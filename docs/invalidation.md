# Invalidation & warming

A cached page is a copy that can go stale. This page explains, in detail, how Edge decides
**which** cached pages a content change affects, how it purges them, and how it optionally
re-warms them, all without ever blocking the editor who made the change.

The design principle throughout: **a stale page is a bug; an extra purge is not.** When Edge
is unsure, it purges more, not less.

- [The two dependency maps](#the-two-dependency-maps)
- [From a save to a purge](#from-a-save-to-a-purge)
- [Coarse flushes](#coarse-flushes)
- [Everything is asynchronous](#everything-is-asynchronous)
- [Purge jobs and retries](#purge-jobs-and-retries)
- [Warming](#warming)
- [Drafts, revisions, and previews](#drafts-revisions-and-previews)
- [What Edge cannot detect](#what-edge-cannot-detect)

## The two dependency maps

Every time Edge caches a page, it records two kinds of dependency (in the `edge_cache_elements`
and `edge_cache_tags` tables). Invalidation is the **union** of both: a change purges a page
if it matches *either* map.

### Map 1: element IDs ("what's on the page")

While the page renders, Edge records the ID of **every element that was populated**: the
entry you're viewing, entries pulled in through a related field, categories, authors, any
element the template actually rendered.

-> catches: **edits to content already on the page.** Save entry 42 -> purge every page that
rendered entry 42 (its detail page, any listing showing it, any related-field block linking
to it).

### Map 2: query tags ("what kind of content the page depends on")

Every element **query** the page runs exposes Craft's own cache tags via
`ElementQuery::getCacheTags()`: things like "any entry in section 5" or "any entry of type
7". Edge records those tags for the page.

-> catches: **new content that would now match a listing.** Publish a brand-new blog post ->
it has no ID on any cached page yet (map 1 can't help), but the blog index recorded the tag
"any entry in the blog section", so the index is purged and rebuilds *with* the new post.

> This is the single most important reason Edge tracks queries and not just elements. An
> element-ID-only cache silently misses brand-new entries appearing in listings, the
> classic "I published a post but the blog index still doesn't show it" bug. Map 2 fixes it.

The one tag Edge deliberately does **not** store per-page is the bare `element` tag ("every
element of every type"); it's far too broad to be a useful dependency, so a change that
fires it triggers a [coarse flush](#coarse-flushes) instead.

## From a save to a purge

When content changes, Craft fires `Elements::EVENT_INVALIDATE_CACHES` with a set of tags.
Edge also listens to save / delete / restore / structure-move events as a backstop (so a
delete is captured *before* the row disappears and the ID map can still resolve it). For a
single request it:

1. **Buffers** all the fired tags and changed element IDs.
2. At the **end of the request**, resolves the union: every cached URL whose recorded tags
   intersect the fired tags, **plus** every cached URL that recorded one of the changed
   element IDs.
3. **Deletes** those rows from `edge_caches` (they'll re-register on the next render).
4. **Dispatches** batched purge jobs, and, if `warmCacheAutomatically` is on, warm jobs.

Worked example: editing "Alpha Post", which a related field links from "Beta Post", on a
site with a blog index:

| Cached URL | Recorded? | Purged because |
| --- | --- | --- |
| `/blog/alpha-post` | element 101 (itself) | ID map: element 101 changed |
| `/blog/beta-post` | element 101 (via related field) | ID map: element 101 changed |
| `/blog` (index) | tag "entries in blog section" | tag map: same section |
| `/about` | element 55 only | **not** purged, unrelated |

`/about` stays a hit; the other three are purged and (if warming is on) rebuilt within
seconds.

## Coarse flushes

Some changes can't be resolved to a precise URL set, so Edge flushes the **entire managed
tier**. These are rarer than normal edits but important to know:

- **A global set is saved.** Global content is read via the `globals` variable, not an
  element query, so there's no query tag to match against; Edge can't know which pages use
  which global. Everything flushes. (If your nav or footer is a global set, saving it clears
  the whole cache, usually fine since it affects every page anyway.)
- **Plugin settings are saved**, or **any plugin is installed / uninstalled / enabled /
  disabled** (except Edge installing itself). These can change how *any* page renders.
- **Craft fires its "all element caches invalidated" signal** (e.g. `invalidateAllCaches`).

A coarse flush deletes all `edge_caches` rows and issues a single "purge everything" to the
tier (`purge_everything` on Cloudflare; delete the directory on nginx-static; per-URL purges
on nginx-fastcgi, which has no reliable wildcard; see the
[fastcgi limitations](driver-nginx-fastcgi.md#limitations)). If warming is on, every
previously-cached URL is queued for re-warming.

## Everything is asynchronous

**No purge or warm ever happens inline in the request that triggered it.** When an editor
saves, Edge buffers the change and registers a one-time end-of-request hook; the actual
resolution and job dispatch happen *after* the response is sent. The editor's Save returns
immediately; they never wait on a purge, and a slow or failing purge can't make saving
feel slow or error out.

The consequence: there is a **brief window** between a save and the purge completing, during
which the stale page may still be served. That window is however long your queue takes to
pick up the job: seconds if you run the queue as a daemon, longer if you rely on
web-triggered queue runs. **Run the queue as a daemon in production** so purges land within
seconds. See [Craft's queue docs](https://craftcms.com/docs/5.x/system/queue.html).

## Purge jobs and retries

Purges run in `PurgeJob`s:

- **Batched.** URLs are chunked (default 30 per job for Cloudflare's API limit; the nginx
  drivers purge per-URL). A big edit becomes a handful of jobs, not one giant one.
- **Retried with backoff.** A transient failure (Cloudflare `429`/`5xx`, or a network blip)
  throws a *retryable* error, and the job re-queues itself with exponential backoff (30s,
  60s, 120s, 240s) up to 5 attempts. A purge is **never silently dropped**.
- **Isolated from the save.** If a purge ultimately fails after all retries, it's logged as
  an error, but the content save that triggered it already succeeded. A broken edge tier
  degrades to "serving stale until fixed", never to "editors can't save".

A *permanent* error (misconfiguration: wrong Cloudflare token, missing `ngx_cache_purge`
location) is not retried; it's raised clearly so you can fix the config. The
[driver pages](driver-nginx-fastcgi.md#the-purge-path-explained) cover those messages.

## Warming

With `warmCacheAutomatically: true` (default), after a purge Edge queues a `WarmJob` that
re-requests the purged URLs so the next real visitor gets a hit instead of paying for the
re-render:

- Requests are sent **cookie-free and anonymous** (no cookie jar), so the render is a
  clean anonymous shell and the origin stores the result.
- They carry an `X-Edge-Warm: 1` header (so you can spot them in logs) and run
  `concurrency` at a time (default 5).
- Warm jobs run at lower priority and with a short delay, so purges land first.
- A failed warm request is logged and skipped; worst case, that URL is a miss for the next
  visitor, who re-primes it.

Turn warming off if you'd rather reduce origin load right after big bulk edits and let
traffic re-populate the cache lazily.

You can also warm manually:

- `./craft edge/cache/warm`: re-warm every URL Edge currently has recorded.
- `./craft edge/cache/generate`: request every **live element URL** (entries + categories,
  all sites) to build the cache from scratch, e.g. right after a deploy or a full clear.

## Drafts, revisions, and previews

Edge never lets non-live content pollute or purge the live cache:

- **Draft/revision renders aren't tracked**: a draft preview never creates cache
  dependencies.
- **Draft/revision saves don't purge live URLs**: editing a draft of an entry doesn't touch
  the cached live page. Only publishing (which saves the canonical element) does.
- **Preview and token requests are never cached** in the first place (they're bypassed at
  the [decision stage](configuration.md#the-full-decision-order)).

## Scheduled status changes

A save is not the only way content changes. An entry with a future **Post Date** becomes
live on its own; one with an **Expiry Date** stops being live. Craft derives status when a
query runs, so at that moment nothing is saved and no event fires — the two maps above
have nothing to react to.

Edge handles this from the other end, which is why
[the refresh task](installation.md#schedule-the-refresh-task-required) is required:

- While rendering, Edge records the **earliest** moment any element on the page is due to
  change status, in `edge_caches.expiryDate`.
- `edge/cache/refresh-expired` purges pages whose recorded moment has passed, and separately
  picks up elements that crossed a post or expiry date since its last run, handing each to
  Craft so it takes exactly the same path as a save.

The second half is what catches a scheduled post going live: a page never rendered a
pending entry, so it holds no dependency on one, and only the query tags can resolve it.

Without the task scheduled, a scheduled post never appears and an expired entry never
disappears — on those pages, until something else purges them.

## What Edge cannot detect

Edge tracks **content** changes via Craft's element system. It cannot know about changes
that don't go through that system:

- **Template / code changes.** Editing a `.twig` file or deploying new code doesn't
  invalidate anything; Edge has no signal for it. **Clear the cache as a deploy step**
  (`./craft edge/cache/clear`) if a release changes how pages render.
- **Time-based content** that was frozen at render (see
  [Templating](templating.md#what-counts-as-per-visitor)). "Posted 3 minutes ago" won't
  update until the page is purged for another reason. Make such content client-side.
- **External data.** A page rendering data from a third-party API won't purge when that API's
  data changes; nothing fired a Craft event. Purge those URLs on a schedule
  (`edge/cache/clear-url`) or shorten their handling.

Next: [CLI & control panel](cli-and-control-panel.md) | [Troubleshooting](troubleshooting.md).
