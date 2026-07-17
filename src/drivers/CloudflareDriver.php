<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

use artformdev\edge\models\Settings;
use artformdev\edge\models\SiteUri;
use craft\helpers\Json;
use Psr\Http\Message\ResponseInterface;
use yii\web\Response;

/**
 * Cloudflare caches on the origin's `Cache-Control: public, max-age=N`; there is no local
 * storage. Purging goes through the zone purge API (by URL on all plans, by Cache-Tag when
 * cloudflareUsesCacheTags is enabled, Enterprise only).
 */
class CloudflareDriver extends BaseDriver
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * @inheritdoc
     */
    public function getHandle(): string
    {
        return Settings::DRIVER_CLOUDFLARE;
    }

    /**
     * @inheritdoc
     */
    public function prepareResponse(Response $response, bool $cacheable, array $tags = []): void
    {
        parent::prepareResponse($response, $cacheable, $tags);

        if ($cacheable && $this->getSettings()->cloudflareUsesCacheTags && !empty($tags)) {
            $sanitized = array_map([CloudflareRulesBuilder::class, 'sanitizeTag'], $tags);
            $response->getHeaders()->set('Cache-Tag', implode(',', $sanitized));
        }
    }

    /**
     * @inheritdoc
     */
    public function store(SiteUri $siteUri, string $html): bool
    {
        // Cloudflare stores the response at its own edge as it passes through.
        return true;
    }

    /**
     * @inheritdoc
     */
    public function purge(array $siteUris): void
    {
        if (empty($siteUris)) {
            return;
        }

        $this->purgeUrls(array_map(fn(SiteUri $siteUri) => $siteUri->getUrl(), $siteUris));
    }

    /**
     * Purges absolute URLs (`files` payload, <=30 per request on all plans; callers batch).
     *
     * @param string[] $urls
     */
    public function purgeUrls(array $urls): void
    {
        if (empty($urls)) {
            return;
        }

        $this->purgePayload(['files' => array_values($urls)]);
    }

    /**
     * Purges by Cache-Tag (Enterprise only, cloudflareUsesCacheTags).
     *
     * @param string[] $tags Craft cache tags (sanitized here)
     */
    public function purgeTags(array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        $this->purgePayload(['tags' => array_map([CloudflareRulesBuilder::class, 'sanitizeTag'], $tags)]);
    }

    /**
     * @inheritdoc
     */
    public function flushAll(): void
    {
        $this->purgePayload(['purge_everything' => true]);
    }

    /**
     * @inheritdoc
     */
    public function setup(): array
    {
        $settings = $this->getSettings();
        [$token, $zoneId] = $this->requireCredentials();

        $entrypoint = self::API_BASE . "/zones/$zoneId/rulesets/phases/http_request_cache_settings/entrypoint";

        // Read the existing entrypoint so other rules are preserved (idempotent update).
        $existing = [];
        $getResponse = $this->getClient()->request('GET', $entrypoint, ['headers' => $this->authHeaders($token)]);
        if ($getResponse->getStatusCode() === 200) {
            $body = Json::decode((string)$getResponse->getBody());
            $existing = $body['result']['rules'] ?? [];
        } elseif ($getResponse->getStatusCode() !== 404) {
            $this->throwForResponse($getResponse, 'reading the cache-rules entrypoint');
        }

        $rules = CloudflareRulesBuilder::mergeIntoExisting($existing, $settings);

        $putResponse = $this->getClient()->request('PUT', $entrypoint, [
            'headers' => $this->authHeaders($token),
            'json' => ['rules' => $rules],
        ]);

        if ($putResponse->getStatusCode() !== 200) {
            $this->throwForResponse($putResponse, 'updating the cache-rules entrypoint');
        }

        $lines = ['Cache rules updated (' . count($rules) . ' rules in the http_request_cache_settings phase):'];
        $n = 1;
        if (($bypassExpression = CloudflareRulesBuilder::buildBypassExpression($settings)) !== null) {
            $lines[] = $n++ . '. ' . CloudflareRulesBuilder::BYPASS_DESCRIPTION . ': ' . $bypassExpression;
        }
        $lines[] = $n . '. ' . CloudflareRulesBuilder::CACHE_DESCRIPTION . ': cache on, edge_ttl respect_origin, marketing params excluded from the cache key';

        return $lines;
    }

    /**
     * @inheritdoc
     */
    public function verify(string $url): VerifyResult
    {
        $lines = [];
        [$first, $second] = $this->fetchTwice($url);

        $firstStatus = $first->getHeaderLine('CF-Cache-Status');
        $secondStatus = $second->getHeaderLine('CF-Cache-Status');
        $setCookie = $second->getHeader('Set-Cookie');

        $lines[] = 'GET #1: HTTP ' . $first->getStatusCode() . ' CF-Cache-Status: ' . ($firstStatus ?: '(missing: is the URL proxied through Cloudflare?)');
        $lines[] = 'GET #2: HTTP ' . $second->getStatusCode() . ' CF-Cache-Status: ' . ($secondStatus ?: '(missing)');
        $lines[] = 'Set-Cookie on cached response: ' . (empty($setCookie) ? 'none (correct)' : 'PRESENT, LEAK: ' . implode('; ', $setCookie));

        if ($second->hasHeader(self::HEADER_ORIGIN) && $secondStatus === '') {
            $lines[] = 'The response came from the origin with no CF-Cache-Status. Check that nginx is pass-through and the zone proxies this host.';
        }

        $ok = $second->getStatusCode() === 200 && $secondStatus === 'HIT' && empty($setCookie);

        return new VerifyResult($ok, $lines);
    }

    /**
     * POSTs a purge payload, translating Cloudflare failures into retryable/permanent errors.
     *
     * @param array<string, mixed> $payload
     */
    private function purgePayload(array $payload): void
    {
        [$token, $zoneId] = $this->requireCredentials();

        try {
            $response = $this->getClient()->request('POST', self::API_BASE . "/zones/$zoneId/purge_cache", [
                'headers' => $this->authHeaders($token),
                'json' => $payload,
            ]);
        } catch (\Throwable $e) {
            throw new RetryableDriverException("Cloudflare purge request failed: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 429 || $status >= 500) {
            throw new RetryableDriverException("Cloudflare purge returned HTTP $status; will retry.");
        }

        if ($status !== 200) {
            $this->throwForResponse($response, 'purging');
        }
    }

    /**
     * @return array{0: string, 1: string} [token, zoneId]
     * @throws DriverException when credentials are missing
     */
    private function requireCredentials(): array
    {
        $settings = $this->getSettings();
        $token = $settings->getParsedCloudflareApiToken();
        $zoneId = $settings->getParsedCloudflareZoneId();

        if (!$token || !$zoneId) {
            throw new DriverException(
                'Cloudflare is not configured: set the CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID ' .
                'environment variables (referenced from config/edge.php).'
            );
        }

        return [$token, $zoneId];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json',
        ];
    }

    private function throwForResponse(ResponseInterface $response, string $doing): never
    {
        $detail = '';
        try {
            $body = Json::decode((string)$response->getBody());
            $errors = array_map(
                fn(array $error) => ($error['code'] ?? '?') . ': ' . ($error['message'] ?? '?'),
                $body['errors'] ?? [],
            );
            $detail = implode('; ', $errors);
        } catch (\Throwable) {
            // Non-JSON error body.
        }

        throw new DriverException(
            "Cloudflare API error while $doing (HTTP {$response->getStatusCode()})" . ($detail ? ": $detail" : '.')
        );
    }
}
