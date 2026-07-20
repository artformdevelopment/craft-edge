<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\controllers;

use artformdev\edge\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The uncached per-visitor endpoints that keep CSRF, sessions and islands working on
 * fully-cached pages. Every response here is `private, no-store` and its cookies pass
 * through untouched: this is where sessions legitimately start.
 */
class DynamicController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    /**
     * GET edge/csrf: starts the session and returns the CSRF token for form hydration.
     */
    public function actionCsrf(): Response
    {
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->getSettings()->csrfEndpointEnabled) {
            throw new NotFoundHttpException();
        }

        $this->setNoStore();

        return $this->asJson($this->csrfPayload());
    }

    /**
     * GET edge/hydrate?islands=a,b,c: the whole per-visitor payload in one round trip.
     *
     * A cached page is otherwise paying one uncached request for the token plus one per
     * island, all of which reach PHP. Batching them means a cache hit costs a single
     * origin request no matter how many islands the page has.
     */
    public function actionHydrate(): Response
    {
        $this->requireAcceptsJson();
        $this->setNoStore();

        $payload = $this->csrfPayload();
        $payload['islands'] = [];

        $requested = (string)Craft::$app->getRequest()->getQueryParam('islands', '');

        foreach (array_filter(array_map('trim', explode(',', $requested))) as $name) {
            if (!self::isValidIslandName($name)) {
                continue;
            }
            // A missing or broken island returns an empty fragment rather than failing
            // the batch: one bad island must not cost the page its token.
            $payload['islands'][$name] = $this->renderIsland($name) ?? '';
        }

        return $this->asJson($payload);
    }

    /**
     * @return array{token: string|null, param: string|null}
     */
    private function csrfPayload(): array
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();

        if (!$generalConfig->enableCsrfProtection
            || !Plugin::getInstance()->getSettings()->csrfEndpointEnabled
        ) {
            return ['token' => null, 'param' => null];
        }

        // getCsrfToken() starts the CSRF cookie; touching the session gives the visitor
        // their CraftSessionId. Both Set-Cookie headers pass through (uncached response).
        Craft::$app->getSession()->open();

        return [
            'token' => Craft::$app->getRequest()->getCsrfToken(),
            'param' => $generalConfig->csrfTokenName,
        ];
    }

    private static function isValidIslandName(string $name): bool
    {
        return $name !== ''
            && preg_match('/^[a-zA-Z0-9_\-\/]+$/', $name) === 1
            && !str_contains($name, '..');
    }

    /**
     * Renders an island fragment, or null when the template doesn't exist.
     */
    private function renderIsland(string $name): ?string
    {
        $template = trim(Plugin::getInstance()->getSettings()->islandsTemplatePath, '/') . '/' . $name;
        $view = Craft::$app->getView();

        if (!$view->doesTemplateExist($template)) {
            return null;
        }

        try {
            return $view->renderTemplate($template);
        } catch (\Throwable $e) {
            Craft::warning("Edge island `$name` failed to render: {$e->getMessage()}", __METHOD__);

            // An island error must never 500 the page; return an empty fragment.
            return '';
        }
    }

    /**
     * GET edge/island?name=x: renders a per-visitor fragment from the configured
     * islands template path. Missing islands 404 without breaking the page.
     */
    public function actionIsland(): Response
    {
        $this->setNoStore();

        $name = (string)Craft::$app->getRequest()->getQueryParam('name', '');

        if (!self::isValidIslandName($name)) {
            throw new NotFoundHttpException('Invalid island name.');
        }

        $html = $this->renderIsland($name);

        if ($html === null) {
            throw new NotFoundHttpException("Island template for `$name` not found.");
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_HTML;
        $response->content = $html;

        return $response;
    }

    private function setNoStore(): void
    {
        Craft::$app->getResponse()->getHeaders()->set('Cache-Control', 'private, no-store');
    }
}
