<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\models;

use Craft;
use craft\helpers\UrlHelper;

/**
 * A site-scoped cache key: site ID + normalized URI ('' = homepage, may contain '?query').
 */
final class SiteUri
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $uri,
    ) {
    }

    /**
     * The absolute URL for this site URI.
     */
    public function getUrl(): string
    {
        return UrlHelper::siteUrl($this->uri, null, null, $this->siteId);
    }

    /**
     * The site's base URL host path used for file storage (host + optional base path),
     * e.g. "example.com" or "example.com/subdir".
     */
    public function getHostPath(): ?string
    {
        return self::hostPathForSite($this->siteId);
    }

    /**
     * The host plus any base path for a site's base URL, e.g. "example.com" or
     * "example.com/subdir". Null when the site has no absolute base URL.
     */
    public static function hostPathForSite(int $siteId): ?string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId, true);
        if ($site === null) {
            return null;
        }

        $baseUrl = Craft::getAlias($site->getBaseUrl() ?? '');
        if (!$baseUrl) {
            return null;
        }

        $hostPath = preg_replace('/^https?:\/\//i', '', $baseUrl);

        // Strip any :port so the path matches nginx's $host, which never includes one.
        return preg_replace('/:\d+/', '', $hostPath);
    }

    /**
     * Just the hostname for a site: no base path, no port. Used to compare against the
     * request Host. Null when the site has no absolute base URL.
     */
    public static function hostForSite(int $siteId): ?string
    {
        $hostPath = self::hostPathForSite($siteId);

        return $hostPath === null ? null : (explode('/', $hostPath)[0] ?: null);
    }

    /**
     * @return array{siteId: int, uri: string}
     */
    public function toArray(): array
    {
        return ['siteId' => $this->siteId, 'uri' => $this->uri];
    }

    /**
     * @param array{siteId: int|string, uri: string} $config
     */
    public static function fromArray(array $config): self
    {
        return new self((int)$config['siteId'], (string)$config['uri']);
    }
}
