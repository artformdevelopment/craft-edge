<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\web\assets\hydrate;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\AssetBundle;
use craft\web\View;

/**
 * The vanilla-JS hydration runtime: injects the visitor's CSRF token into forms and
 * swaps island placeholders for their uncached fragments. No framework, no build step.
 */
class HydrateAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = __DIR__ . '/dist';

    /**
     * @inheritdoc
     */
    public $js = ['edge-hydrate.js'];

    /**
     * @inheritdoc
     */
    public function registerAssetFiles($view): void
    {
        if ($view instanceof View) {
            // Static per-site config, safe to bake into cached HTML (never per-visitor).
            // Root-relative URLs: an absolute scheme baked into the cache would break
            // (mixed content) when the page is served over the other scheme.
            $config = Json::encode([
                'csrfUrl' => $this->rootRelative(UrlHelper::siteUrl('edge/csrf')),
                'islandUrl' => $this->rootRelative(UrlHelper::siteUrl('edge/island')),
                'csrfParam' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            ]);
            $view->registerJs("window.EdgeConfig = $config;", View::POS_HEAD);
        }

        parent::registerAssetFiles($view);
    }

    private function rootRelative(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
