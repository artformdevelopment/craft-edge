<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\utilities;

use artformdev\edge\db\Table;
use artformdev\edge\Plugin;
use Craft;
use craft\base\Utility;
use craft\db\Query;

/**
 * The CP health panel: active driver, cache counts, last verify result, clear button.
 */
class EdgeUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Edge Cache';
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'edge-cache';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'bolt';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $cachedCount = (new Query())->from(Table::CACHES)->count();
        $elementLinks = (new Query())->from(Table::CACHE_ELEMENTS)->count();
        $tagLinks = (new Query())->from(Table::CACHE_TAGS)->count();

        return Craft::$app->getView()->renderTemplate('edge/utility', [
            'settings' => $settings,
            'cachedCount' => $cachedCount,
            'elementLinks' => $elementLinks,
            'tagLinks' => $tagLinks,
            'lastVerify' => $plugin->getLastVerifyResult(),
            'proxyWarnings' => Plugin::proxyWarnings(),
        ]);
    }
}
