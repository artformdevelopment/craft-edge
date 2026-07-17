# Driver: Cloudflare

Cloudflare caches your HTML at its global edge, so hits are answered by the Cloudflare
data centre nearest the visitor: the request often never reaches your server at all. There
is no local storage: Cloudflare stores a page because the **origin** tells it to, via
`Cache-Control: public, max-age=...`, which Edge already sends on cacheable responses. Edge's
job here is to (1) write the zone's cache rules so Cloudflare caches HTML the right way, and
(2) purge through Cloudflare's API when content changes.

- [How it works](#how-it-works)
- [Step 1: prepare the environment (credentials)](#step-1-prepare-the-environment-credentials)
- [Step 2: connect Edge to it](#step-2-connect-edge-to-it)
- [Step 3: create the cache rules](#step-3-create-the-cache-rules)
- [Step 4: verify](#step-4-verify)
- [Purging](#purging)
- [Enterprise: tag-based purging](#enterprise-tag-based-purging)
- [Keep nginx pass-through](#keep-nginx-pass-through)
- [The browser-TTL caveat](#the-browser-ttl-caveat)

## How it works

1. Anonymous request -> Cloudflare has no copy -> it forwards to your origin -> Craft renders ->
   Edge sends the page cookie-free with `public, max-age=...` -> **Cloudflare stores it**.
2. Next anonymous request anywhere in the world -> Cloudflare serves its edge copy with
   `CF-Cache-Status: HIT`. Your origin isn't touched.
3. On a content change -> Edge calls Cloudflare's **purge API** to evict the affected URLs.

Cloudflare refuses to cache responses that carry `Set-Cookie`, which is exactly why Edge's
cookie-strip layer matters here: without it, every page would be a permanent `MISS`. And
because the cache rule uses `edge_ttl: respect_origin`, pages the origin marks
`private, no-store` (logins, bypassed pages, mutations) are **never** stored by Cloudflare,
no override.

## Step 1: prepare the environment (credentials)

You need two values from Cloudflare, and they go in **environment variables**, never
committed to `config/edge.php` as literals.

1. **Zone ID**: Cloudflare dashboard -> your domain -> **Overview** -> API section (right
   sidebar).
2. **API token**: dashboard -> **My Profile -> API Tokens -> Create Token**. Scope it to the
   one zone, with these permissions:
   - **Zone -> Cache Purge -> Purge** (required, for purging).
   - **Zone -> Zone Settings -> Edit** and **Zone -> Config Rules -> Edit** (only needed if you
     want Edge to write the cache rules for you via `edge/cloudflare/setup`; you can also
     create the rules by hand and skip these).

Put them in your environment (e.g. `.env`):

```bash
CLOUDFLARE_ZONE_ID=your_zone_id
CLOUDFLARE_API_TOKEN=your_scoped_token
```

## Step 2: connect Edge to it

The reference `config/edge.php` already reads these from the environment; that's the
correct, secrets-safe pattern:

```php
// config/edge.php
use craft\helpers\App;

return [
    'driver'             => 'cloudflare',
    'cloudflareApiToken' => App::env('CLOUDFLARE_API_TOKEN'),
    'cloudflareZoneId'   => App::env('CLOUDFLARE_ZONE_ID'),
];
```

> **Never** hard-code the token. Keeping it in an env var means it stays out of version
> control, out of the database, and out of project config. Edge parses `$ENV_VAR`-style
> references at read time.

## Step 3: create the cache rules

Cloudflare won't cache HTML the way Edge needs until the zone has the right cache rules.
Edge can write them for you, idempotently:

```bash
./craft edge/cloudflare/setup
```

This **mutates your zone** (which is why it's an explicit command and never runs on
install). It PUTs Edge's ordered rules into the zone's `http_request_cache_settings`
phase, replacing any previous Edge rules and preserving everything else:

1. **Bypass on opt-in cookie** (first, so it wins; written **only when `bypassCookies` is
   non-empty**, which it isn't by default): an expression like
   `(http.cookie contains "cart_cookie" or http.cookie contains "edge_bypass")` built from
   your `bypassCookies`, with `cache: false`. Visitors carrying an opt-in bypass cookie
   never touch the Cloudflare cache. Login cookies are deliberately not part of any rule:
   signed-in visitors are served the shared cached shell and personalize client-side
   through the island endpoints.
2. **Cache HTML, respect origin**: `(http.request.method eq "GET")` with `cache: true`,
   `edge_ttl: respect_origin`, and a cache key that **ignores query-string order** and
   **excludes the marketing params** (`utm_*`, `gclid`, `fbclid`, `_ga`, `mc_cid`,
   `mc_eid`). Cookies are never part of the key. With the default empty `bypassCookies`,
   this is the only rule written.

Prefer to do it by hand? Create the same rule(s) yourself in **Rules -> Cache Rules** with
the same settings, then you don't need the Zone-Settings/Config-Rules token permissions.

## Step 4: verify

```bash
./craft edge/cloudflare/verify --url=https://your-site.com/
```

Expected:

```
GET #1: HTTP 200 CF-Cache-Status: MISS
GET #2: HTTP 200 CF-Cache-Status: HIT
Set-Cookie on cached response: none (correct)
Cloudflare verification PASSED.
```

If `CF-Cache-Status` is `(missing)`, the host isn't proxied through Cloudflare (grey cloud ->
switch to orange), or something in front of PHP added an `Age`/cache header that isn't
Cloudflare. If it's stuck on `MISS`/`DYNAMIC`, the cache rule isn't in place or the origin
isn't sending `public, max-age`: re-run setup and confirm the page is actually cacheable.

## Purging

- **By URL (all plans, the default).** Edge sends the affected absolute URLs to the purge
  API in batches of **<=30 per request** (the API limit on every plan). Batching, and
  retry-with-backoff on `429`/`5xx`, are handled by the queue job: a rate-limited purge is
  retried, never dropped, and never blocks the editor's save.
- **Coarse flush** (global set change, plugin change) issues a single
  `purge_everything` for the zone.

## Enterprise: tag-based purging

On **Cloudflare Enterprise**, you can switch to `Cache-Tag` purging:

```php
'cloudflareUsesCacheTags' => true,
```

With this on, every cached page emits a unique per-page `Cache-Tag` header, and purges send
`{"tags": [...]}` instead of URLs. This is exact and avoids the 30-URL batching entirely.

> **Enterprise only.** `Cache-Tag` request headers and tag purges are ignored/rejected on
> non-Enterprise plans, so leave this `false` unless you're certain. On lower plans, URL
> purging is the correct mode.

## Keep nginx pass-through

In `cloudflare` mode, **nginx must not cache HTML**: Cloudflare is the one HTML cache, and
Edge only purges Cloudflare. So don't enable `fastcgi_cache` for HTML and don't install the
Edge `try_files` block. Let PHP render HTML straight through to Cloudflare. (Static assets
cached by nginx are fine, the concern is only HTML.) `edge/cloudflare/verify` will warn if
it sees a response with an `Age`/`X-Edge-Cache` header but no `CF-Cache-Status`, which is
the signature of a second cache in front of PHP.

## The browser-TTL caveat

`cacheControlTtl` (default one year) is the `max-age` Cloudflare sees, **and so does the
visitor's browser.** Edge can purge Cloudflare's copy, but it cannot reach into a visitor's
browser cache. For most sites this is fine (a returning visitor getting slightly stale HTML
for a few minutes is harmless), but if long browser-side HTML caching worries you, either:

- add a **Browser TTL** override in the Cache Rule (`browser_ttl`) to keep the *browser*
  cache short while the *edge* cache stays long, or
- lower `cacheControlTtl`.

Because correctness comes from **purging**, not from expiry, a shorter TTL doesn't hurt
hit rate at the edge; it only changes how long browsers hold their private copy.

Next: [Configuration reference](configuration.md) | [Templating for the cache](templating.md).
