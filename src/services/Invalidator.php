<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\services;

use artformdev\edge\db\Table;
use artformdev\edge\jobs\PurgeJob;
use artformdev\edge\jobs\WarmJob;
use artformdev\edge\models\Settings;
use artformdev\edge\models\SiteUri;
use artformdev\edge\Plugin;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\GlobalSet;
use craft\helpers\ElementHelper;
use craft\helpers\Queue as QueueHelper;

/**
 * Resolves content changes to the COMPLETE set of stale cached URIs (the UNION of the
 * element-ID map and the element-query-tag map) and dispatches batched purge/warm jobs.
 * Nothing purges inline: the triggering save returns before any edge purge happens.
 */
class Invalidator extends Component
{
    /** @var array<string, true> Craft cache tags buffered for this request */
    private array $tags = [];

    /** @var array<int, true> element IDs buffered for this request */
    private array $elementIds = [];

    private bool $coarseFlush = false;
    private bool $flushRegistered = false;

    /**
     * Handles Craft's EVENT_INVALIDATE_CACHES tags (the primary change signal).
     *
     * @param string[] $tags
     */
    public function addInvalidatedTags(array $tags, ?ElementInterface $element = null): void
    {
        // Non-live changes (drafts, revisions) never purge live URLs.
        if ($element !== null && ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        // Global set content lives outside element queries (accessed via the `globals`
        // variable), so precise resolution is impossible -> coarse flush, per the spec.
        if ($element instanceof GlobalSet) {
            $this->requestCoarseFlush('global set saved');

            return;
        }

        foreach ($tags as $tag) {
            // 'element' = "every element of every type" (invalidateAllCaches) -> coarse.
            if ($tag === 'element') {
                $this->requestCoarseFlush('all element caches invalidated');

                return;
            }

            $this->tags[$tag] = true;

            // "element::<id>" also resolves through the element-ID map.
            if (preg_match('/^element::(\d+)$/', $tag, $match)) {
                $this->elementIds[(int)$match[1]] = true;
            }
        }

        $this->registerFlush();
    }

    /**
     * Belt-and-braces signal from save/delete/restore/structure-move events.
     */
    public function addElement(ElementInterface $element): void
    {
        if ($element->id === null || ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        if ($element instanceof GlobalSet) {
            $this->requestCoarseFlush('global set saved');

            return;
        }

        $this->elementIds[$element->id] = true;
        $this->registerFlush();
    }

    /**
     * Flushes the entire managed tier (plugin settings changed, plugin updated, global
     * set saved, etc.). Still asynchronous: a queued job performs the edge purge.
     */
    public function requestCoarseFlush(string $reason): void
    {
        if (!$this->coarseFlush) {
            Craft::info("Edge coarse flush requested: $reason", __METHOD__);
        }
        $this->coarseFlush = true;
        $this->registerFlush();
    }

    /**
     * Resolves the buffered changes and dispatches the queue jobs. Called once at the
     * end of the request (or manually from tests/CLI).
     */
    public function flushBuffer(): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if ($this->coarseFlush) {
            $this->tags = [];
            $this->elementIds = [];
            $this->coarseFlush = false;

            $allUris = $this->getAllCachedSiteUris();
            Craft::$app->getDb()->createCommand()->delete(Table::CACHES)->execute();

            // ponytail: ngx_cache_purge wildcard purges need a nonstandard build, so the
            // fastcgi driver also purges every known URI individually on a coarse flush.
            if ($settings->driver === Settings::DRIVER_NGINX_FASTCGI) {
                $this->pushPurgeJobs($allUris, $settings);
            }

            QueueHelper::push(new PurgeJob(purgeAll: true), priority: 100);
            $this->pushWarmJobs($settings->warmCacheAutomatically ? $allUris : [], $settings);

            return;
        }

        if (empty($this->tags) && empty($this->elementIds)) {
            return;
        }

        $siteUris = $this->resolveSiteUris(array_keys($this->tags), array_keys($this->elementIds));
        $this->tags = [];
        $this->elementIds = [];

        if (empty($siteUris)) {
            return;
        }

        // Remove the DB rows now (idempotent; pages re-register on next render).
        $cacheIds = array_keys($siteUris);
        Craft::$app->getDb()->createCommand()->delete(Table::CACHES, ['id' => $cacheIds])->execute();

        // Then purge the edge asynchronously, in batches.
        $uris = array_values($siteUris);
        $this->pushPurgeJobs($uris, $settings);
        $this->pushWarmJobs($uris, $settings);
    }

    /**
     * The union resolution: URIs whose recorded query tags match the invalidated tags,
     * plus URIs that rendered any of the changed element IDs.
     *
     * @param string[] $tags
     * @param int[] $elementIds
     * @return array<int, SiteUri> keyed by cache ID
     */
    public function resolveSiteUris(array $tags, array $elementIds): array
    {
        $cacheIds = [];

        if (!empty($tags)) {
            $cacheIds = (new Query())
                ->select('cacheId')
                ->distinct()
                ->from(Table::CACHE_TAGS)
                ->where(['tag' => $tags])
                ->column();
        }

        if (!empty($elementIds)) {
            $cacheIds = array_merge($cacheIds, (new Query())
                ->select('cacheId')
                ->distinct()
                ->from(Table::CACHE_ELEMENTS)
                ->where(['elementId' => $elementIds])
                ->column());
        }

        if (empty($cacheIds)) {
            return [];
        }

        $rows = (new Query())
            ->select(['id', 'siteId', 'uri'])
            ->from(Table::CACHES)
            ->where(['id' => array_unique($cacheIds)])
            ->all();

        $siteUris = [];
        foreach ($rows as $row) {
            $siteUris[(int)$row['id']] = new SiteUri((int)$row['siteId'], $row['uri']);
        }

        return $siteUris;
    }

    /**
     * @return SiteUri[]
     */
    public function getAllCachedSiteUris(): array
    {
        $rows = (new Query())
            ->select(['siteId', 'uri'])
            ->from(Table::CACHES)
            ->all();

        return array_map(fn(array $row) => new SiteUri((int)$row['siteId'], $row['uri']), $rows);
    }

    /**
     * @param SiteUri[] $siteUris
     */
    public function pushPurgeJobs(array $siteUris, Settings $settings): void
    {
        $chunkSize = max(1, $settings->cloudflarePurgeChunkSize);

        // Cloudflare Enterprise tag mode: every cached page emits a unique per-page
        // Cache-Tag (Generator::pageTag), so purging those tags is exact.
        if ($settings->driver === Settings::DRIVER_CLOUDFLARE && $settings->cloudflareUsesCacheTags) {
            $tags = array_map(
                fn(SiteUri $siteUri) => Generator::pageTag($siteUri),
                array_values($siteUris),
            );
            foreach (array_chunk(array_unique($tags), $chunkSize) as $chunk) {
                QueueHelper::push(new PurgeJob(tags: $chunk), priority: 100);
            }

            return;
        }

        foreach (self::chunkSiteUris($siteUris, $chunkSize) as $chunk) {
            QueueHelper::push(new PurgeJob(siteUris: $chunk), priority: 100);
        }
    }

    /**
     * Splits site URIs into job-sized payload chunks (pure, unit-tested: 31 URLs with
     * a chunk size of 30 become two payloads of [30, 1]).
     *
     * @param SiteUri[] $siteUris
     * @return array<int, array<int, array{siteId: int, uri: string}>>
     */
    public static function chunkSiteUris(array $siteUris, int $chunkSize): array
    {
        $payloads = array_map(
            fn(SiteUri $siteUri) => $siteUri->toArray(),
            array_values($siteUris),
        );

        return array_chunk($payloads, max(1, $chunkSize));
    }

    /**
     * @param SiteUri[] $siteUris
     */
    private function pushWarmJobs(array $siteUris, Settings $settings): void
    {
        if (!$settings->warmCacheAutomatically || empty($siteUris)) {
            return;
        }

        QueueHelper::push(new WarmJob(
            siteUris: array_map(fn(SiteUri $siteUri) => $siteUri->toArray(), array_values($siteUris)),
        ), priority: 200, delay: 5);
    }

    /**
     * Registers a one-time end-of-request hook so all changes from a single request
     * are resolved and dispatched together (and the save returns before any purge).
     */
    private function registerFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;

        Craft::$app->on(\yii\base\Application::EVENT_AFTER_REQUEST, function() {
            $this->flushRegistered = false;
            $this->flushBuffer();
        });
    }
}
