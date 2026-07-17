<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\console\controllers;

use artformdev\edge\drivers\CloudflareDriver;
use artformdev\edge\Plugin;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Cloudflare automation: edge/cloudflare/setup (idempotent cache rules) and
 * edge/cloudflare/verify. Setup mutates your zone; it is explicit and opt-in,
 * never run on install.
 */
class CloudflareController extends Controller
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
        if ($actionID === 'verify') {
            $options[] = 'url';
        }

        return $options;
    }

    /**
     * Creates/updates the two Edge cache rules on the zone (bypass-on-cookie first,
     * then cache-eligible HTML). Idempotent: existing Edge rules are replaced, other
     * rules preserved.
     */
    public function actionSetup(): int
    {
        $driver = $this->getCloudflareDriver();
        if ($driver === null) {
            return ExitCode::CONFIG;
        }

        try {
            foreach ($driver->setup() as $line) {
                $this->stdout("$line\n", Console::FG_GREEN);
            }
        } catch (\Throwable $e) {
            $this->stderr("Setup failed: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Requests a URL twice through Cloudflare and asserts CF-Cache-Status: HIT on the
     * second hit with no Set-Cookie leak.
     */
    public function actionVerify(): int
    {
        $driver = $this->getCloudflareDriver();
        if ($driver === null) {
            return ExitCode::CONFIG;
        }

        $url = $this->url ?? Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        if (!$url) {
            $this->stderr("No URL to verify. Pass --url.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        try {
            $result = $driver->verify($url);
        } catch (\Throwable $e) {
            $this->stderr("Verify failed: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($result->lines as $line) {
            $this->stdout("$line\n");
        }

        Plugin::getInstance()->setLastVerifyResult($result);
        $this->stdout($result->ok ? "Cloudflare verification PASSED.\n" : "Cloudflare verification FAILED.\n", $result->ok ? Console::FG_GREEN : Console::FG_RED);

        return $result->ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function getCloudflareDriver(): ?CloudflareDriver
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->getParsedCloudflareApiToken() || !$settings->getParsedCloudflareZoneId()) {
            $this->stderr(
                "Cloudflare is not configured: set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID env vars\n" .
                "(referenced from config/edge.php as cloudflareApiToken / cloudflareZoneId).\n",
                Console::FG_RED
            );

            return null;
        }

        return new CloudflareDriver();
    }
}
