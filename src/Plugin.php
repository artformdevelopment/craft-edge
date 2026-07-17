<?php
/**
 * Edge plugin for Craft CMS 5: full-page HTML caching at one edge tier (nginx or
 * Cloudflare) with exact, element-driven invalidation.
 *
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge;

use artformdev\edge\db\Table;
use artformdev\edge\drivers\CacheDriverInterface;
use artformdev\edge\drivers\CloudflareDriver;
use artformdev\edge\drivers\NginxFastCgiDriver;
use artformdev\edge\drivers\NginxStaticDriver;
use artformdev\edge\drivers\VerifyResult;
use artformdev\edge\models\RequestContext;
use artformdev\edge\models\Settings;
use artformdev\edge\services\Cacheability;
use artformdev\edge\services\Generator;
use artformdev\edge\services\Invalidator;
use artformdev\edge\twig\EdgeExtension;
use artformdev\edge\utilities\EdgeUtility;
use artformdev\edge\web\assets\hydrate\HydrateAsset;
use Craft;
use craft\events\DeleteElementEvent;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\MoveElementEvent;
use craft\events\MultiElementActionEvent;
use craft\events\PluginEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\services\Plugins;
use craft\services\Structures;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\Application as WebApplication;
use craft\web\UrlManager;
use yii\base\Event;
use yii\web\Response;

/**
 * @property-read Cacheability $cacheability
 * @property-read Generator $generator
 * @property-read Invalidator $invalidator
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    public const LAST_VERIFY_CACHE_KEY = 'edge:lastVerify';

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    private ?CacheDriverInterface $driver = null;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'cacheability' => Cacheability::class,
                'generator' => Generator::class,
                'invalidator' => Invalidator::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->registerElementEvents();
        $this->registerCoarseFlushEvents();
        $this->registerCpComponents();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        Craft::$app->getView()->registerTwigExtension(new EdgeExtension());
        $this->registerSiteUrlRules();
        $this->registerCacheableRequestHandling();
    }

    /**
     * The active edge driver (exactly one tier is managed at a time).
     */
    public function getDriver(): CacheDriverInterface
    {
        if ($this->driver === null) {
            $this->driver = match ($this->getSettings()->driver) {
                Settings::DRIVER_NGINX_FASTCGI => new NginxFastCgiDriver(),
                Settings::DRIVER_CLOUDFLARE => new CloudflareDriver(),
                default => new NginxStaticDriver(),
            };
        }

        return $this->driver;
    }

    /**
     * @return array{ok: bool, lines: string[], driver: string, date: string}|null
     */
    public function getLastVerifyResult(): ?array
    {
        $value = Craft::$app->getCache()->get(self::LAST_VERIFY_CACHE_KEY);

        return is_array($value) ? $value : null;
    }

    public function setLastVerifyResult(VerifyResult $result): void
    {
        Craft::$app->getCache()->set(self::LAST_VERIFY_CACHE_KEY, [
            'ok' => $result->ok,
            'lines' => $result->lines,
            'driver' => $this->getSettings()->driver,
            'date' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ], 0);
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('edge/settings', [
            'settings' => $this->getSettings(),
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile('edge')),
        ]);
    }

    /**
     * The request lifecycle: decide cacheability at web app init, track + store at
     * response prepare, and guarantee `private, no-store` on everything not cached.
     */
    private function registerCacheableRequestHandling(): void
    {
        Event::on(WebApplication::class, WebApplication::EVENT_INIT, function() {
            $request = Craft::$app->getRequest();

            if (!$request->getIsSiteRequest()) {
                return;
            }

            $settings = $this->getSettings();
            $ctx = RequestContext::fromCurrentRequest();
            $decision = $this->cacheability->evaluateRequest($ctx, $settings);

            // Hydration must run on bypassed pages too (a logged-in user's forms still
            // hydrate their CSRF token from edge/csrf), so register it for every
            // ordinary site page view, not only cacheable ones.
            if ($settings->autoInjectHydrationScript
                && strtoupper($ctx->method) === 'GET'
                && !$ctx->isActionRequest
                && !str_starts_with(trim($ctx->uri, '/'), 'cpresources')
            ) {
                try {
                    Craft::$app->getView()->registerAssetBundle(HydrateAsset::class);
                } catch (\Throwable $e) {
                    Craft::warning("Edge could not register the hydrate asset: {$e->getMessage()}", __METHOD__);
                }
            }

            if ($decision->cacheable) {
                $siteUri = $this->cacheability->getCacheSiteUri($ctx, $settings);
                $this->generator->start($siteUri);

                Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
                    /** @var Response $response */
                    $response = $event->sender;
                    $this->generator->save($response);
                }, append: false);

                return;
            }

            // Not cacheable: make sure no edge tier stores it. Cookies pass through
            // untouched. This is where sessions, logins and mutations live.
            Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) use ($ctx) {
                // Craft's resource requests manage their own cache headers.
                if (str_starts_with(trim($ctx->uri, '/'), 'cpresources')) {
                    return;
                }

                /** @var Response $response */
                $response = $event->sender;
                $this->getDriver()->prepareResponse($response, false);
            }, append: false);
        });
    }

    /**
     * Element-change signals: Craft's own cache invalidation tags (primary), plus
     * save/delete/restore/structure events (belt and braces).
     */
    private function registerElementEvents(): void
    {
        Event::on(Elements::class, Elements::EVENT_INVALIDATE_CACHES,
            function(InvalidateElementCachesEvent $event) {
                $this->invalidator->addInvalidatedTags($event->tags ?? [], $event->element);
            }
        );

        $elementEvents = [
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            Elements::EVENT_AFTER_RESAVE_ELEMENT,
            Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI,
            Elements::EVENT_AFTER_RESTORE_ELEMENT,
        ];

        foreach ($elementEvents as $eventName) {
            Event::on(Elements::class, $eventName,
                function(ElementEvent|MultiElementActionEvent $event) {
                    $this->invalidator->addElement($event->element);
                }
            );
        }

        // Capture deletes BEFORE the element row disappears, so the ID map still resolves.
        Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function(DeleteElementEvent $event) {
                $this->invalidator->addElement($event->element);
            }
        );

        $structureEvent = defined(Structures::class . '::EVENT_AFTER_UPDATE_ELEMENT')
            ? Structures::EVENT_AFTER_UPDATE_ELEMENT
            : Structures::EVENT_AFTER_MOVE_ELEMENT;

        Event::on(Structures::class, $structureEvent,
            function(MoveElementEvent $event) {
                $this->invalidator->addElement($event->element);
            }
        );
    }

    /**
     * Coarse triggers that defeat precise resolution -> flush the whole managed tier.
     */
    private function registerCoarseFlushEvents(): void
    {
        Event::on(Plugins::class, Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
            function(PluginEvent $event) {
                $this->invalidator->requestCoarseFlush("plugin settings saved ({$event->plugin->handle})");
            }
        );

        foreach ([
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            Plugins::EVENT_AFTER_UNINSTALL_PLUGIN,
            Plugins::EVENT_AFTER_ENABLE_PLUGIN,
            Plugins::EVENT_AFTER_DISABLE_PLUGIN,
        ] as $eventName) {
            Event::on(Plugins::class, $eventName,
                function(PluginEvent $event) {
                    // Installing Edge itself creates no stale cache, so skip the noise.
                    if ($event->plugin->handle !== $this->handle) {
                        $this->invalidator->requestCoarseFlush("plugin {$event->plugin->handle} changed");
                    }
                }
            );
        }
    }

    private function registerCpComponents(): void
    {
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = EdgeUtility::class;
            }
        );

        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event) {
                $event->options[] = [
                    'key' => 'edge-cache',
                    'label' => 'Edge cache',
                    'action' => function() {
                        Craft::$app->getDb()->createCommand()->delete(Table::CACHES)->execute();
                        $this->getDriver()->flushAll();
                    },
                ];
            }
        );
    }

    private function registerSiteUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['edge/csrf'] = 'edge/dynamic/csrf';
                $event->rules['edge/island'] = 'edge/dynamic/island';
            }
        );
    }
}
