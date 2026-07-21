# Installation

This page gets the plugin installed and gets you your first cache hit. It assumes you've
read **[How Edge works](how-it-works.md)**, in particular that Edge manages one edge tier
and that you'll pick a driver.

- [Requirements](#requirements)
- [Install the plugin](#install-the-plugin)
- [Pick a driver](#pick-a-driver)
- [The config file](#the-config-file)
- [First cache hit](#first-cache-hit)
- [What Edge added to your project](#what-edge-added-to-your-project)

## Requirements

- **Craft CMS 5.0+** and **PHP 8.2+**.
- One edge tier to manage:
  - `nginx-static`: any nginx build (no modules needed).
  - `nginx-fastcgi`: nginx with the
    [`ngx_cache_purge`](https://github.com/nginx-modules/ngx_cache_purge) module.
  - `cloudflare`: a Cloudflare zone proxying your site.
- A working **Craft queue runner**. Edge does all purging and warming in queued jobs, so
  the queue must actually run. On production, run the queue as a
  [daemon](https://craftcms.com/docs/5.x/system/queue.html) (`queue/listen` under a
  service manager) rather than relying on web-triggered runs. Purges should happen within
  seconds of a save, not on the next unlucky page load.

## Install the plugin

```bash
composer require artformdev/craft-edge
./craft plugin/install edge
```

That's the whole install. Installing the plugin:

- creates three small database tables (`edge_caches`, `edge_cache_elements`,
  `edge_cache_tags`) that hold the [dependency maps](how-it-works.md#the-two-dependency-maps);
- registers the **Utilities -> Edge Cache** panel, the `edge/*` console commands, and the
  `edge/csrf` + `edge/island` endpoints.

**Installing the plugin does not start caching anything on its own.** Nothing is cached
until you configure a driver and prepare the matching edge tier. This is deliberate:
turning on a full-page cache before the edge is set up would just mark responses cacheable
with nothing in front of PHP to store them.

## Pick a driver

Choose based on where you host. When in doubt, start with **`nginx-static`**: it needs no
special modules and is the easiest to reason about.

| Your situation | Driver | Guide |
| --- | --- | --- |
| Single server / VPS, you control nginx | **`nginx-static`** | [nginx-static](driver-nginx-static.md) |
| You already rely on nginx's FastCGI cache | `nginx-fastcgi` | [nginx-fastcgi](driver-nginx-fastcgi.md) |
| Site is behind Cloudflare | `cloudflare` | [Cloudflare](driver-cloudflare.md) |

Set the driver in the control panel (**Settings -> Plugins -> Edge**) or in `config/edge.php`:

```php
// config/edge.php
return [
    'driver' => 'nginx-static',
];
```

Then follow that driver's page to prepare the edge tier and connect Edge to it. **Do not
skip the edge-tier setup**: the plugin marking a response cacheable is only half of it,
something in front of PHP has to store and serve the copy.

## The config file

Every setting can be managed from the control panel, but on a real project you'll want at
least some settings in version control. Copy the reference file from the package into your
project's `config/` folder:

```bash
cp vendor/artformdev/craft-edge/config/edge.php config/edge.php
```

Any key present in `config/edge.php` **overrides** the control-panel value for that key:
the CP field becomes read-only and shows an "overridden by config" note. This is standard
Craft behaviour and it's how you keep environment-specific values (which driver, which
paths, secrets) in code and env vars instead of the database. Full details on every key:
**[Configuration reference](configuration.md)**.

> **Secrets never go in this file as literals.** Cloudflare credentials are read from
> environment variables (`App::env('CLOUDFLARE_API_TOKEN')`). See the
> [Cloudflare driver page](driver-cloudflare.md).

## First cache hit

Once your driver's edge tier is set up, confirm it end to end. Edge ships a `verify`
command that requests a URL twice and checks that the second response was a hit with no
cookie leak:

```bash
# nginx-static or nginx-fastcgi:
./craft edge/nginx/verify --url=https://your-site.test/

# cloudflare:
./craft edge/cloudflare/verify --url=https://your-site.test/
```

A pass looks like this (nginx-static):

```
GET #1: HTTP 200 (rendered by PHP, MISS)
GET #2: HTTP 200 (served by nginx static file, HIT)
Set-Cookie on cached response: none (correct)
Cache-Control: (none: normal for a static-file hit; nginx serves the raw file)
nginx verification PASSED.
```

You can also check by hand with `curl`: a hit is missing the `X-Edge-Origin` header:

```bash
curl -sSI https://your-site.test/ | grep -i -E 'x-edge|cache-control|set-cookie'
```

If verification fails, the output tells you what's wrong (usually the nginx config isn't
in place or the `cachePath` doesn't match). Head to [Troubleshooting](troubleshooting.md).

## Schedule the refresh task (required)

Some content changes with nobody touching it: an entry with a future **Post Date** goes
live, an entry with an **Expiry Date** stops being live. Craft works status out when a
query runs, so nothing is saved and no event fires at that moment — there is nothing for
Edge to react to, and the cached page would keep the old content indefinitely.

One scheduled command closes that gap:

```cron
* * * * * cd /path/to/your/project && ./craft edge/cache/refresh-expired
```

Run it as the same user as your other Craft cron jobs. It is two indexed lookups and a
no-op almost every time, so a per-minute schedule is cheap; the interval you choose is the
longest a scheduled post can be late.

Skip this only if you never use Post Dates or Expiry Dates.

## What Edge added to your project

So you know what's there:

- **Three database tables**: `edge_caches` (one row per cached URL, with the date it is
  next due to change status) and `edge_cache_elements` / `edge_cache_tags` (the dependency
  maps). Removed cleanly if you uninstall.
- **Two front-end routes**: `edge/csrf` and `edge/island`, used by the hydration script.
  Both are always `private, no-store`.
- **A Twig function**: `{{ edgeIsland('name') }}`, for personalized fragments.
- **An asset**: `edge-hydrate.js`, auto-injected on front-end pages (toggle with
  `autoInjectHydrationScript`).
- **A CP utility**: **Utilities -> Edge Cache**, showing the active driver, cache counts,
  and last verification, with clear/verify buttons.
- **Console commands**: under `./craft edge/*`.

Next: your driver's setup page:
[nginx-static](driver-nginx-static.md) |
[nginx-fastcgi](driver-nginx-fastcgi.md) |
[Cloudflare](driver-cloudflare.md).
