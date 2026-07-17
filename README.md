# Edge

**Full-page HTML caching for Craft CMS 5, without the usual footguns.**

Edge serves your anonymous visitors their pages straight from the edge (a folder of static
files, nginx's FastCGI cache, or Cloudflare) with **PHP and the database never running**.
The hard parts of full-page caching (not leaking one person's session to the next, and never
serving stale content after an edit) are the parts Edge is actually built around.

- **Hits skip PHP entirely.** An anonymous page view is answered by nginx or Cloudflare in
  single-digit milliseconds. Craft doesn't boot.
- **Session leaks are structurally impossible.** Cacheable responses are stored *stripped
  of every cookie*; there is no code path that caches a response carrying someone's session.
- **Invalidation is exact.** When an editor saves, Edge purges *every* URL that rendered
  the changed element or ran a query the change affects: detail pages, listings (including
  brand-new entries), related pages, nav, in background jobs, within seconds.
- **Cached pages stay personal.** Forms, CSRF tokens, carts and greetings keep working via
  tiny uncached "islands" hydrated in the browser. No framework, no build step.

## How it works, in one picture

A cached page is an **anonymous shell**, identical for everyone, stored cookie-free and
served from the edge, with a few **personal holes** filled in the browser from endpoints
that are never cached:

```
┌──────────── cached, shared, cookie-free (served by nginx/Cloudflare) ────────────┐
│  header · article · product grid · footer, the same HTML for every visitor        │
│                                                                                   │
│   ┌─ island: cart / greeting ─┐        ┌─ form CSRF token (empty in the cache) ─┐  │
│   │ fetched per-visitor, live │        │ filled per-visitor by edge-hydrate.js  │  │
│   └───────────────────────────┘        └────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────────────────┘
```

Anonymous cookies (`CraftSessionId`, CSRF) are **ignored** so returning visitors still get
hits, and login cookies are ignored too: signed-in visitors get the same shared shell as
everyone else, with their account menu, cart and CSRF tokens hydrated client-side from
uncached endpoints. It's a small set of ideas that fit together. The
**[How Edge works](docs/how-it-works.md)** guide walks through all of them, and it's worth
ten minutes before you turn caching on.

## Install

```bash
composer require artformdev/craft-edge
./craft plugin/install edge
```

Then pick a driver and prepare its edge tier. Installing the plugin doesn't cache anything on
its own; something in front of PHP has to store and serve the copies:

| Where you host | Driver | Guide |
| --- | --- | --- |
| Single server / VPS (simplest, start here) | `nginx-static` | [nginx-static](docs/driver-nginx-static.md) |
| You use nginx's FastCGI cache | `nginx-fastcgi` | [nginx-fastcgi](docs/driver-nginx-fastcgi.md) |
| Behind Cloudflare | `cloudflare` | [Cloudflare](docs/driver-cloudflare.md) |

Copy the config file and confirm your first hit:

```bash
cp vendor/artformdev/craft-edge/config/edge.php config/edge.php
./craft edge/nginx/verify --url=https://your-site.test/   # (or edge/cloudflare/verify)
```

Full walkthrough: **[Installation](docs/installation.md)**.

## Documentation

The complete guide lives in **[`docs/`](docs/index.md)**. Read it in order the first time:

1. **[How Edge works](docs/how-it-works.md)**: the mental model. *Start here.*
2. **[Installation](docs/installation.md)**: install, pick a driver, first hit.
3. **Drivers**: [nginx-static](docs/driver-nginx-static.md),
   [nginx-fastcgi](docs/driver-nginx-fastcgi.md), [Cloudflare](docs/driver-cloudflare.md).
   Each covers preparing the environment, connecting Edge, and verifying.
4. **[Configuration reference](docs/configuration.md)**: every setting, in depth.
5. **[Templating for the cache](docs/templating.md)**: the anonymous shell, forms & CSRF,
   islands, and the patterns that quietly break caching. **The key page for developers.**
6. **[Invalidation & warming](docs/invalidation.md)**: how a save becomes the right purges.
7. **[CLI & control panel](docs/cli-and-control-panel.md)**: commands and the utility.
8. **[Troubleshooting](docs/troubleshooting.md)**: reading the headers, and fixing the
   usual suspects.

## At a glance

**Requirements:** Craft CMS 5.0+, PHP 8.2+, a running queue, and one edge tier
(any nginx for `nginx-static`; nginx + `ngx_cache_purge` for `nginx-fastcgi`; or a Cloudflare
zone).

**The cookie model** (the thing that makes it safe):

| Cookie class | Examples | What Edge does |
| --- | --- | --- |
| Anonymous | `CraftSessionId`, CSRF token, `PHPSESSID`, Craft's login cookies | **Ignored**: never key, bypass, or vary. Signed-in visitors share the anonymous shell and personalize client-side. |
| Opt-in bypass | your `bypassCookies` (empty by default), e.g. a live cart cookie | **Bypass**: always dynamic. |
| `Set-Cookie` on a cacheable response | any | **Stripped before storing**: if one survives, the page isn't stored at all. |

**Never stored, ever:** non-GET requests; CP / action / preview / token requests; logged-in
renders (a signed-in visitor is *served* the shared anonymous copy, but their own render is
never persisted); requests with a bypass cookie; non-200 or redirect responses; anything
still carrying a `Set-Cookie`. A cache-write failure (read-only disk, etc.) never breaks the
page: it's logged and served dynamically.

**Common commands:**

```bash
./craft edge/cache/clear             # clear everything (records + tier)
./craft edge/cache/clear-url <url>   # purge one URL
./craft edge/cache/generate          # build the cache from all live element URLs
./craft edge/nginx/verify            # prove MISS -> HIT, no cookie leak
./craft edge/cloudflare/setup        # write the Cloudflare cache rules
```

## License

Commercial plugin, see [LICENSE.md](LICENSE.md) (Craft license).
