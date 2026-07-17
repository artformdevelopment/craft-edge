<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\twig;

use artformdev\edge\web\assets\hydrate\HydrateAsset;
use Craft;
use craft\helpers\Html;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * `{{ edgeIsland('name') }}`: emits a placeholder that edge-hydrate.js swaps for the
 * uncached `edge/island` fragment on load.
 */
class EdgeExtension extends AbstractExtension
{
    /**
     * @inheritdoc
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('edgeIsland', [$this, 'edgeIsland']),
        ];
    }

    public function edgeIsland(string $name, string $placeholder = ''): Markup
    {
        try {
            Craft::$app->getView()->registerAssetBundle(HydrateAsset::class);
        } catch (\Throwable $e) {
            Craft::warning("Edge could not register the hydrate asset: {$e->getMessage()}", __METHOD__);
        }

        $html = Html::tag('div', $placeholder, [
            'data-edge-island' => $name,
        ]);

        return new Markup($html, Craft::$app->charset);
    }
}
