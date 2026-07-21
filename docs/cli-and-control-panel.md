# CLI & control panel

Everything you need to operate Edge day to day: the console commands and the CP utility.

- [Console commands](#console-commands)
- [The Edge Cache utility](#the-edge-cache-utility)
- [Settings screen](#settings-screen)
- [Common recipes](#common-recipes)

## Console commands

All commands live under `./craft edge/`.

### Cache management

```bash
./craft edge/cache/clear
```
Clears the **entire** cache: deletes all dependency rows and flushes the managed tier
(empties the static directory / purges everything on Cloudflare / per-URL purges on
fastcgi). Use after a deploy that changes templates, or to start clean.

```bash
./craft edge/cache/clear-url https://your-site.com/blog/hello
```
Purges a **single** URL from the edge and removes its record. If the URL isn't recorded,
Edge still issues the purge at the tier (best-effort). Handy for one-off staleness or
externally-driven content.

```bash
./craft edge/cache/warm
```
Queues a warm job for **every URL Edge currently has recorded**, re-requesting them
cookie-free so they're hits again. Nothing to warm if the cache is empty: run `generate`
first to seed from content.

```bash
./craft edge/cache/generate
```
Queues requests for **every live element URL** (all entries and categories with URIs,
across all sites), building the cache from scratch. Ideal right after a full clear or a
deploy: `./craft edge/cache/clear && ./craft edge/cache/generate && ./craft queue/run`.

```bash
./craft edge/cache/refresh-expired
```
Catches up with content that changed status with nobody touching it: a scheduled post going
live, an entry passing its expiry date. Craft fires no event at those moments, so this is
the only thing that notices. **Put it on a schedule** — see
[Installation](installation.md#schedule-the-refresh-task-required). Two indexed lookups and
a no-op almost every time, so a per-minute cron is fine.

> `warm`, `generate` and `refresh-expired` **queue** jobs; they don't do the work
> synchronously. Make sure the queue runs (`./craft queue/run`, or a queue daemon)
> afterwards.

### Verification

```bash
./craft edge/nginx/verify [--url=https://your-site.com/]
```
For the `nginx-static` / `nginx-fastcgi` drivers. Requests the URL twice and asserts the
second response was a hit (`nginx-static`: no `X-Edge-Origin`; `nginx-fastcgi`:
`X-Edge-Cache: HIT`) with **no `Set-Cookie` leak**. Defaults to the primary site's base URL.
Exits non-zero on failure: usable in CI/health checks. Refuses to run if the active driver
isn't an nginx driver.

```bash
./craft edge/cloudflare/verify [--url=https://your-site.com/]
```
The Cloudflare equivalent: asserts a second-hit `CF-Cache-Status: HIT` with no cookie leak.

### Cloudflare setup

```bash
./craft edge/cloudflare/setup
```
Idempotently creates/updates the Edge cache rules on the zone: cache-eligible HTML,
preceded by a bypass-on-cookie rule when `bypassCookies` is configured.
**Mutates your Cloudflare zone**: explicit and opt-in, never run
automatically. Re-run it whenever you change `bypassCookies` or `excludedQueryStringParams`
so the rules stay in sync. Fails with a clear message if credentials are missing. See the
[Cloudflare driver page](driver-cloudflare.md#step-3-create-the-cache-rules).

## The Edge Cache utility

**Utilities -> Edge Cache** in the control panel shows:

- the **active driver**;
- **cache counts**: how many URLs are cached and how many element/tag dependency links
  exist (a quick health signal: zero cached URLs on a busy site means caching isn't
  happening);
- the **last verification result** (from any `verify` run, CLI or button);
- buttons to **clear the cache**, **run verification**, and, on the Cloudflare driver,
  **run setup**.

The buttons map to the same actions as the CLI, and are admin-only. The verify button runs
against the primary site's base URL; for other URLs use the CLI with `--url`.

## Settings screen

**Settings -> Plugins -> Edge** exposes every [configuration option](configuration.md) as a
proper Craft form: the driver select reveals only the relevant driver's fields, list
settings are editable tables, and path/secret fields have environment-variable autosuggest.
Any key set in `config/edge.php` shows as **overridden** and read-only: the config file
always wins. This is the same precedence described in
[Configuration -> How overrides work](configuration.md#how-overrides-work).

## Common recipes

**Deploy that changed templates:**
```bash
./craft edge/cache/clear
./craft edge/cache/generate
./craft queue/run
```

**Prime a fresh cache without a deploy:**
```bash
./craft edge/cache/generate && ./craft queue/run
```

**Health check (exits non-zero if the edge isn't serving hits):**
```bash
./craft edge/nginx/verify --url=https://your-site.com/ || alert "Edge not caching!"
```

**Bust one stale page (e.g. after an external data change):**
```bash
./craft edge/cache/clear-url https://your-site.com/pricing
```

**Force a fresh render in a browser** (no CLI): append `?no-cache=1` to any URL.

Next: [Troubleshooting](troubleshooting.md).
