# Release Notes for Edge

## 1.2.0 - 2026-07-20

### Added
- `{{ edgeCsrfInput() }}` Twig function. Emits a token-free placeholder on a page that is
  being cached (the hydration runtime fills it per visitor) and the real token inline on a
  page that isn't — an excluded URI, a bypass, a signed-in render, an island fragment. A
  shared form partial no longer needs a flag threaded through it to say which kind of page
  it landed in, and the uncached case costs no round trip and needs no JavaScript.
- The Edge Cache utility and `edge/nginx/verify` now warn when `queryStringCaching` is
  `respect` on the `nginx-static` driver. That combination silently writes one file per
  query string that the tier can never look up, because it serves `<host>/<uri>/index.html`
  with no place for a query segment.

### Fixed
- The shipped nginx configs now bypass the cache for any request carrying a query param
  that isn't in `excludedQueryStringParams`. Without it, `/shop?brand=x` was answered from
  the unfiltered `/shop` entry, since `ignore` mode leaves the query string out of the key.
  `tests/guards.php` asserts the map and the origin guard agree case by case.
- `location @edge` now sends `Cache-Control: public, max-age=0, must-revalidate` on a hit.
  nginx serves the stored file verbatim, so the origin's headers never reached the visitor
  and browsers fell back to heuristic freshness — holding pages for an unpredictable window
  that no purge could reach.

### Changed
- `Plugin::proxyWarnings()` is now `Plugin::configWarnings()` and covers driver/query-mode
  mismatches as well as trusted-proxy problems.


## 1.1.0 - 2026-07-20

### Fixed
- A response rendered for a `Host` other than the site's configured base-URL host is no
  longer stored. The cache file is keyed by the site's own host while Craft renders
  absolute URLs from the request `Host`, so a request arriving on another host (a bare IP,
  a forged `Host`) could write its URLs into the canonical entry and have them served to
  every visitor. Such requests still render normally.
- Cacheable responses now send `public, s-maxage=<cacheControlTtl>, max-age=0,
  must-revalidate` instead of `public, max-age=<cacheControlTtl>`. `max-age` is a browser
  directive, and a purge can never reach a browser: the previous header let a visitor hold
  stale HTML for the full TTL (a year, by default) with no way to recall it.
- In `queryStringCaching: 'ignore'` mode, a request carrying a query param that isn't in
  `excludedQueryStringParams` is no longer stored. The query string is dropped from the
  cache key in that mode, so `/shop?brand=x` was being written over the entry for plain
  `/shop`. Marketing params are excluded by design and still cache.
- CSRF hydration no longer races island hydration. The token is fetched once and applied
  again to each island fragment after it swaps in, before `edge:island` fires, so forms
  inside islands are filled.
- CSRF hydration now fills Craft's async `<craft-csrf-input>` placeholder, which Formie
  also emits and which `asyncCsrfInputs` produces. Previously only plain inputs were
  filled.

### Added
- `cacheLoggedInRenders` setting (default `false`). The edge tier already serves the shared
  copy to signed-in visitors, but their renders were never stored, so a page browsed only
  by signed-in staff never warmed. Enable it when the shell is identity-independent.
- A response containing a rendered CSRF token (an input named after `csrfTokenName` with a
  non-empty value) is refused and logged instead of being stored with a token baked in.
- A response containing the signed-in user's email, username or full name is refused and
  logged when `cacheLoggedInRenders` is on. The matched field name is logged, never its
  value. This catches the obvious leak only; it cannot see group-scoped pricing or
  permission-scoped elements.
- `X-Edge-Skip-Reason` header on non-cacheable responses in `devMode`, naming the rule that
  matched.
- The Edge Cache utility and `edge/nginx/verify` warn when `trustedHosts` contains `any`
  while `ipHeaders` is set, which lets any client spoof its IP through `X-Forwarded-For`.
- `window.EdgeCsrf.ensure(root)` / `.apply(root)` for filling CSRF fields in markup a site
  injects itself.
- `docs/reverse-proxy.md`, covering `trustedHosts` / `ipHeaders` for both proxy topologies.
- The shipped nginx configs gained a `location ^~ /actions/` block, a `default_server`
  catch-all recipe, and a reverse-proxy variant for origins that aren't FastCGI. Without a
  `.php` handler, `try_files $uri` resolves `/index.php` to the file on disk and nginx
  serves the PHP source.
- `tests/guards.php`, a framework-free self-check for the pure guards (`php tests/guards.php`).

### Changed
- `CacheDriverInterface::prepareResponse()` takes an optional fourth argument,
  `?string $skipReason`. Drivers extending `BaseDriver` need no change; a driver
  implementing the interface directly must update its signature.


## 1.0.0 - 2026-07-17
- Initial release.
