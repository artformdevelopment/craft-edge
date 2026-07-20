# Configuration reference

Every setting can be managed in **Settings -> Plugins -> Edge**, or set in `config/edge.php`.
This page explains what each one does, when you'd change it, and its edge cases. For the
"why" behind the cookie and cache-key settings, read [How Edge works](how-it-works.md)
first.

- [How overrides work](#how-overrides-work)
- [Settings at a glance](#settings-at-a-glance)
- [General](#general)
- [What gets cached (URIs, environments, sites)](#what-gets-cached)
- [Query strings & the cache key](#query-strings--the-cache-key)
- [Cookies & sessions](#cookies--sessions)
- [Driver settings](#driver-settings)
- [Headers & TTL](#headers--ttl)
- [Warming & hydration](#warming--hydration)
- [The full decision order](#the-full-decision-order)

## How overrides work

Precedence, highest first:

1. **`config/edge.php`**: a key here overrides everything. The CP field becomes read-only
   with an "overridden" note. Use this for anything environment-specific or secret.
2. **Control-panel settings**: stored in project config / the database, edited in the CP.
3. **Built-in defaults**: what you get if you set nothing.

Environment variables and Craft aliases are supported wherever a value is a path or a
secret: `cachePath`, `fastCgiPurgeUrl`, `cloudflareApiToken`, `cloudflareZoneId` all resolve
`$ENV_VAR` and `@alias` references at read time. This is the Craft-standard way to keep
per-environment values out of the database, e.g. `'cachePath' => '$EDGE_CACHE_PATH'`.

List-type settings (URI patterns, cookies, params, site IDs) are edited as editable tables
in the CP and as plain PHP arrays in the config file. The CP form also accepts
comma-separated strings and normalizes them.

## Settings at a glance

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` | Master on/off switch. |
| `driver` | `'nginx-static'` | The one managed tier: `nginx-static` \| `nginx-fastcgi` \| `cloudflare`. |
| `cacheableEnvironments` | `null` | Environments where caching runs. `null` = everywhere except devMode. |
| `includedUriPatterns` | `[]` | If set, cache **only** URIs matching these regexes. |
| `excludedUriPatterns` | `[]` | Never cache URIs matching these regexes (bypass routes). Always wins. |
| `excludedSiteIds` | `[]` | Site IDs to never cache (multi-site opt-out). |
| `queryStringCaching` | `'ignore'` | `ignore` = strip query from the key; `respect` = per-query entries. |
| `excludedQueryStringParams` | `['utm_*','gclid','fbclid','_ga','mc_cid','mc_eid']` | Params that never affect the key. |
| `includedQueryStringParams` | `[]` | If set, **only** these params affect the key (allowlist). |
| `bypassCookies` | `[]` | Cookie names/suffixes that force a live, un-shared render. |
| `cachePath` | `'@storage/edge-cache'` | nginx-static: where HTML files are written (outside the web root). |
| `fastCgiPurgeUrl` | `null` | nginx-fastcgi: the ngx_cache_purge location URL. |
| `cloudflareApiToken` | `App::env('CLOUDFLARE_API_TOKEN')` | Cloudflare token (env var). |
| `cloudflareZoneId` | `App::env('CLOUDFLARE_ZONE_ID')` | Cloudflare zone ID (env var). |
| `cloudflareUsesCacheTags` | `false` | Purge by Cache-Tag (Enterprise only). |
| `cloudflarePurgeChunkSize` | `30` | Max URLs per Cloudflare purge request. |
| `cacheControlTtl` | `31536000` | `max-age` on cacheable responses (1 year). |
| `warmCacheAutomatically` | `true` | Re-warm purged URLs automatically. |
| `concurrency` | `5` | Parallel requests used by the warmer. |
| `autoInjectHydrationScript` | `true` | Auto-add `edge-hydrate.js` on front-end pages. |
| `csrfEndpointEnabled` | `true` | Serve the uncached `edge/csrf` endpoint. |
| `islandsTemplatePath` | `'_edge/islands'` | Template prefix for `edge/island?name=x`. |

## General

### `enabled`
`bool`, default `true`. The master switch. `false` means nothing is evaluated, stored, or
served from the cache: every response is dynamic. Toggle this to disable caching without
tearing down your nginx/Cloudflare config. (The edge tier can still serve already-stored
copies until they're purged; run `./craft edge/cache/clear` if you want a clean slate.)

### `driver`
`string`, default `'nginx-static'`. The single managed tier, one of `nginx-static`,
`nginx-fastcgi`, `cloudflare`. Changing it changes how storing, purging, and verifying
work; the [driver pages](driver-nginx-static.md) cover each. **Remember the golden rule:**
whichever tier you *don't* choose must not cache HTML.

## What gets cached

These control which requests are even considered. They're checked in a specific order (see
[the full decision order](#the-full-decision-order)), but the mental model is: *exclude
always wins, then (if you set an include list) the URI must be on it.*

### `excludedUriPatterns`
`string[]`, default `[]`. URIs matching any of these regular expressions are **never**
cached. This is where **bypass routes** go: account pages, carts, checkout, search results,
anything personalized or sensitive.

Patterns are regular expressions matched against the URI **without** leading/trailing
slashes:

- `''` (empty string) matches the **homepage** only.
- `'*'` matches **everything**.
- `'account'` matches any URI containing "account" (it's a regex, not an anchored match).
- `'^account'` matches URIs that **start with** "account" (`account`, `account/orders`).
- `'^cart$'` matches **exactly** `cart`.
- `'^checkout(/|$)'` matches `checkout` and anything under it.

```php
'excludedUriPatterns' => [
    '^account',
    '^cart',
    '^checkout',
    '^my/.*',
    'search',      // any URL containing "search"
],
```

> Excluded pages still render normally; they're just always dynamic and marked
> `private, no-store`. Cookies pass through them untouched, so they're the safe home for
> anything per-visitor that *isn't* an [island](templating.md).

### `includedUriPatterns`
`string[]`, default `[]`. If **non-empty**, ONLY URIs matching one of these are cached;
everything else is dynamic. Same pattern syntax as above. Leave empty (the default) to cache
everything that isn't excluded, the usual choice. Use an include list only when you want to
cache a small, known set of URLs and leave the rest dynamic (e.g. cache only `^$` and
`^blog`).

If both lists are set, **exclude is checked first and wins.**

### `cacheableEnvironments`
`string[]|null`, default `null`. Which `CRAFT_ENVIRONMENT` values cache:

- `null` (default): cache in **any** environment, **except** when `devMode` is on. This
  is what you want in most projects: production and staging cache; local dev (devMode on)
  doesn't.
- A list, e.g. `['production', 'staging']`: cache **only** in those environments. Note a
  non-empty list **overrides the devMode skip**: if `production` is listed, it caches even
  if someone left devMode on there.

### `excludedSiteIds`
`int[]`, default `[]`. In a multi-site install, site IDs that should never be cached.
Everything served for those sites stays dynamic. Useful for a headless/preview site, or a
low-traffic site where caching isn't worth it.

## Query strings & the cache key

By default the URL path alone is the cache key and query strings are ignored, so
`/blog`, `/blog?utm_source=x`, and `/blog?ref=twitter` all share **one** cached copy. That's
usually right: most query strings are tracking noise, and caching per-query fragments your
cache badly. Change this only when a query string genuinely produces different content
(pagination, filters).

### `queryStringCaching`
`string`, default `'ignore'`.

- `'ignore'`: query strings are **stripped from the cache key**. Every variation of a URL's
  query string maps to the same cached page. Best hit rate; correct for tracking params.
- `'respect'`: each unique **allowed** query string is its **own** cache entry. Use this
  when `?page=2` or `?color=blue` should be a different page. Marketing params
  (`excludedQueryStringParams`) are still stripped even here, so `?page=2&utm_source=x`
  shares `?page=2`'s entry.

> On the `nginx-static` driver, `respect` mode needs extra nginx config to serve per-query
> files, see the note in [`nginx-static.conf`](nginx-static.conf). `nginx-fastcgi` and
> `cloudflare` handle per-query keys at the tier natively.

### `excludedQueryStringParams`
`string[]`, default `['utm_*', 'gclid', 'fbclid', '_ga', 'mc_cid', 'mc_eid']`. Params that
**never** affect the cache key (in `respect` mode): tracking and campaign params. A
trailing `*` is a prefix wildcard, so `utm_*` covers `utm_source`, `utm_medium`, etc. In
`ignore` mode this list doesn't matter (everything is stripped). On Cloudflare, `utm_*`
is expanded to the five standard `utm_` params when writing the cache rule.

### `includedQueryStringParams`
`string[]`, default `[]`. The inverse allowlist. If non-empty, **only** these params affect
the key (in `respect` mode); every other param is treated like a tracking param and
stripped. `excludedQueryStringParams` still wins: a param on both lists is excluded.
Trailing `*` wildcard supported. Use this when you know the exact handful of params that
matter (`page`, `sort`) and want everything else ignored.

Two of Craft's own params, `p` (the path param) and `token`, are **always** excluded from
the key regardless of these settings.

## Cookies & sessions

The one setting here is load-bearing. Read
[the cookie model](how-it-works.md#the-cookie-model-the-important-part) before changing it.

### `bypassCookies`
`string[]`, default `[]`. Cookie names, matched **exactly** or by **suffix**, whose
presence on a request forces a **live, un-shared render** at every tier. The default is
empty on purpose: the shared shell is safe to serve to everyone, signed in or not, because
per-visitor content lives in [islands](templating.md#islands-per-visitor-content), so no
cookie needs to bypass. This is the opt-in escape hatch for a *whole visit* that genuinely
must never be answered from the shared copy:

```php
'bypassCookies' => [
    'commerce_cart',   // a live-cart cookie: this visitor's pages must render live
    'edge_bypass',     // your own "make this dynamic" cookie
],
```

Three rules to keep in mind:

- **Never add `CraftSessionId` or the CSRF cookie here.** Those are anonymous; every
  visitor has them, and Edge deliberately ignores them. It even guards against this
  misconfiguration internally (anonymous cookies never bypass even if listed), but don't
  rely on that; keep them off the list.
- **Don't add Craft's login cookies either.** Signed-in visitors are meant to be served
  the shared shell (their renders aren't *stored* unless you opt into
  [`cacheLoggedInRenders`](#cacheloggedinrenders); see
  [the cookie model](how-it-works.md#the-cookie-model-the-important-part)). Bypassing them
  would turn the cache off for exactly the visitors who use the site most; personal
  content belongs in islands instead.
- **When you add a bypass cookie, add it to the nginx bypass map too** (for the nginx
  drivers) so the tier bypasses on it as well, otherwise nginx would serve a cached copy to
  a visitor the plugin intended to bypass. On Cloudflare, re-run `edge/cloudflare/setup` so
  the bypass rule is rebuilt (the rule exists only while at least one bypass cookie is
  configured). The [driver pages](driver-nginx-static.md) show where.

## Driver settings

Only the settings for your active driver apply.

### `cachePath` (nginx-static)
`string`, default `'@storage/edge-cache'`. Where rendered HTML files are written. The
default sits **outside the web root**, so a cache file is never addressable by any direct
URL; nginx serves hits from it through the internal `@edge` location in
[`nginx-static.conf`](nginx-static.conf), and the two paths **must match**. Supports
`@aliases` and `$ENV_VARS`. Required: the plugin won't let you blank it.

### `fastCgiPurgeUrl` (nginx-fastcgi)
`string|null`, default `null`. The base URL of the `ngx_cache_purge` location, e.g.
`http://127.0.0.1/edge-purge`. Edge appends the URI to purge. Point it at localhost.
Supports `$ENV_VAR`. Required for the fastcgi driver: purges fail with a clear error if
it's unset.

### `cloudflareApiToken` / `cloudflareZoneId` (cloudflare)
`string|null`, default from `App::env(...)`. Cloudflare credentials, **always** from
environment variables. See the [Cloudflare page](driver-cloudflare.md#step-1-prepare-the-environment-credentials).

### `cloudflareUsesCacheTags` (cloudflare)
`bool`, default `false`. Switch purging from URLs to `Cache-Tag`. **Enterprise only**:
leave `false` on all other plans. See
[tag-based purging](driver-cloudflare.md#enterprise-tag-based-purging).

### `cloudflarePurgeChunkSize` (cloudflare)
`int`, default `30`. Max URLs per purge API request. `30` is the documented API limit on
all plans; there's rarely a reason to change it (lower it only if you hit request-size
limits with very long URLs).

## Headers & TTL

### `cacheControlTtl`
`int` seconds, default `31536000` (one year). The **shared-cache** TTL. Cacheable responses
are sent as:

```
Cache-Control: public, s-maxage=<cacheControlTtl>, max-age=0, must-revalidate
```

`s-maxage` is honoured by shared caches — nginx, Cloudflare — and ignored by browsers.
`max-age=0, must-revalidate` sends browsers back to the edge on every navigation.

That split is deliberate, and it is the only correct one. Edge keeps content correct by
**purging**, so a long shared TTL costs nothing: a purge reaches the edge tier immediately.
A purge can never reach a visitor's browser. Sending a long `max-age` would strand stale
HTML on every device that had already loaded the page, with no way to recall it short of
changing the URL.

Revalidation is cheap. Under `nginx-static` the conditional request is answered from the
static file with a `304` and never reaches PHP; under `nginx-fastcgi` and `cloudflare` it is
answered by the tier.

## Warming & hydration

### `warmCacheAutomatically`
`bool`, default `true`. After purging a set of URLs, queue a **warm** job that re-requests
them (cookie-free, anonymous) so the next real visitor gets a hit instead of paying for the
re-render. Turn it off if you'd rather let traffic re-populate the cache lazily (lower
origin load right after big edits, at the cost of some misses).

### `concurrency`
`int`, default `5`. How many URLs the warmer requests in parallel. Raise it to warm large
purges faster; lower it if warming spikes your origin load too hard.

### `autoInjectHydrationScript`
`bool`, default `true`. Automatically register `edge-hydrate.js` on front-end GET pages,
including bypassed and logged-in pages, so forms hydrate everywhere. Set `false` only if
you want to include the script yourself (e.g. bundle it), or you have no forms/islands and
want to skip the ~2 KB. See [Templating](templating.md#the-hydration-script).

### `csrfEndpointEnabled`
`bool`, default `true`. Whether `edge/csrf` responds. Leave it on if you have **any** forms
on cacheable pages; it's what lets those forms get a valid token. Turning it off makes the
endpoint 404 and disables CSRF hydration; only do that if no cacheable page ever contains a
Craft form.

### `cacheLoggedInRenders`
`bool`, default `false`. Whether a render for a **signed-in** visitor may be stored in the
shared cache.

Signed-in visitors are already *served* the shared file (see
[the cookie model](how-it-works.md#the-cookie-model-the-important-part)). This setting
controls the other half: whether their visits also *populate* it. Left off, only an
anonymous visitor — or the cookie-free warmer — ever warms a page, so a page browsed solely
by signed-in staff stays uncached.

Turn it on only when your shell is identity-independent: every per-visitor fragment is an
[island](templating.md#islands-per-visitor-content), and nothing in the cacheable shell
branches on `currentUser`, customer group, or permission-scoped element queries.

```php
'cacheLoggedInRenders' => true,
```

Edge backs this with a containment check: a response containing the signed-in user's email,
username or full name is refused and logged with the field that matched (never the value).
Treat that as a smoke alarm, not a proof. It cannot see:

- customer-group or catalog pricing that differs per visitor,
- an "edit this entry" link or other permission-gated markup,
- elements returned to one visitor and not another.

Verify before enabling: load a page signed in, fetch the same URL cookie-free, and diff.

### `islandsTemplatePath`
`string`, default `'_edge/islands'`. The site-template folder that `edge/island?name=x`
renders from. `{{ edgeIsland('cart') }}` -> renders `templates/_edge/islands/cart.twig` for
the current visitor. Change it if you keep island templates elsewhere. See
[Templating: islands](templating.md#islands-per-visitor-content).

## The full decision order

For debugging, here's the exact order `Cacheability::evaluateRequest` runs. The **first**
rule that matches decides, and the reason is what you'll see in logs and `verify` output. A
request is cached only if it survives all of them.

1. `enabled` is `false` -> skip.
2. Console request, or not a site request -> skip.
3. Control-panel request -> skip.
4. Method isn't `GET`/`HEAD` -> skip.
5. Action request -> skip.
6. Preview / live-preview request -> skip.
7. Carries a token (`token` param, or `X-Craft-Token`) -> skip.
8. A user is logged in, and [`cacheLoggedInRenders`](#cacheloggedinrenders) is `false`
   -> skip **storing** (their live render isn't persisted; the edge tier still serves them
   the shared anonymous file when one exists).
9. Environment not cacheable (see `cacheableEnvironments` / devMode) -> skip.
10. A [bypass cookie](#bypasscookies) is present -> skip.
11. Site ID is in `excludedSiteIds` -> skip.
12. `?no-cache` param is present -> skip (a debugging aid: request any page with
    `?no-cache=1` to force a fresh render).
13. The request `Host` doesn't match the site's configured base-URL host -> skip. The
    stored file is keyed by the site's own host, but Craft renders absolute URLs from the
    request `Host`, so storing a response that arrived on another host would write its URLs
    into the canonical entry. Such requests still render normally, they're just never
    stored. (Reject them at the edge too: see
    [reverse proxy](reverse-proxy.md).)
14. `queryStringCaching` is `ignore` and the request carries a query param that isn't in
    [`excludedQueryStringParams`](#excludedquerystringparams) -> skip. In `ignore` mode the
    query string is dropped from the cache key, so storing `/shop?brand=x` would write the
    filtered page over the entry for plain `/shop`. Marketing params (`utm_*`, `gclid`, …)
    are excluded from the key by design and still cache. Use `respect` mode if a param
    should produce its own entry.
15. URI is `edge` or under `edge/` -> skip (the plugin's own endpoints).
16. URI matches `excludedUriPatterns` -> skip.
17. `includedUriPatterns` is set and the URI doesn't match -> skip.
18. Otherwise -> **cache**.

Four response-side rules then apply at store time. A response is never stored if it:

- isn't a **200**,
- still carries **`Set-Cookie`** after the strip layer,
- contains a **rendered CSRF token** (an input named after `csrfTokenName` with a non-empty
  value) — see [templating](templating.md#forms--csrf-on-cached-pages),
- contains the signed-in user's **email, username or full name**, when
  [`cacheLoggedInRenders`](#cacheloggedinrenders) is on.

Each is logged and the response is downgraded to `private, no-store`. In `devMode`,
non-cacheable responses also carry an `X-Edge-Skip-Reason` header naming the rule that
matched, so you can see why a page didn't cache without reading logs.

Next: **[Templating for the cache](templating.md)**, the page that turns all of this into
working templates.
