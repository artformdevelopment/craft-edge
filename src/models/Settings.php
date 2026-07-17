<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Edge plugin settings.
 *
 * Every key here can be overridden from `config/edge.php`.
 */
class Settings extends Model
{
    public const DRIVER_NGINX_STATIC = 'nginx-static';
    public const DRIVER_NGINX_FASTCGI = 'nginx-fastcgi';
    public const DRIVER_CLOUDFLARE = 'cloudflare';

    public const QUERY_STRINGS_IGNORE = 'ignore';
    public const QUERY_STRINGS_RESPECT = 'respect';

    /**
     * @var bool Whether edge caching is enabled at all.
     */
    public bool $enabled = true;

    /**
     * @var string The single managed edge tier: 'nginx-static', 'nginx-fastcgi' or 'cloudflare'.
     */
    public string $driver = self::DRIVER_NGINX_STATIC;

    /**
     * @var string[]|null Environments (CRAFT_ENVIRONMENT) in which caching is active.
     * `null` means: cache in any environment, except when devMode is enabled.
     * A non-empty list overrides the devMode skip for the listed environments.
     */
    public ?array $cacheableEnvironments = null;

    /**
     * @var string[] If non-empty, only URIs matching one of these regex patterns are cached.
     */
    public array $includedUriPatterns = [];

    /**
     * @var string[] URIs matching one of these regex patterns are never cached. Always wins.
     */
    public array $excludedUriPatterns = [];

    /**
     * @var string 'ignore' = strip query strings from the cache key (cache as same page);
     * 'respect' = each unique (allowed) query string is a distinct cache entry.
     */
    public string $queryStringCaching = self::QUERY_STRINGS_IGNORE;

    /**
     * @var string[] Query params that never affect the cache key (marketing params).
     * Supports a trailing `*` wildcard.
     */
    public array $excludedQueryStringParams = ['utm_*', 'gclid', 'fbclid', '_ga', 'mc_cid', 'mc_eid'];

    /**
     * @var string[] If non-empty, ONLY these query params affect the cache key
     * (allowlist: everything else is treated like a marketing param). Supports a
     * trailing `*` wildcard. Excluded params always win.
     */
    public array $includedQueryStringParams = [];

    /**
     * @var int[] Site IDs that are never cached (multi-site opt-out).
     */
    public array $excludedSiteIds = [];

    /**
     * @var string[] Cookie names (exact match) or suffixes (matched with str_ends_with) whose
     * presence forces a live, un-shared render at every tier. Empty by default: the shared
     * shell is served to every visitor, logged in or not, and personal content hydrates
     * client-side through the island/CSRF endpoints. List a cookie here only when its
     * presence means the page must never come from the shared copy (e.g. a live cart
     * cookie). Never list the anonymous session/CSRF cookies (CraftSessionId,
     * CRAFT_CSRF_TOKEN); the cache deliberately ignores them.
     */
    public array $bypassCookies = [];

    /**
     * @var string Where the nginx-static driver writes rendered HTML. Outside the web root,
     * so cache files are never addressable by URL; nginx serves them through an internal
     * location (see docs/nginx-static.conf). Supports @aliases and $ENV_VAR references.
     */
    public string $cachePath = '@storage/edge-cache';

    /**
     * @var string|null Base URL of the ngx_cache_purge location for the nginx-fastcgi driver,
     * e.g. "http://127.0.0.1/edge-purge". The URI to purge is appended.
     */
    public ?string $fastCgiPurgeUrl = null;

    /**
     * @var string|null Cloudflare API token (use App::env('CLOUDFLARE_API_TOKEN') in config/edge.php).
     */
    public ?string $cloudflareApiToken = null;

    /**
     * @var string|null Cloudflare zone ID (use App::env('CLOUDFLARE_ZONE_ID') in config/edge.php).
     */
    public ?string $cloudflareZoneId = null;

    /**
     * @var bool Purge by Cache-Tag and emit Cache-Tag headers (Cloudflare Enterprise only).
     */
    public bool $cloudflareUsesCacheTags = false;

    /**
     * @var int Max URLs per Cloudflare purge request (API limit is 30 on all plans).
     */
    public int $cloudflarePurgeChunkSize = 30;

    /**
     * @var int max-age for `Cache-Control: public, max-age=...` on cacheable responses.
     * Long by default: invalidation is edge-purge-driven, not TTL-driven.
     */
    public int $cacheControlTtl = 31536000;

    /**
     * @var bool Re-warm purged URLs automatically after a purge.
     */
    public bool $warmCacheAutomatically = true;

    /**
     * @var int Concurrent requests used by the cache warmer.
     */
    public int $concurrency = 5;

    /**
     * @var bool Automatically register the hydration script on cacheable pages.
     */
    public bool $autoInjectHydrationScript = true;

    /**
     * @var bool Whether the uncached `edge/csrf` endpoint is enabled.
     */
    public bool $csrfEndpointEnabled = true;

    /**
     * @var string Site template path prefix that `edge/island?name=x` renders from.
     */
    public string $islandsTemplatePath = '_edge/islands';

    /**
     * Parsed Cloudflare API token (supports $ENV_VAR style references).
     */
    public function getParsedCloudflareApiToken(): ?string
    {
        return App::parseEnv($this->cloudflareApiToken) ?: null;
    }

    /**
     * Parsed Cloudflare zone ID (supports $ENV_VAR style references).
     */
    public function getParsedCloudflareZoneId(): ?string
    {
        return App::parseEnv($this->cloudflareZoneId) ?: null;
    }

    /**
     * @inheritdoc
     *
     * Normalizes CP form input (comma-separated strings, or editableTable row arrays
     * like [['value' => 'x'], ['x']]) into the array-typed settings.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        $listKeys = [
            'bypassCookies', 'excludedUriPatterns', 'includedUriPatterns',
            'excludedQueryStringParams', 'includedQueryStringParams',
            'cacheableEnvironments', 'excludedSiteIds',
        ];

        foreach ($listKeys as $listKey) {
            if (!isset($values[$listKey])) {
                continue;
            }
            if (is_string($values[$listKey])) {
                $values[$listKey] = explode(',', $values[$listKey]);
            }
            if (is_array($values[$listKey])) {
                $values[$listKey] = self::normalizeList($values[$listKey]);
            }
        }

        if (isset($values['excludedSiteIds']) && is_array($values['excludedSiteIds'])) {
            $values['excludedSiteIds'] = array_map('intval', $values['excludedSiteIds']);
        }

        foreach (['cacheControlTtl', 'concurrency', 'cloudflarePurgeChunkSize'] as $intKey) {
            if (isset($values[$intKey]) && is_string($values[$intKey]) && is_numeric($values[$intKey])) {
                $values[$intKey] = (int)$values[$intKey];
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * Flattens editableTable rows / trims strings / drops blanks.
     *
     * @return string[]
     */
    private static function normalizeList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = reset($value);
            }
            if (is_int($value) || is_float($value)) {
                $value = (string)$value;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    $normalized[] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['driver'], 'in', 'range' => [
                self::DRIVER_NGINX_STATIC,
                self::DRIVER_NGINX_FASTCGI,
                self::DRIVER_CLOUDFLARE,
            ]],
            [['queryStringCaching'], 'in', 'range' => [
                self::QUERY_STRINGS_IGNORE,
                self::QUERY_STRINGS_RESPECT,
            ]],
            [['cacheControlTtl', 'concurrency', 'cloudflarePurgeChunkSize'], 'integer', 'min' => 1],
            [['cachePath'], 'required'],
        ];
    }
}
