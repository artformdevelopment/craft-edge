<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\controllers;

use artformdev\edge\db\Table;
use artformdev\edge\drivers\CloudflareDriver;
use artformdev\edge\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP actions behind the utility's buttons: clear cache, run verify, Cloudflare setup.
 * All admin-only, all POST.
 */
class CpController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requireAdmin();

        return parent::beforeAction($action);
    }

    /**
     * POST edge/cp/clear: clears records + the managed tier.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        Craft::$app->getDb()->createCommand()->delete(Table::CACHES)->execute();

        try {
            Plugin::getInstance()->getDriver()->flushAll();
            Craft::$app->getSession()->setNotice('Edge cache cleared.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError("Edge cache records cleared, but the edge flush failed: {$e->getMessage()}");
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * POST edge/cp/verify: runs the active driver's verification against the primary site.
     */
    public function actionVerify(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $url = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        try {
            $result = $plugin->getDriver()->verify($url);
            $plugin->setLastVerifyResult($result);
            if ($result->ok) {
                Craft::$app->getSession()->setNotice('Edge verification passed.');
            } else {
                Craft::$app->getSession()->setError('Edge verification failed. See the utility panel for details.');
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError("Edge verification errored: {$e->getMessage()}");
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * POST edge/cp/cloudflare-setup: idempotently creates/updates the zone cache rules.
     * Explicit and opt-in: it mutates the Cloudflare zone.
     */
    public function actionCloudflareSetup(): Response
    {
        $this->requirePostRequest();

        try {
            $driver = new CloudflareDriver();
            $lines = $driver->setup();
            Craft::$app->getSession()->setNotice(implode(' ', $lines));
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError("Cloudflare setup failed: {$e->getMessage()}");
        }

        return $this->redirectToPostedUrl();
    }
}
