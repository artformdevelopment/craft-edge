<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

use artformdev\edge\models\Settings;
use artformdev\edge\models\SiteUri;
use Craft;
use craft\helpers\App;

/**
 * nginx `fastcgi_cache` stores the PHP responses itself (see docs/nginx-fastcgi.conf);
 * storing is therefore a no-op here. Purging requires the ngx_cache_purge module; the
 * driver issues requests to the configured purge location.
 */
class NginxFastCgiDriver extends BaseDriver
{
    /**
     * @inheritdoc
     */
    public function getHandle(): string
    {
        return Settings::DRIVER_NGINX_FASTCGI;
    }

    /**
     * @inheritdoc
     */
    public function store(SiteUri $siteUri, string $html): bool
    {
        // nginx stores the response as it passes through fastcgi_cache.
        return true;
    }

    /**
     * @inheritdoc
     */
    public function purge(array $siteUris): void
    {
        $purgeUrl = $this->getPurgeUrl();

        foreach ($siteUris as $siteUri) {
            $uri = '/' . ltrim($siteUri->uri, '/');
            // The purge location keys on the original host + URI (without any cache-key query
            // handling; the nginx map already normalizes that).
            $url = rtrim($purgeUrl, '/') . $uri;

            $host = parse_url($siteUri->getUrl(), PHP_URL_HOST);

            try {
                $response = $this->getClient()->request('GET', $url, [
                    'headers' => $host ? ['Host' => $host] : [],
                ]);
            } catch (\Throwable $e) {
                throw new RetryableDriverException("Purge request to $url failed: {$e->getMessage()}", 0, $e);
            }

            $status = $response->getStatusCode();

            // A purge request must terminate at nginx. If the origin marker is present,
            // the purge location doesn't exist and the request fell through to PHP.
            if ($response->hasHeader(BaseDriver::HEADER_ORIGIN)) {
                throw new DriverException(
                    "Purge request to $url was routed to PHP instead of an ngx_cache_purge location. " .
                    'The nginx-fastcgi driver requires the ngx_cache_purge module and the edge-purge ' .
                    'location from docs/nginx-fastcgi.conf; check fastCgiPurgeUrl and the nginx config.'
                );
            }

            // 200 = purged, 404 = not in cache (already purged), both fine.
            if ($status !== 200 && $status !== 404) {
                throw new DriverException(
                    "Purge request to $url returned HTTP $status. The nginx-fastcgi driver requires " .
                    'the ngx_cache_purge module and the edge-purge location from docs/nginx-fastcgi.conf. ' .
                    'Verify the module is compiled/loaded and fastCgiPurgeUrl points at the purge location.'
                );
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function flushAll(): void
    {
        // ngx_cache_purge supports wildcard purging when built with it; try `/*`.
        try {
            $this->purge([new SiteUri(Craft::$app->getSites()->getPrimarySite()->id, '*')]);
        } catch (\Throwable $e) {
            Craft::warning(
                'Edge could not wildcard-purge the fastcgi cache (ngx_cache_purge may be built without ' .
                'wildcard support). Clear the fastcgi_cache_path directory manually or reload nginx. ' .
                $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function verify(string $url): VerifyResult
    {
        $lines = [];
        [$first, $second] = $this->fetchTwice($url);

        $firstStatus = $first->getHeaderLine('X-Edge-Cache');
        $secondStatus = $second->getHeaderLine('X-Edge-Cache');
        $setCookie = $second->getHeader('Set-Cookie');
        $cacheControl = $second->getHeaderLine('Cache-Control');

        $lines[] = 'GET #1: HTTP ' . $first->getStatusCode() . ' X-Edge-Cache: ' . ($firstStatus ?: '(missing: is the docs/nginx-fastcgi.conf config in place?)');
        $lines[] = 'GET #2: HTTP ' . $second->getStatusCode() . ' X-Edge-Cache: ' . ($secondStatus ?: '(missing)');
        $lines[] = 'Set-Cookie on cached response: ' . (empty($setCookie) ? 'none (correct)' : 'PRESENT, LEAK: ' . implode('; ', $setCookie));
        $lines[] = "Cache-Control: $cacheControl";

        $ok = $second->getStatusCode() === 200 && $secondStatus === 'HIT' && empty($setCookie);

        return new VerifyResult($ok, $lines);
    }

    /**
     * @throws DriverException when fastCgiPurgeUrl is not configured
     */
    private function getPurgeUrl(): string
    {
        $url = App::parseEnv($this->getSettings()->fastCgiPurgeUrl);

        if (!$url) {
            throw new DriverException(
                'The nginx-fastcgi driver needs the fastCgiPurgeUrl setting (e.g. "http://127.0.0.1/edge-purge") ' .
                'pointing at an ngx_cache_purge location. See docs/nginx-fastcgi.conf.'
            );
        }

        return $url;
    }
}
