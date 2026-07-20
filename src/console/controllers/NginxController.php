<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\console\controllers;

use artformdev\edge\models\Settings;
use artformdev\edge\Plugin;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * edge/nginx/verify: curls a URL twice and asserts MISS to HIT with no Set-Cookie on the
 * cached response, for whichever nginx driver is active.
 */
class NginxController extends Controller
{
    /**
     * @var string|null URL to verify (defaults to the primary site's base URL).
     */
    public ?string $url = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'url';

        return $options;
    }

    /**
     * Verifies the active nginx driver end-to-end.
     */
    public function actionVerify(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!in_array($settings->driver, [Settings::DRIVER_NGINX_STATIC, Settings::DRIVER_NGINX_FASTCGI], true)) {
            $this->stderr("The active driver is `{$settings->driver}`. edge/nginx/verify only applies to the nginx drivers.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $url = $this->url ?? Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        if (!$url) {
            $this->stderr("No URL to verify. Pass --url.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        try {
            $result = $plugin->getDriver()->verify($url);
        } catch (\Throwable $e) {
            $this->stderr("Verify failed: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($result->lines as $line) {
            $this->stdout("$line\n");
        }

        foreach (Plugin::proxyWarnings() as $warning) {
            $this->stdout("WARNING: $warning\n", Console::FG_YELLOW);
        }

        $plugin->setLastVerifyResult($result);
        $this->stdout($result->ok ? "nginx verification PASSED.\n" : "nginx verification FAILED.\n", $result->ok ? Console::FG_GREEN : Console::FG_RED);

        return $result->ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
