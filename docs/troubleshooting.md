# Troubleshooting

How to diagnose the handful of things that go wrong with full-page caching, and how to read
the headers Edge leaves behind. Most problems fall into three buckets: *it's not caching*,
*it cached the wrong thing*, or *forms/islands aren't working*.

- [Reading the headers](#reading-the-headers)
- [It's not caching](#its-not-caching)
- [It cached something it shouldn't have](#it-cached-something-it-shouldnt-have)
- [Stale pages after an edit](#stale-pages-after-an-edit)
- [Forms are rejected / CSRF errors](#forms-are-rejected--csrf-errors)
- [Islands don't appear](#islands-dont-appear)
- [Cloudflare-specific](#cloudflare-specific)
- [Where to look next](#where-to-look-next)

## Reading the headers

Every diagnosis starts with `curl -I`. The markers Edge and the tiers set:

| Header | Meaning |
| --- | --- |
| `X-Edge-Origin: 1` | **PHP rendered this response.** Present on every dynamic/miss/bypassed page; absent on a `nginx-static` hit. |
| `X-Edge-Cache: HIT` / `MISS` | `nginx-fastcgi` cache status (from `$upstream_cache_status`). |
| `CF-Cache-Status: HIT` / `MISS` / `DYNAMIC` | Cloudflare's cache status. |
| `Cache-Control: public, max-age=...` | This response is cacheable (cookie-free). |
| `Cache-Control: private, no-store` | This response is **not** cached: dynamic, bypassed, or an endpoint. |
| `Set-Cookie: ...` | Cookies are being set: correct on dynamic pages, a **leak** if seen on a cached hit. |

The essential two-request test (request the same URL twice, compare):

```bash
curl -sSI https://your-site.com/ | grep -i -E 'x-edge|cf-cache|cache-control|set-cookie'
curl -sSI https://your-site.com/ | grep -i -E 'x-edge|cf-cache|cache-control|set-cookie'
```

Second request should show the hit marker (no `X-Edge-Origin` / `HIT`), `public, max-age`,
and **no** `Set-Cookie`. Or just run `./craft edge/nginx/verify` (or `cloudflare/verify`),
which does exactly this and explains the result.

## It's not caching

Symptom: every request shows `X-Edge-Origin: 1` / `CF-Cache-Status: MISS`, `verify` fails on
GET #2.

Work down this list: it mirrors the [decision order](configuration.md#the-full-decision-order):

1. **Is the plugin enabled and a driver set?** `enabled: true`, correct `driver`. Check
   **Utilities -> Edge Cache** shows the driver you expect.
2. **Is the response even cacheable?** Look at the first response's `Cache-Control`. If it's
   `private, no-store`, the plugin decided *not* to store it: the reason is one of the
   decision rules. Common culprits:
   - **You're signed in** (e.g. into the control panel in the same browser) **and the
     page has no cached file yet**. A logged-in render is never *stored*, so a signed-in
     visitor can't prime a cold page; they get hits only once an anonymous request (or the
     warmer) has written the shared file. Prime with `curl` (no cookies) or a private
     window, then your signed-in browser gets hits too.
   - **devMode is on** and `cacheableEnvironments` is `null` -> caching is skipped. Set the
     environment or list it in `cacheableEnvironments`.
   - The URI matches `excludedUriPatterns`, or you set `includedUriPatterns` and it doesn't
     match.
3. **Is the edge tier actually in front of PHP?** If GET #1 is `public, max-age` (so the
   origin *is* marking it cacheable) but GET #2 still renders via PHP, the tier isn't
   serving the stored copy:
   - `nginx-static`: `cachePath` != the `root` inside the nginx `@edge` location,
     `location /` doesn't fall through to `@edge`, or the file wasn't written
     (permissions: look for an Edge warning in the log).
   - `nginx-fastcgi`: `X-Edge-Cache` missing -> the `add_header`/`fastcgi_cache` directives
     aren't in the PHP `location`; `MISS` forever -> the bypass map is skipping it or nginx
     is refusing to store it.
   - `cloudflare`: `CF-Cache-Status` missing -> host isn't proxied (orange-cloud it); stuck
     `MISS`/`DYNAMIC` -> cache rule not created (run `edge/cloudflare/setup`).
4. **Second-request-is-still-a-miss with cookies:** are you testing with a browser that
   already has a `CraftSessionId` or a login cookie? That's *fine*: neither bypasses the
   cache. But a cookie on your configured `bypassCookies` list does. `curl` with no
   cookies is the clean test.

## It cached something it shouldn't have

Symptom: a personalized or sensitive page is being served from cache, or a `Set-Cookie`
leaked onto a hit.

- **A per-visitor page got cached.** It wasn't excluded and didn't carry a bypass cookie at
  first render. Add its URI to [`excludedUriPatterns`](configuration.md#excludeduripatterns),
  then `./craft edge/cache/clear-url <url>` to purge the bad copy. If the page is *mostly*
  shared with a few personal bits, use [islands](templating.md#islands-per-visitor-content)
  instead of excluding it.
- **A `Set-Cookie` leaked onto a cached hit.** This should be structurally impossible: Edge
  strips cookies and refuses to store a response that still has one. If `verify` reports a
  leak, the tier is caching a response Edge *didn't* store (e.g. nginx `fastcgi_cache` set to
  cache too aggressively, ignoring `Set-Cookie`), or a second cache is in front. Re-check the
  driver config against the shipped `.conf`, and confirm no other tier is caching HTML.
- **Per-visitor content in the shared shell.** A `currentUser`-dependent branch is baked
  into a cached template, so everyone served the shell sees whatever the priming render
  produced. Move it to an [island](templating.md#the-logged-in-navbar-problem).

## Stale pages after an edit

Symptom: an editor saved, but the page still shows old content.

1. **Is the queue running?** This is the #1 cause. Purges are queued jobs: if nothing runs
   the queue, nothing purges. Run `./craft queue/run` to confirm it clears, then set up a
   [queue daemon](https://craftcms.com/docs/5.x/system/queue.html) so it's automatic. Until
   the job runs, the stale page is served; that's the async window, not a bug.
2. **Brand-new entry not in a listing?** That relies on the [query-tag map](invalidation.md#map-2-query-tags-what-kind-of-content-the-page-depends-on).
   If a listing isn't purging for new entries, the listing template may build its query in a
   way that doesn't expose section tags (e.g. a hand-built raw query). Prefer
   `craft.entries.section(...)` element queries so Craft's cache tags are recorded.
3. **Scheduled post didn't appear, or an expired entry won't go away?** Nobody saved
   anything, so no event fired. That gap is closed by
   [the refresh task](installation.md#schedule-the-refresh-task-required) —
   check it is actually scheduled and that `./craft edge/cache/refresh-expired` runs clean
   by hand. This is the most common cause when the content changed "by itself".
4. **Template/data change, not a content change?** Edge can't detect those: see
   [what Edge cannot detect](invalidation.md#what-edge-cannot-detect). Clear the cache.
5. **Global set change didn't clear enough / cleared everything?** Global sets trigger a
   [coarse flush](invalidation.md#coarse-flushes) by design; that's expected.
6. **Frozen "now"/relative time?** That's [render-time freezing](templating.md#what-counts-as-per-visitor),
   not staleness. Make it client-side.

## Forms are rejected / CSRF errors

Symptom: submitting a form on a cached page fails CSRF validation.

- **You called `csrfInput()` on a cached page.** That bakes one visitor's token into the
  shared HTML. Switch to the [empty-input pattern](templating.md#forms--csrf-on-cached-pages)
  and let hydration fill it. This is by far the most common cause.
- **Hydration isn't running.** Check the browser console for `[edge]` warnings and the
  network tab for the `edge/csrf` request. If the script isn't on the page, confirm
  `autoInjectHydrationScript: true` (or that you're including it yourself).
- **`edge/csrf` 404s.** `csrfEndpointEnabled` is `false`, or the `edge/*` routes aren't
  reaching PHP: make sure your nginx config routes `^~ /edge/` to `index.php` and doesn't
  try to serve it from the static cache.
- **CSRF disabled globally.** With `enableCsrfProtection: false`, `edge/csrf` returns
  `{token: null}` and forms submit without a token. If you *want* CSRF, re-enable it in
  Craft's general config.

## Islands don't appear

- **Network tab:** is `edge/island?name=...` being requested? If not, the hydration script
  isn't loaded (see above) or there's no `data-edge-island` element.
- **404 on the island:** the template doesn't exist at
  `templates/{islandsTemplatePath}/{name}.twig`, or the `name` has invalid characters. Names
  allow letters, digits, `_`, `-`, `/` only.
- **Empty fragment swapped in:** the island template threw an error while rendering: Edge
  returns an empty fragment rather than a 500. Check the logs for
  "Edge island `name` failed to render" and fix the template.
- **Placeholder never changes:** the fetch failed (console `[edge]` warning). The page is
  otherwise fine; island failures never break it.

## Cloudflare-specific

- **`CF-Cache-Status: DYNAMIC` forever** -> no cache rule matches. Run `edge/cloudflare/setup`
  (or create the rules by hand) and confirm the origin sends `public, max-age`.
- **Everything is `MISS`, never `HIT`** -> Cloudflare won't store responses with `Set-Cookie`.
  Confirm Edge is stripping cookies (`verify` shows "none (correct)"); if a plugin adds a
  cookie the strip missed, the belt-and-braces check downgrades the page to `no-store` and
  Cloudflare won't cache it. Find the offending cookie.
- **Purges 429** -> rate limiting; the job retries with backoff automatically. Nothing to do
  unless you see repeated failures in the log after 5 attempts (then check the token scope).
- **Tag purge rejected** -> `cloudflareUsesCacheTags` is on but the plan isn't Enterprise.
  Set it back to `false`.

## Where to look next

- **Craft logs** (`storage/logs/`): Edge logs warnings for skipped stores ("did not store
  ... : reason"), cache-write failures, purge retries, and island render errors, all prefixed
  by the originating method.
- **`./craft edge/.../verify`**: the fastest structured diagnosis; it tells you *which* of
  hit / cookie-free / cacheable failed.
- **The [decision order](configuration.md#the-full-decision-order)**: when in doubt about
  *why* a page isn't cached, walk it top to bottom against your request.

If you're still stuck, the code paths in
[How Edge works -> where the pieces live](how-it-works.md#where-the-pieces-live-in-the-code)
point at the exact file for each behaviour.
