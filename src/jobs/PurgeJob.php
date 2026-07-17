<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\jobs;

use artformdev\edge\drivers\CloudflareDriver;
use artformdev\edge\drivers\RetryableDriverException;
use artformdev\edge\models\SiteUri;
use artformdev\edge\Plugin;
use Craft;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseJob;

/**
 * Purges a batch of URIs (or tags, or everything) through the active driver.
 * Transient failures (429/5xx/network) are retried with exponential backoff by
 * re-dispatching a delayed copy: a purge is never silently dropped, and a failed
 * purge never breaks the content save that triggered it.
 */
class PurgeJob extends BaseJob
{
    public const MAX_ATTEMPTS = 5;

    /**
     * @param array<int, array{siteId: int|string, uri: string}> $siteUris
     * @param string[] $tags Cloudflare Cache-Tag payload (tag mode only)
     */
    public function __construct(
        public array $siteUris = [],
        public array $tags = [],
        public bool $purgeAll = false,
        public int $attempt = 1,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $driver = Plugin::getInstance()->getDriver();

        try {
            if ($this->purgeAll) {
                $driver->flushAll();
            } elseif (!empty($this->tags) && $driver instanceof CloudflareDriver) {
                $driver->purgeTags($this->tags);
            } elseif (!empty($this->siteUris)) {
                $driver->purge(array_map(
                    fn(array $config) => SiteUri::fromArray($config),
                    $this->siteUris,
                ));
            }
        } catch (RetryableDriverException $e) {
            if ($this->attempt >= self::MAX_ATTEMPTS) {
                Craft::error(
                    "Edge purge failed after {$this->attempt} attempts: {$e->getMessage()}",
                    __METHOD__
                );

                return;
            }

            // Exponential backoff: 30s, 60s, 120s, 240s.
            $delay = 30 * (2 ** ($this->attempt - 1));
            QueueHelper::push(new self(
                siteUris: $this->siteUris,
                tags: $this->tags,
                purgeAll: $this->purgeAll,
                attempt: $this->attempt + 1,
            ), priority: 100, delay: $delay);

            Craft::warning(
                "Edge purge attempt {$this->attempt} failed ({$e->getMessage()}); retrying in {$delay}s.",
                __METHOD__
            );
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        if ($this->purgeAll) {
            return 'Purging the entire edge cache';
        }

        $count = count($this->siteUris) + count($this->tags);

        return "Purging $count edge cache " . (!empty($this->tags) ? 'tags' : 'URLs');
    }
}
