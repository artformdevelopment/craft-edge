<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\jobs;

use artformdev\edge\models\SiteUri;
use artformdev\edge\Plugin;
use artformdev\edge\services\Warmer;
use Craft;
use craft\queue\BaseJob;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use yii\queue\RetryableJobInterface;

/** Executes bounded anonymous fetches; old URI payloads enter the shared work list. */
class WarmJob extends BaseJob implements RetryableJobInterface
{
    public array $requestIds = [];

    public function __construct(public array $siteUris = [], array $config = [], array $requestIds = [])
    {
        $this->requestIds = $requestIds;
        parent::__construct($config);
    }

    public function getTtr(): int
    {
        return Warmer::TTR;
    }

    public function canRetry(mixed $attempt, mixed $error): bool
    {
        return $attempt < 3 && !$error instanceof WarmRequestException;
    }

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $warmer = $plugin->warmer;
        if ($this->siteUris !== []) {
            $warmer->enqueue($this->siteUris, 5);
            return;
        }
        $concurrency = max(1, $plugin->getSettings()->concurrency);
        $ids = array_slice($this->requestIds, 0, Warmer::batchSize($concurrency));
        $remaining = array_slice($this->requestIds, count($ids));
        if ($remaining !== []) {
            $warmer->dispatch($remaining);
        }
        /** @var array<int, array> $claims */
        $claims = [];
        $errors = [];
        $done = 0;
        $finish = function(int $id, ?string $error) use ($warmer, &$claims, &$errors, &$done, $ids, $queue): void {
            $warmer->complete($claims[$id], $error);
            unset($claims[$id]);
            if ($error !== null) {
                $errors[] = "request $id: $error";
                Craft::warning("Edge warm request $id failed: $error", __METHOD__);
            }
            $this->setProgress($queue, ++$done / count($ids));
        };
        $requests = function() use ($ids, $warmer, &$claims, $finish): \Generator {
            foreach ($ids as $id) {
                $claim = $warmer->claim((int)$id);
                if ($claim === null) {
                    continue;
                }
                $claims[$id] = $claim;
                try {
                    $request = new Request('GET', SiteUri::fromArray($claim)->getUrl(), ['Accept' => 'text/html', 'X-Edge-Warm' => '1']);
                } catch (\Throwable $e) {
                    $finish((int)$id, $e->getMessage());
                    continue;
                }
                yield $id => $request;
            }
        };
        $client = Craft::createGuzzleClient([
            'timeout' => 60, 'connect_timeout' => 10, 'http_errors' => false,
            'allow_redirects' => false, 'cookies' => false,
        ]);
        try {
            (new Pool($client, $requests(), [
                'concurrency' => $concurrency,
                'fulfilled' => function(ResponseInterface $response, int $id) use ($finish): void {
                    $status = $response->getStatusCode();
                    $response->getBody()->close();
                    $finish($id, $status === 200 ? null : "HTTP $status");
                },
                'rejected' => function($reason, int $id) use ($finish): void {
                    $finish($id, (string)$reason);
                },
            ]))->promise()->wait();
        } finally {
            foreach ($claims as $claim) {
                $warmer->complete($claim, 'Warming execution interrupted; retry the job.');
            }
        }
        if ($errors !== []) {
            throw new WarmRequestException(implode('; ', $errors));
        }
    }

    protected function defaultDescription(): string
    {
        return 'Warming ' . count($this->siteUris ?: $this->requestIds) . ' edge cache URLs';
    }
}
