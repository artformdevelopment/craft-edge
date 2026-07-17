# Edge documentation: index

Edge is a full-page HTML cache for Craft CMS 5. It serves your anonymous visitors their
pages straight from the edge (a directory of static files, nginx's FastCGI cache, or
Cloudflare), **without PHP or the database running at all**, and keeps that cache correct by
watching Craft's own element-change signals.

The tricky part of full-page caching isn't making pages fast. It's keeping them fast
**without** serving one person's session to somebody else, and **without** ever showing
stale content after an editor hits Save. This documentation is mostly about those two
problems, because that's where full-page caching usually goes wrong.

This page is the central map of everything. If it's your first time, follow the
[first-time reading path](#reading-paths): the pages build on each other.

## Full contents

| # | Page | What's in it |
| --- | --- | --- |
| 1 | **[How Edge works](how-it-works.md)** | The mental model: cache hit/miss lifecycle, the cookie model, the anonymous-shell + islands idea, the two-map invalidation, and the `X-Edge-Origin` header. **Read first.** |
| 2 | **[Installation](installation.md)** | Install the plugin, pick a driver, copy the config, get your first cache hit. |
| 3 | **[Driver: nginx-static](driver-nginx-static.md)** | Edge writes HTML files; nginx serves them with `try_files`. No modules. The recommended default. |
| 4 | **[Driver: nginx-fastcgi](driver-nginx-fastcgi.md)** | nginx's `fastcgi_cache` stores responses; purge via `ngx_cache_purge`. |
| 5 | **[Driver: Cloudflare](driver-cloudflare.md)** | Cloudflare caches at its global edge; Edge writes the cache rules and purges via API. |
| 6 | **[Configuration reference](configuration.md)** | Every setting, what it does, when to change it, and the full decision order. |
| 7 | **[Templating for the cache](templating.md)** | The anonymous shell, forms & CSRF, islands, and the patterns that quietly break caching. **The key page for developers.** |
| 8 | **[Invalidation & warming](invalidation.md)** | How a save becomes the exact set of purges, coarse flushes, and how warming works. |
| 9 | **[CLI & control panel](cli-and-control-panel.md)** | Every console command and the **Utilities -> Edge Cache** panel. |
| 10 | **[Troubleshooting](troubleshooting.md)** | Reading the response headers, and fixing the usual suspects. |

### Reference configs (copy these)

| File | Use it for |
| --- | --- |
| **[`nginx-static.conf`](nginx-static.conf)** | The nginx server config for the `nginx-static` driver. |
| **[`nginx-fastcgi.conf`](nginx-fastcgi.conf)** | The nginx server config for the `nginx-fastcgi` driver (needs `ngx_cache_purge`). |

## Find what you need by goal

- **"I just want to install it."** -> [Installation](installation.md), then your driver:
  [nginx-static](driver-nginx-static.md) | [nginx-fastcgi](driver-nginx-fastcgi.md) |
  [Cloudflare](driver-cloudflare.md).
- **"How do I set up nginx / Cloudflare?"** -> the [driver pages](driver-nginx-static.md),
  each with a *prepare -> connect -> verify* walkthrough, plus the
  [reference configs](#reference-configs-copy-these).
- **"What does this setting do?"** -> [Configuration reference](configuration.md).
- **"How do I build templates that cache correctly?"** -> [Templating](templating.md).
- **"How do I put a cart / login menu / greeting on a cached page?"** ->
  [Templating -> Islands](templating.md#islands-per-visitor-content).
- **"My form is failing CSRF validation."** ->
  [Templating -> Forms & CSRF](templating.md#forms--csrf-on-cached-pages) or
  [Troubleshooting](troubleshooting.md#forms-are-rejected--csrf-errors).
- **"It's not caching / it cached the wrong thing / it's stale."** ->
  [Troubleshooting](troubleshooting.md).
- **"When does content get purged?"** -> [Invalidation & warming](invalidation.md).
- **"What can I run from the CLI?"** -> [CLI & control panel](cli-and-control-panel.md).
- **"How do I keep secrets out of config?"** ->
  [Configuration -> How overrides work](configuration.md#how-overrides-work) and the
  [Cloudflare credentials section](driver-cloudflare.md#step-1-prepare-the-environment-credentials).

## Reading paths

- **First time (everyone):** [How Edge works](how-it-works.md) -> [Installation](installation.md)
  -> your [driver page](driver-nginx-static.md) -> [Templating](templating.md). That's the core;
  the rest is reference you can reach for later.
- **Front-end developer:** [How Edge works](how-it-works.md) -> [Templating](templating.md) ->
  [Troubleshooting](troubleshooting.md).
- **DevOps / server admin:** [How Edge works](how-it-works.md) -> your
  [driver page](driver-nginx-static.md) -> [Invalidation & warming](invalidation.md) ->
  [CLI & control panel](cli-and-control-panel.md).

## The 60-second version

- A page for an **anonymous visitor** with **no `Set-Cookie`** is safe to cache. Edge stores
  it **stripped of all cookies**, so a stored page can never carry anyone's session.
- Cookies are **ignored** by the cache for serving: Craft's anonymous cookies
  (`CraftSessionId`, the CSRF token) and its login cookies never change the key and never
  cause a bypass, so returning and signed-in visitors keep getting hits. A logged-in
  *render*, though, is never stored; the shared copy only ever comes from an anonymous
  render.
- Your **`bypassCookies`** list (empty by default) is the opt-in escape hatch: a cookie on
  it (e.g. a live-cart cookie) forces fresh, personal pages for that visitor.
- A cached page is made personal again **in the browser**: a tiny script fetches the
  visitor's CSRF token and any personalized "islands" from **uncached** endpoints.
- When content changes, Edge purges **every** URL that rendered the changed element or ran a
  query the change affects, in background queue jobs. It errs toward purging too much, never
  too little.

Start with **[How Edge works](how-it-works.md)**.
