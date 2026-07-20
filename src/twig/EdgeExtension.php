<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\twig;

use artformdev\edge\Plugin;
use artformdev\edge\web\assets\hydrate\HydrateAsset;
use Craft;
use craft\helpers\Html;
use craft\web\Request as WebRequest;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * `{{ edgeIsland('name') }}`: emits a placeholder that edge-hydrate.js swaps for the
 * uncached `edge/island` fragment on load.
 *
 * `{{ edgeCsrfInput() }}`: a CSRF field that is correct whether or not the page is
 * being cached.
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
            new TwigFunction('edgeCsrfInput', [$this, 'edgeCsrfInput']),
        ];
    }

    /**
     * A CSRF field that is safe on any page, so templates don't have to know whether the
     * page they're in is cached.
     *
     * On a page being cached, a real token must never be written into the shared HTML, so
     * this emits a token-free placeholder and the hydration runtime fills it from
     * `edge/csrf` once the visitor has a session. On a page that is NOT being cached (an
     * excluded URI, a bypass, an island fragment, a signed-in render when
     * `cacheLoggedInRenders` is off) it emits the real token inline, which needs no round
     * trip and works without JavaScript.
     *
     * Use it in place of `csrfInput()` on any template that might be reached by both.
     */
    public function edgeCsrfInput(): Markup
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();

        if (!$generalConfig->enableCsrfProtection) {
            return new Markup('', Craft::$app->charset);
        }

        $request = Craft::$app->getRequest();
        $isCached = $request instanceof WebRequest
            && Plugin::getInstance()->generator->getIsTracking();

        if ($isCached) {
            // The runtime does the filling, and a page may use this without any island.
            try {
                Craft::$app->getView()->registerAssetBundle(HydrateAsset::class);
            } catch (\Throwable $e) {
                Craft::warning("Edge could not register the hydrate asset: {$e->getMessage()}", __METHOD__);
            }

            return new Markup('<craft-csrf-input></craft-csrf-input>', Craft::$app->charset);
        }

        if (!$request instanceof WebRequest) {
            return new Markup('', Craft::$app->charset);
        }

        $html = Html::hiddenInput($generalConfig->csrfTokenName, $request->getCsrfToken());

        return new Markup($html, Craft::$app->charset);
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
