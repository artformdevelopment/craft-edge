<?php
/**
 * @copyright Copyright (c) ArtformDev
 * @license https://craftcms.github.io/license/ Craft License
 */

namespace artformdev\edge\models;

use Craft;
use craft\web\Request;

/**
 * A plain snapshot of the current request, so cacheability logic is testable without Craft.
 */
final class RequestContext
{
    /**
     * @param array<string, string> $cookies cookie name => value
     * @param array<string, mixed> $queryParams
     * @param string[] $anonymousCookieNames session/CSRF cookie names that must never bypass
     */
    public function __construct(
        public string $method = 'GET',
        public bool $isSiteRequest = true,
        public bool $isCpRequest = false,
        public bool $isActionRequest = false,
        public bool $isConsoleRequest = false,
        public bool $isPreview = false,
        public bool $hasToken = false,
        public bool $isLoggedIn = false,
        public bool $devMode = false,
        public ?string $environment = null,
        public int $siteId = 1,
        public string $uri = '',
        public array $queryParams = [],
        public array $cookies = [],
        public array $anonymousCookieNames = ['CraftSessionId', 'CRAFT_CSRF_TOKEN', 'PHPSESSID'],
        public ?string $host = null,
        public ?string $siteHost = null,
    ) {
    }

    /**
     * Builds a context from the current Craft web request.
     */
    public static function fromCurrentRequest(): self
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof Request) {
            return new self(isConsoleRequest: true, isSiteRequest: false);
        }

        $generalConfig = Craft::$app->getConfig()->getGeneral();

        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            $cookies[(string)$name] = is_string($value) ? $value : '';
        }

        try {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        } catch (\Throwable) {
            $siteId = 1;
        }

        return new self(
            method: $request->getMethod(),
            isSiteRequest: $request->getIsSiteRequest(),
            isCpRequest: $request->getIsCpRequest(),
            isActionRequest: $request->getIsActionRequest(),
            isConsoleRequest: false,
            isPreview: $request->getIsPreview() || $request->getIsLivePreview()
                || $request->getQueryParam('x-craft-preview') !== null
                || $request->getQueryParam('x-craft-live-preview') !== null,
            hasToken: $request->getToken() !== null
                || $request->getQueryParam($generalConfig->tokenParam) !== null
                || $request->getHeaders()->get('X-Craft-Token') !== null,
            isLoggedIn: !Craft::$app->getUser()->getIsGuest(),
            devMode: Craft::$app->getConfig()->getGeneral()->devMode,
            environment: Craft::$app->env,
            siteId: $siteId,
            uri: $request->getFullUri(),
            queryParams: $request->getQueryParams(),
            cookies: $cookies,
            anonymousCookieNames: array_values(array_filter([
                $generalConfig->phpSessionName,
                $generalConfig->csrfTokenName,
                'PHPSESSID',
            ])),
            host: $request->getHostName(),
            siteHost: SiteUri::hostForSite($siteId),
        );
    }
}
