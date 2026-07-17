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

        $generalConfig = Craft::$app->getConfig()->getGeneral();

        if (!$generalConfig->enableCsrfProtection) {
            return $this->asJson(['token' => null, 'param' => null]);
        }

        // getCsrfToken() starts the CSRF cookie; touching the session gives the visitor
        // their CraftSessionId. Both Set-Cookie headers pass through (uncached response).
        Craft::$app->getSession()->open();

        return $this->asJson([
            'token' => Craft::$app->getRequest()->getCsrfToken(),
            'param' => $generalConfig->csrfTokenName,
        ]);
    }

    /**
     * GET edge/island?name=x: renders a per-visitor fragment from the configured
     * islands template path. Missing islands 404 without breaking the page.
     */
    public function actionIsland(): Response
    {
        $this->setNoStore();

        $name = (string)Craft::$app->getRequest()->getQueryParam('name', '');

        if ($name === '' || !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $name) || str_contains($name, '..')) {
            throw new NotFoundHttpException('Invalid island name.');
        }

        $template = trim(Plugin::getInstance()->getSettings()->islandsTemplatePath, '/') . '/' . $name;

        $view = Craft::$app->getView();

        if (!$view->doesTemplateExist($template)) {
            throw new NotFoundHttpException("Island template `$template` not found.");
        }

        try {
            $html = $view->renderTemplate($template);
        } catch (\Throwable $e) {
            Craft::warning("Edge island `$name` failed to render: {$e->getMessage()}", __METHOD__);

            // An island error must never 500 the page; return an empty fragment.
            $html = '';
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
