<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\drivers;

use artformdev\edge\models\Settings;
use artformdev\edge\models\SiteUri;
use Craft;
use craft\helpers\FileHelper;

/**
 * Writes rendered HTML to disk; nginx serves it via `try_files` before PHP ever runs
 * (see docs/nginx-static.conf). Purge = delete the file(s).
 */
class NginxStaticDriver extends BaseDriver
{
    /**
     * @inheritdoc
     */
    public function getHandle(): string
    {
        return Settings::DRIVER_NGINX_STATIC;
    }

    /**
     * @inheritdoc
     */
    public function store(SiteUri $siteUri, string $html): bool
    {
        $paths = $this->getFilePaths($siteUri);

        if (empty($paths)) {
            return false;
        }

        foreach ($paths as $path) {
            try {
                FileHelper::writeToFile($path, $html);
            } catch (\Throwable $e) {
                // A cache failure must never break the page.
                Craft::warning("Edge could not write cache file $path: {$e->getMessage()}", __METHOD__);

                return false;
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function purge(array $siteUris): void
    {
        foreach ($siteUris as $siteUri) {
            foreach ($this->getFilePaths($siteUri) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function flushAll(): void
    {
        $cachePath = $this->getCachePath();

        if ($cachePath !== null && is_dir($cachePath)) {
            try {
                FileHelper::clearDirectory($cachePath);
            } catch (\Throwable $e) {
                Craft::warning("Edge could not clear $cachePath: {$e->getMessage()}", __METHOD__);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function verify(string $url): VerifyResult
    {
        $lines = [];
        [$first, $second] = $this->fetchTwice($url);

        $firstOrigin = $first->hasHeader(self::HEADER_ORIGIN);
        $secondOrigin = $second->hasHeader(self::HEADER_ORIGIN);
        $setCookie = $second->getHeader('Set-Cookie');
        $cacheControl = $second->getHeaderLine('Cache-Control');

        $lines[] = 'GET #1: HTTP ' . $first->getStatusCode() . ($firstOrigin ? ' (rendered by PHP, MISS)' : ' (served by nginx, already cached)');
        $lines[] = 'GET #2: HTTP ' . $second->getStatusCode() . ($secondOrigin ? ' (rendered by PHP, NOT served from the static cache!)' : ' (served by nginx static file, HIT)');
        $lines[] = 'Set-Cookie on cached response: ' . (empty($setCookie) ? 'none (correct)' : 'PRESENT, LEAK: ' . implode('; ', $setCookie));
        $lines[] = 'Cache-Control: ' . ($cacheControl !== ''
            ? $cacheControl
            : '(none: normal for a static-file hit; nginx serves the raw file)');

        $ok = $second->getStatusCode() === 200 && !$secondOrigin && empty($setCookie);

        if ($secondOrigin) {
            $lines[] = 'Check that the nginx try_files config from docs/nginx-static.conf is in place and the cachePath matches.';
        }

        return new VerifyResult($ok, $lines);
    }

    /**
     * The resolved cache path root, or null if unset.
     */
    public function getCachePath(): ?string
    {
        // Supports $ENV_VAR references and @aliases, per Craft convention.
        $path = \craft\helpers\App::parseEnv($this->getSettings()->cachePath);

        if (!is_string($path) || $path === '') {
            return null;
        }

        return FileHelper::normalizePath($path);
    }

    /**
     * The file path(s) for a site URI: cachePath/host[/basePath]/uri[/query]/index.html.
     * A second, rawurldecoded variant is included when it differs. Traversal-safe.
     *
     * @return string[]
     */
    public function getFilePaths(SiteUri $siteUri): array
    {
        $cachePath = $this->getCachePath();
        $hostPath = $siteUri->getHostPath();

        if ($cachePath === null || $hostPath === null) {
            return [];
        }

        $sitePath = FileHelper::normalizePath($cachePath . '/' . $hostPath);

        return self::buildFilePaths($sitePath, $siteUri->uri);
    }

    /**
     * Pure path construction (unit-tested): query strings become a path segment, matching
     * nginx's `$uri/$args` try_files pattern; traversal outside the site path is rejected.
     *
     * @return string[]
     */
    public static function buildFilePaths(string $sitePath, string $uri): array
    {
        $paths = [];
        $homePath = FileHelper::normalizePath($sitePath . '/index.html');

        $isContained = static function(string $candidateUri) use ($sitePath, $homePath): bool {
            $filePath = FileHelper::normalizePath($sitePath . '/' . str_replace('?', '/', $candidateUri) . '/index.html');

            return str_starts_with($filePath, $sitePath . DIRECTORY_SEPARATOR) || $filePath === $homePath;
        };

        // If the URI escapes the site path in either its raw or decoded form, refuse it
        // entirely; never write a cache file for a traversal-shaped request.
        if (!$isContained($uri) || !$isContained(rawurldecode($uri))) {
            return [];
        }

        foreach (array_unique([$uri, rawurldecode($uri)]) as $variant) {
            $paths[] = FileHelper::normalizePath($sitePath . '/' . str_replace('?', '/', $variant) . '/index.html');
        }

        return array_unique($paths);
    }
}
