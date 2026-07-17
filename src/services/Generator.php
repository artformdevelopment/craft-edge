<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\services;

use artformdev\edge\db\Table;
use artformdev\edge\models\SiteUri;
use artformdev\edge\Plugin;
use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\events\CancelableEvent;
use craft\events\PopulateElementEvent;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use yii\base\Event;
use yii\web\Response;

/**
 * Tracks every element and element query a cacheable render touches, then stores the
 * final cookie-free HTML through the active driver and upserts the dependency rows.
 *
 * Tag model (Craft-native, see NOTES):
 * - element IDs of populated elements -> edge_cache_elements (matched by "element::<id>"
 *   invalidation tags and save/delete events)
 * - `ElementQuery::getCacheTags()` of every prepared query -> edge_cache_tags (matched
 *   exactly against the tags Craft fires in EVENT_INVALIDATE_CACHES; catches brand-new
 *   elements that match existing listing queries)
 */
class Generator extends Component
{
    private ?SiteUri $siteUri = null;

    /** @var array<int, true> */
    private array $elementIds = [];

    /** @var array<string, true> */
    private array $tags = [];

    /**
     * Starts tracking for a cacheable request.
     */
    public function start(SiteUri $siteUri): void
    {
        $this->siteUri = $siteUri;
        $this->elementIds = [];
        $this->tags = [];

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT,
            function(PopulateElementEvent $event) {
                if ($event->element instanceof Element) {
                    $this->trackElement($event->element);
                }
            }
        );

        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_PREPARE,
            function(CancelableEvent $event) {
                /** @var ElementQuery $query */
                $query = $event->sender;
                $this->trackElementQuery($query);
            }
        );
    }

    public function getIsTracking(): bool
    {
        return $this->siteUri !== null;
    }

    /**
     * The tags recorded for the current render (used for Cache-Tag headers).
     *
     * @return string[]
     */
    public function getTags(): array
    {
        $tags = array_keys($this->tags);

        foreach (array_keys($this->elementIds) as $elementId) {
            $tags[] = "element::$elementId";
        }

        return $tags;
    }

    /**
     * Records an element the page rendered.
     */
    public function trackElement(Element $element): void
    {
        if (!$this->getIsTracking() || $element->id === null) {
            return;
        }

        // Only canonical, live content creates purge dependencies.
        if (ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        $this->elementIds[$element->id] = true;
    }

    /**
     * Records an element query the page ran, via Craft's own cache tags.
     */
    public function trackElementQuery(ElementQuery $query): void
    {
        if (!$this->getIsTracking()) {
            return;
        }

        // Draft/revision queries never affect live pages.
        if ($query->drafts !== false || $query->revisions !== false) {
            return;
        }

        try {
            $tags = $query->getCacheTags();
        } catch (\Throwable) {
            return;
        }

        foreach ($tags as $tag) {
            // 'element' (every element of every type) is far too broad to store per page,
            // so an invalidation of that tag triggers a coarse flush instead (see Invalidator).
            if ($tag === 'element') {
                continue;
            }
            $this->tags[$tag] = true;
        }
    }

    /**
     * Called at Response::EVENT_AFTER_PREPARE for a cacheable request: applies the
     * cookie-safety layer, stores the HTML through the driver and saves dependencies.
     */
    public function save(Response $response): void
    {
        if (!$this->getIsTracking()) {
            return;
        }

        $siteUri = $this->siteUri;
        $plugin = Plugin::getInstance();
        $driver = $plugin->getDriver();

        // Only OK HTML responses are storable.
        $isHtml = in_array($response->format, [Response::FORMAT_HTML, 'template'], true);
        if (!$response->getIsOk() || !$isHtml || $response->content === null) {
            $driver->prepareResponse($response, false);

            return;
        }

        // The per-page tag makes Cloudflare tag-mode purging exact (see Invalidator).
        $tags = [...$this->getTags(), self::pageTag($siteUri)];

        // Cookie-safety layer: strips Set-Cookie/Vary, sets public cache headers.
        $driver->prepareResponse($response, true, $tags);

        // Belt and braces: if a Set-Cookie survived (a plugin wrote headers directly),
        // do NOT store the response.
        $decision = $plugin->cacheability->evaluateResponse(
            $response->getStatusCode(),
            $response->getHeaders()->get('Set-Cookie') !== null,
        );

        if (!$decision->cacheable) {
            Craft::warning("Edge did not store {$siteUri->uri}: {$decision->reason}", __METHOD__);
            $response->getHeaders()->set('Cache-Control', 'private, no-store');

            return;
        }

        if (!$driver->store($siteUri, $response->content)) {
            return;
        }

        $this->saveDependencies($siteUri, array_keys($this->elementIds), array_keys($this->tags));
    }

    /**
     * The per-page Cache-Tag value used by Cloudflare tag-mode purging.
     */
    public static function pageTag(SiteUri $siteUri): string
    {
        return 'edge:uri:' . md5($siteUri->siteId . ':' . $siteUri->uri);
    }

    /**
     * Upserts the cache row and replaces its element/tag dependency rows.
     *
     * @param int[] $elementIds
     * @param string[] $tags
     */
    public function saveDependencies(SiteUri $siteUri, array $elementIds, array $tags): void
    {
        $db = Craft::$app->getDb();

        $cacheId = (new Query())
            ->select('id')
            ->from(Table::CACHES)
            ->where(['siteId' => $siteUri->siteId, 'uri' => $siteUri->uri])
            ->scalar();

        if (!$cacheId) {
            $db->createCommand()->insert(Table::CACHES, [
                'siteId' => $siteUri->siteId,
                'uri' => $siteUri->uri,
                'dateCached' => Db::prepareDateForDb(new \DateTime()),
            ])->execute();
            $cacheId = (int)$db->getLastInsertID();
        } else {
            $db->createCommand()->update(Table::CACHES, [
                'dateCached' => Db::prepareDateForDb(new \DateTime()),
            ], ['id' => $cacheId])->execute();
        }

        // Replace dependencies atomically enough for idempotent re-renders.
        $db->createCommand()->delete(Table::CACHE_ELEMENTS, ['cacheId' => $cacheId])->execute();
        $db->createCommand()->delete(Table::CACHE_TAGS, ['cacheId' => $cacheId])->execute();

        // Only reference elements that still exist (FK safety).
        if (!empty($elementIds)) {
            $existingIds = (new Query())
                ->select('id')
                ->from(\craft\db\Table::ELEMENTS)
                ->where(['id' => $elementIds])
                ->column();

            if (!empty($existingIds)) {
                $db->createCommand()->batchInsert(
                    Table::CACHE_ELEMENTS,
                    ['cacheId', 'elementId'],
                    array_map(fn($id) => [$cacheId, (int)$id], $existingIds),
                )->execute();
            }
        }

        if (!empty($tags)) {
            $db->createCommand()->batchInsert(
                Table::CACHE_TAGS,
                ['cacheId', 'tag'],
                array_map(fn(string $tag) => [$cacheId, mb_substr($tag, 0, 255)], array_unique($tags)),
            )->execute();
        }
    }
}
