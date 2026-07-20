<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

use artformdev\edge\models\SiteUri;
use yii\web\Response;

/**
 * A single managed edge tier. Exactly one driver is active at a time.
 */
interface CacheDriverInterface
{
    /**
     * The driver handle ('nginx-static' | 'nginx-fastcgi' | 'cloudflare').
     */
    public function getHandle(): string;

    /**
     * Whether the origin should attempt to serve from the driver's store itself.
     * All shipped drivers return false: the edge tier (nginx/Cloudflare) serves
     * cached responses before PHP is ever reached.
     */
    public function shouldServeCached(): bool;

    /**
     * Sets driver-specific headers on a response the origin is about to emit.
     * $cacheable=true -> the cookie-free cacheable headers; false -> private, no-store.
     *
     * @param string[] $tags Cache tags recorded for the page (used for Cache-Tag headers).
     */
    public function prepareResponse(Response $response, bool $cacheable, array $tags = [], ?string $skipReason = null): void;

    /**
     * Stores the rendered, cookie-free HTML for a URI. Returns false on failure
     * (a cache failure must never break the page: callers log and continue).
     */
    public function store(SiteUri $siteUri, string $html): bool;

    /**
     * Purges the given URIs from the edge tier.
     *
     * @param SiteUri[] $siteUris
     * @throws RetryableDriverException when the purge should be retried (429/5xx)
     * @throws DriverException when the purge failed permanently (misconfiguration)
     */
    public function purge(array $siteUris): void;

    /**
     * Purges everything this driver manages.
     */
    public function flushAll(): void;

    /**
     * One-time, opt-in edge configuration (Cloudflare: create/update cache rules;
     * nginx: nothing to mutate). Returns human-readable result lines.
     *
     * @return string[]
     */
    public function setup(): array;

    /**
     * Verifies the edge tier is working for a URL: 2nd request must be a HIT and no
     * Set-Cookie may ride a cached response.
     */
    public function verify(string $url): VerifyResult;
}
