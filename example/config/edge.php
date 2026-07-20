<?php
/**
 * Edge plugin config overrides.
 *
 * Copy this file to your project's `config/` folder as `edge.php`. Any key set here
 * overrides the value saved in the plugin's CP settings. Secrets must come from
 * environment variables. Never commit them.
 */

use craft\helpers\App;

return [
    // Master switch for edge caching.
    'enabled' => true,

    // The ONE managed edge tier: 'nginx-static' | 'nginx-fastcgi' | 'cloudflare'.
    // Whichever tier is not managed must be configured pass-through (see README).
    'driver' => 'nginx-static',

    // Environments (CRAFT_ENVIRONMENT) in which caching is active.
    // null = cache everywhere except when devMode is on.
    // A list (e.g. ['production', 'staging']) overrides the devMode skip for those envs.
    'cacheableEnvironments' => null,

    // If non-empty, ONLY URIs matching one of these regex patterns are cached.
    'includedUriPatterns' => [],

    // URIs matching one of these regex patterns are NEVER cached. Always wins.
    'excludedUriPatterns' => [],

    // 'ignore' = query strings are stripped from the cache key (?utm_source=x shares the
    // clean URL's entry). 'respect' = each unique allowed query string is its own entry.
    'queryStringCaching' => 'ignore',

    // Query params that never affect the cache key (trailing * wildcard supported).
    'excludedQueryStringParams' => ['utm_*', 'gclid', 'fbclid', '_ga', 'mc_cid', 'mc_eid'],

    // If non-empty, ONLY these params affect the cache key (allowlist; exclusions win;
    // trailing * wildcard supported). Empty = every non-excluded param counts.
    'includedQueryStringParams' => [],

    // Site IDs that are never cached (multi-site opt-out).
    'excludedSiteIds' => [],

    // Cookie names (exact) or suffixes whose presence forces a live, un-shared render.
    // The plugin default is [] (empty): every visitor, logged in or not, is served the
    // shared shell, and personal content (the account menu, the greeting) hydrates
    // client-side through the island/CSRF endpoints. Craft's identity cookies are
    // deliberately NOT listed; a signed-in visitor gets cache HITs like everyone else.
    // The example keeps one demo cookie to show the opt-in escape hatch: send
    // `Cookie: edge_bypass=1` and every tier renders live (it is also listed in the
    // nginx cookie map, nginx/default.conf).
    // NOTE: CraftSessionId and CRAFT_CSRF_TOKEN must NOT be added here. The anonymous
    // session/CSRF cookies are deliberately ignored by the cache.
    'bypassCookies' => ['edge_bypass'],

    // nginx-static driver: where rendered HTML files are written. Outside the web root,
    // so cache files are never addressable by URL; nginx serves them through an internal
    // location (docs/nginx-static.conf). Must match the cache root in your nginx config.
    // Supports @aliases and $ENV_VAR references.
    'cachePath' => '@storage/edge-cache',

    // nginx-fastcgi driver: base URL of the ngx_cache_purge location; the URI to purge is
    // appended, e.g. 'http://127.0.0.1/edge-purge'. Requires the ngx_cache_purge module.
    'fastCgiPurgeUrl' => null,

    // Cloudflare driver credentials, ALWAYS from env vars.
    'cloudflareApiToken' => App::env('CLOUDFLARE_API_TOKEN'),
    'cloudflareZoneId' => App::env('CLOUDFLARE_ZONE_ID'),

    // Purge by Cache-Tag + emit Cache-Tag headers (Cloudflare Enterprise only).
    'cloudflareUsesCacheTags' => false,

    // Max URLs per Cloudflare purge API request (API limit: 30 on all plans).
    'cloudflarePurgeChunkSize' => 30,

    // max-age for `Cache-Control: public, max-age=...` on cacheable responses.
    // Long by default: correctness comes from purging, not TTL expiry.
    // Shared-cache TTL, sent as `s-maxage`. Browsers always get `max-age=0,
    // must-revalidate`: a purge reaches the edge tier but never a visitor's browser.
    'cacheControlTtl' => 31536000,

    // Signed-in visits warm the same shared page as anonymous ones. Safe here because
    // every per-visitor fragment in this example is an island, so the shell never
    // depends on who is looking at it. Edge additionally refuses to store a response
    // containing the signed-in user's email, username or full name.
    'cacheLoggedInRenders' => true,

    // Re-warm purged URLs automatically (queued WarmJob after each purge).
    'warmCacheAutomatically' => true,

    // Concurrent requests used by the cache warmer.
    'concurrency' => 5,

    // Automatically register the hydration script (CSRF injection + islands) on cacheable pages.
    'autoInjectHydrationScript' => true,

    // Whether the uncached `edge/csrf` endpoint is enabled.
    'csrfEndpointEnabled' => true,

    // Site template path prefix that `edge/island?name=x` renders from
    // (island 'cart' renders the site template '_edge/islands/cart').
    'islandsTemplatePath' => '_edge/islands',
];
