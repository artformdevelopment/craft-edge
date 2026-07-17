<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\services;

use artformdev\edge\models\Decision;
use artformdev\edge\models\RequestContext;
use artformdev\edge\models\Settings;
use artformdev\edge\models\SiteUri;
use craft\base\Component;

/**
 * The single cacheability decision service. All logic is pure: it operates on a
 * RequestContext snapshot and the Settings model, so every branch is unit-testable
 * without a Craft app or database.
 *
 * The cookie model (signed off):
 * - Anonymous cookies (CraftSessionId, CRAFT_CSRF_TOKEN, PHPSESSID) are IGNORED: they are
 *   never part of the cache key, never a bypass trigger, never a Vary.
 * - The configured bypassCookies (none by default) force a live, un-shared render: list a
 *   cookie only when its presence means the page must never come from the shared copy
 *   (e.g. a live cart cookie).
 * - A logged-in render is never STORED (the logged-in skip below); the edge tier serves
 *   logged-in visitors the same shared anonymous file as everyone else, and their personal
 *   content hydrates client-side through the island/CSRF endpoints.
 * - Cacheable responses are stripped of Set-Cookie before being stored (see ResponseGuard).
 */
class Cacheability extends Component
{
    /**
     * Evaluates whether the request may be served from / stored in the edge cache.
     */
    public function evaluateRequest(RequestContext $ctx, Settings $settings): Decision
    {
        if (!$settings->enabled) {
            return Decision::skip('caching disabled');
        }

        if ($ctx->isConsoleRequest || !$ctx->isSiteRequest) {
            return Decision::skip('not a site request');
        }

        if ($ctx->isCpRequest) {
            return Decision::skip('CP request');
        }

        if (strtoupper($ctx->method) !== 'GET' && strtoupper($ctx->method) !== 'HEAD') {
            return Decision::skip('non-GET request');
        }

        if ($ctx->isActionRequest) {
            return Decision::skip('action request');
        }

        if ($ctx->isPreview) {
            return Decision::skip('preview request');
        }

        if ($ctx->hasToken) {
            return Decision::skip('token request');
        }

        if ($ctx->isLoggedIn) {
            return Decision::skip('logged-in user');
        }

        if ($settings->cacheableEnvironments === null) {
            if ($ctx->devMode) {
                return Decision::skip('devMode enabled');
            }
        } elseif (!in_array($ctx->environment, $settings->cacheableEnvironments, true)) {
            return Decision::skip('environment not cacheable');
        }

        if (($cookie = $this->getBypassCookie($ctx, $settings)) !== null) {
            return Decision::skip("bypass cookie: $cookie");
        }

        if (in_array($ctx->siteId, array_map('intval', $settings->excludedSiteIds), true)) {
            return Decision::skip('excluded site');
        }

        // A `no-cache` request param always skips the cache (a debugging aid).
        if (!empty($ctx->queryParams['no-cache'])) {
            return Decision::skip('no-cache param');
        }

        $uri = $this->normalizeUriPath($ctx->uri);

        // The plugin's own dynamic endpoints are never cacheable.
        if ($uri === 'edge' || str_starts_with($uri, 'edge/')) {
            return Decision::skip('edge endpoint');
        }

        if ($this->matchesAnyPattern($uri, $settings->excludedUriPatterns)) {
            return Decision::skip('excluded URI pattern');
        }

        if (!empty($settings->includedUriPatterns)
            && !$this->matchesAnyPattern($uri, $settings->includedUriPatterns)
        ) {
            return Decision::skip('not an included URI pattern');
        }

        return Decision::cache();
    }

    /**
     * Returns the name of the first configured bypass cookie present on the request,
     * or null. Anonymous session/CSRF cookies never bypass, even if misconfigured
     * into the bypass list.
     */
    public function getBypassCookie(RequestContext $ctx, Settings $settings): ?string
    {
        foreach (array_keys($ctx->cookies) as $name) {
            if (in_array($name, $ctx->anonymousCookieNames, true)) {
                continue;
            }

            foreach ($settings->bypassCookies as $needle) {
                if ($needle !== '' && ($name === $needle || str_ends_with($name, $needle))) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Builds the cache key for the request: site ID + URI with query-string handling applied.
     * Cookies NEVER affect the key.
     */
    public function getCacheSiteUri(RequestContext $ctx, Settings $settings): SiteUri
    {
        $uri = $this->normalizeUriPath($ctx->uri);
        $query = $this->getAllowedQueryString($ctx->queryParams, $settings);

        if ($query !== '') {
            $uri .= '?' . $query;
        }

        return new SiteUri($ctx->siteId, $uri);
    }

    /**
     * Applies queryStringCaching + excludedQueryStringParams and returns the canonical
     * query string for the cache key ('' in ignore mode or when nothing remains).
     */
    public function getAllowedQueryString(array $queryParams, Settings $settings): string
    {
        if ($settings->queryStringCaching === Settings::QUERY_STRINGS_IGNORE) {
            return '';
        }

        $allowed = [];
        foreach ($queryParams as $name => $value) {
            if (!$this->isExcludedQueryParam((string)$name, $settings)) {
                $allowed[(string)$name] = $value;
            }
        }

        ksort($allowed);

        return http_build_query($allowed);
    }

    /**
     * Whether the query param never affects the cache key: Craft internals, the
     * excluded list (utm_* etc), and (when an includedQueryStringParams allowlist is
     * set) anything not on it. Exclusions always win.
     */
    public function isExcludedQueryParam(string $name, Settings $settings): bool
    {
        // Craft internals are never part of the key.
        if (in_array($name, ['p', 'token'], true)) {
            return true;
        }

        if (self::matchesParamList($name, $settings->excludedQueryStringParams)) {
            return true;
        }

        if (!empty($settings->includedQueryStringParams)) {
            return !self::matchesParamList($name, $settings->includedQueryStringParams);
        }

        return false;
    }

    /**
     * Exact-name or trailing-* wildcard param matching.
     *
     * @param string[] $patterns
     */
    private static function matchesParamList(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($name, rtrim($pattern, '*'))) {
                    return true;
                }
            } elseif ($name === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Response-side hard rules: only a 200, non-redirect response may be stored.
     */
    public function evaluateResponse(int $statusCode, bool $hasSetCookie): Decision
    {
        if ($statusCode !== 200) {
            return Decision::skip("non-200 response ($statusCode)");
        }

        if ($hasSetCookie) {
            return Decision::skip('response still carries Set-Cookie');
        }

        return Decision::cache();
    }

    /**
     * Strips the query string and slashes from a request URI path.
     */
    private function normalizeUriPath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '';

        return trim($path, '/');
    }

    /**
     * Whether the URI matches any of the given regex patterns (special patterns:
     * '' matches the homepage, '*' matches everything).
     */
    public function matchesAnyPattern(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }
            if ($pattern === '') {
                $pattern = '^$';
            } elseif ($pattern === '*') {
                $pattern = '.*';
            }
            $pattern = str_replace(['\/', '/'], ['/', '\/'], trim($pattern, '/'));
            if (@preg_match('/' . $pattern . '/', trim($uri, '/')) === 1) {
                return true;
            }
        }

        return false;
    }
}
