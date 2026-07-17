<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\console\controllers;

use artformdev\edge\db\Table;
use artformdev\edge\jobs\WarmJob;
use artformdev\edge\models\SiteUri;
use artformdev\edge\Plugin;
use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\Queue as QueueHelper;
use yii\console\ExitCode;

/**
 * Edge cache management: edge/cache/clear, clear-url, warm, generate.
 */
class CacheController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'clear';

    /**
     * Clears the entire edge cache (local records + the managed tier).
     */
    public function actionClear(): int
    {
        $plugin = Plugin::getInstance();

        Craft::$app->getDb()->createCommand()->delete(Table::CACHES)->execute();

        try {
            $plugin->getDriver()->flushAll();
            $this->stdout("Edge cache cleared ({$plugin->getSettings()->driver}).\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            $this->stderr("Cache records cleared, but the edge flush failed: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Purges a single URL from the edge cache.
     *
     * @param string|null $url The absolute URL to purge
     */
    public function actionClearUrl(?string $url = null): int
    {
        if ($url === null) {
            $this->stderr("Usage: edge/cache/clear-url <absolute-url>\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $siteUri = $this->findSiteUri($url);

        if ($siteUri === null) {
            $this->stdout("No cached entry recorded for $url, purging the URL at the edge anyway.\n", Console::FG_YELLOW);
            $site = Craft::$app->getSites()->getPrimarySite();
            $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
            $siteUri = new SiteUri($site->id, $path);
        } else {
            Craft::$app->getDb()->createCommand()
                ->delete(Table::CACHES, ['siteId' => $siteUri->siteId, 'uri' => $siteUri->uri])
                ->execute();
        }

        try {
            Plugin::getInstance()->getDriver()->purge([$siteUri]);
            $this->stdout("Purged $url\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            $this->stderr("Purge failed: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Warms every known cacheable URL (queued).
     */
    public function actionWarm(): int
    {
        $siteUris = Plugin::getInstance()->invalidator->getAllCachedSiteUris();

        if (empty($siteUris)) {
            $this->stdout("No cached URLs recorded yet, nothing to do. Run edge/cache/generate to seed from your content.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        QueueHelper::push(new WarmJob(
            siteUris: array_map(fn(SiteUri $siteUri) => $siteUri->toArray(), $siteUris),
        ));

        $this->stdout('Queued a warm job for ' . count($siteUris) . " URLs. Run queue/run to process it.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Requests every live element URL (entries + categories, all sites) through the edge,
     * generating the cache from scratch.
     */
    public function actionGenerate(): int
    {
        $siteUris = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteUris[] = new SiteUri($site->id, '');

            foreach ([Entry::class, Category::class] as $elementType) {
                $uris = $elementType::find()
                    ->siteId($site->id)
                    ->status($elementType === Entry::class ? Entry::STATUS_LIVE : 'enabled')
                    ->uri(':notempty:')
                    ->select(['elements_sites.uri'])
                    ->column();

                foreach ($uris as $uri) {
                    $siteUris[] = new SiteUri($site->id, $uri === '__home__' ? '' : (string)$uri);
                }
            }
        }

        $unique = [];
        foreach ($siteUris as $siteUri) {
            $unique[$siteUri->siteId . ':' . $siteUri->uri] = $siteUri;
        }

        if (empty($unique)) {
            $this->stdout("No live element URLs found, nothing to do.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        QueueHelper::push(new WarmJob(
            siteUris: array_map(fn(SiteUri $siteUri) => $siteUri->toArray(), array_values($unique)),
        ));

        $this->stdout('Queued generation of ' . count($unique) . " URLs. Run queue/run to process it.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function findSiteUri(string $url): ?SiteUri
    {
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');

        $rows = (new Query())
            ->select(['siteId', 'uri'])
            ->from(Table::CACHES)
            ->where(['uri' => $path])
            ->orWhere(['like', 'uri', $path . '?%', false])
            ->all();

        foreach ($rows as $row) {
            $siteUri = new SiteUri((int)$row['siteId'], $row['uri']);
            if (str_starts_with($siteUri->getUrl(), rtrim($url, '/'))) {
                return $siteUri;
            }
        }

        return null;
    }
}
