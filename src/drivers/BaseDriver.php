<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

use artformdev\edge\models\Settings;
use artformdev\edge\Plugin;
use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use yii\web\Response;

/**
 * Shared driver behavior: cache-control headers and cookie stripping on cacheable
 * responses (the load-bearing safety layer), no-store on everything else.
 */
abstract class BaseDriver extends Component implements CacheDriverInterface
{
    public const HEADER_ORIGIN = 'X-Edge-Origin';

    private ?Client $client = null;

    /**
     * @inheritdoc
     */
    public function shouldServeCached(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function prepareResponse(Response $response, bool $cacheable, array $tags = [], ?string $skipReason = null): void
    {
        $headers = $response->getHeaders();

        // Prove PHP rendered this response (absent when the edge serves a hit).
        $headers->set(self::HEADER_ORIGIN, '1');

        if (!$cacheable) {
            $headers->set('Cache-Control', 'private, no-store');

            // Why a page didn't cache is otherwise invisible. devMode only: skip reasons
            // can name bypass cookies, which shouldn't be advertised in production.
            if ($skipReason !== null && Craft::$app->getConfig()->getGeneral()->devMode) {
                $headers->set('X-Edge-Skip-Reason', $skipReason);
            }

            return;
        }

        // A cacheable response must be cookie-free. Remove Yii-managed cookies (CSRF),
        // any Set-Cookie header already queued, and the native PHP session cookie header.
        $response->getCookies()->removeAll();
        $headers->remove('Set-Cookie');
        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }

        // Never let Vary: Cookie (or Vary: *) fragment or disable the edge cache.
        $headers->remove('Vary');

        // s-maxage governs the shared edge tier; max-age=0 keeps browsers revalidating.
        // A purge can reach the edge but never a visitor's browser, so a long max-age
        // would strand stale HTML on every device that had already seen the page.
        $headers->set('Cache-Control', sprintf(
            'public, s-maxage=%d, max-age=0, must-revalidate',
            $this->getSettings()->cacheControlTtl,
        ));
    }

    /**
     * @inheritdoc
     */
    public function setup(): array
    {
        return ['Nothing to configure for the ' . $this->getHandle() . ' driver. See the shipped nginx config in docs/.'];
    }

    protected function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    /**
     * The Guzzle client (bundled with Craft). Injectable for tests.
     */
    public function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = Craft::createGuzzleClient([
                'timeout' => 30,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        }

        return $this->client;
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

    /**
     * Fetches a URL twice, cookie-free, and returns both responses (used by verify()).
     *
     * @return \Psr\Http\Message\ResponseInterface[]
     */
    protected function fetchTwice(string $url): array
    {
        $client = $this->getClient();

        $first = $client->request('GET', $url, ['headers' => ['Accept' => 'text/html']]);
        $second = $client->request('GET', $url, ['headers' => ['Accept' => 'text/html']]);

        return [$first, $second];
    }
}
