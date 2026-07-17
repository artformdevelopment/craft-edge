<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\jobs;

use artformdev\edge\models\SiteUri;
use artformdev\edge\Plugin;
use Craft;
use craft\queue\BaseJob;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

/**
 * Re-requests purged URLs through the edge so anonymous visitors get instant HITs again.
 * Requests are sent cookie-free (never with identity cookies), so the edge actually
 * stores the result.
 */
class WarmJob extends BaseJob
{
    /**
     * @param array<int, array{siteId: int|string, uri: string}> $siteUris
     */
    public function __construct(
        public array $siteUris = [],
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $settings = Plugin::getInstance()->getSettings();

        $urls = [];
        foreach ($this->siteUris as $config) {
            try {
                $urls[] = SiteUri::fromArray($config)->getUrl();
            } catch (\Throwable) {
                // Site may have been deleted since the purge.
            }
        }

        if (empty($urls)) {
            return;
        }

        $client = Craft::createGuzzleClient([
            'timeout' => 60,
            'http_errors' => false,
            'allow_redirects' => false,
            // No cookie jar: warmer requests must be anonymous.
            'cookies' => false,
        ]);

        $total = count($urls);
        $done = 0;

        $requests = static function() use ($urls) {
            foreach ($urls as $url) {
                yield new Request('GET', $url, [
                    'Accept' => 'text/html',
                    'X-Edge-Warm' => '1',
                ]);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => max(1, $settings->concurrency),
            'fulfilled' => function() use (&$done, $total, $queue) {
                $done++;
                $this->setProgress($queue, $done / $total);
            },
            'rejected' => function($reason, int $index) use (&$done, $total, $queue, $urls) {
                $done++;
                $this->setProgress($queue, $done / $total);
                Craft::warning('Edge warm request failed for ' . ($urls[$index] ?? '?') . ': ' . $reason, __METHOD__);
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return 'Warming ' . count($this->siteUris) . ' edge cache URLs';
    }
}
