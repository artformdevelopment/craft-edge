# How Edge works

This page is the mental model. If you understand it, the rest of the documentation is
just detail. It is longer than a quick-start because full-page caching has a few ideas
that are genuinely worth understanding before you turn it on. Getting them wrong is
how caches leak sessions or serve stale pages.

- [The one-sentence idea](#the-one-sentence-idea)
- [What "the edge" means here](#what-the-edge-means-here)
- [A request, start to finish](#a-request-start-to-finish)
- [The cookie model (the important part)](#the-cookie-model-the-important-part)
- [Making a cached page personal again](#making-a-cached-page-personal-again)
- [Keeping the cache correct: invalidation](#keeping-the-cache-correct-invalidation)
- [The `X-Edge-Origin` header](#the-x-edge-origin-header)
- [Where the pieces live in the code](#where-the-pieces-live-in-the-code)

## The one-sentence idea

> The first anonymous visitor to a page pays the full cost of rendering it; Edge saves
> that finished HTML at the edge, and **every** anonymous visitor after them is served
> the saved copy without Craft, PHP, or the database being touched.

Everything else exists to make that safe: to make sure the saved copy contains nobody's
private data, that every visitor (signed in or not) can be served it with their personal
content filled in client-side, and that it disappears the moment the content behind it
changes.

## What "the edge" means here

"The edge" is whatever sits **in front of PHP** and can answer a request before Craft
boots. Edge supports three, and you run **exactly one** of them:

| Driver | Where the copy lives | Who serves the hit |
| --- | --- | --- |
| `nginx-static` | HTML files on disk | nginx `try_files`, before PHP |
| `nginx-fastcgi` | nginx's FastCGI cache | nginx `fastcgi_cache`, before PHP |
| `cloudflare` | Cloudflare's global network | Cloudflare, before the request ever reaches your server |

The plugin's job is the same for all three: **decide** what may be cached, **produce**
a clean cacheable response, **store** it (or let the tier store it), and **purge** it
at exactly the right time. Only the storage and purge mechanics differ per driver.

Because exactly one tier is managed, the golden rule is: **the other tiers must not
cache HTML.** If nginx caches HTML *and* Cloudflare caches HTML, you have two caches
with two different ideas of when to expire, and Edge only purges one of them. Each
[driver page](driver-nginx-static.md) tells you how to keep the others pass-through.

## A request, start to finish

Here is the actual lifecycle, traced through the plugin. Two paths matter: a **miss**
(nobody has cached this yet) and a **hit** (the edge already has it).

### The miss: the first anonymous visitor

1. The request reaches nginx. There is no cached file/entry, so nginx passes it to PHP.
   Craft boots.
2. Very early (on `WebApplication::EVENT_INIT`, before the page renders), Edge builds a
   plain snapshot of the request (`RequestContext`) and asks one question: *may this be
   cached?* (`Cacheability::evaluateRequest`). This checks the method, whether it's a
   site request, whether a user is logged in (a logged-in render is never *stored*; see
   [the cookie model](#the-cookie-model-the-important-part)), whether a bypass cookie is
   present, the environment, your include/exclude rules, and so on. See
   [the cookie model](#the-cookie-model-the-important-part) and
   [Configuration](configuration.md) for every rule.
3. If the answer is **yes**, Edge starts *tracking*. From this moment until the response
   is ready, it records **every element** the page renders and **every element query**
   it runs (more on why in [invalidation](#keeping-the-cache-correct-invalidation)).
4. The page renders normally. Twig runs, the database is queried, the HTML is built.
5. When the response is prepared, Edge runs its **cookie-safety layer**: it strips every
   `Set-Cookie` (the session cookie, the CSRF cookie, anything), removes `Vary`, and
   sets `Cache-Control: public, max-age=...`. It then **stores** that clean HTML through
   the driver and records the page's dependencies in three small database tables.
6. The visitor gets their page. The **next** anonymous visitor gets a hit.

If the answer at step 2 was **no**, Edge does the opposite: it makes sure the response
says `Cache-Control: private, no-store` and leaves the cookies **completely alone**.
That is the path logins, forms, and personalized pages take, and it's why they keep
working.

### The hit: everyone after them

1. The request reaches the edge tier.
2. The tier finds the stored copy and returns it **immediately**. PHP never runs. Craft
   never boots. The database is never queried.
3. The visitor gets a byte-for-byte copy of what the first visitor's render produced,
   with no cookies attached, because it was stored clean.

A hit is measured in single-digit milliseconds because there is almost nothing to do.
That is the entire point.

## The cookie model (the important part)

Full-page caching's classic failure is the **session leak**: visitor A's logged-in page
gets cached, and visitor B is served it, now wearing A's session. Edge makes that
**structurally impossible** by sorting every cookie into one of three buckets.

### Bucket 1: ignored cookies (the default for everything)

`CraftSessionId`, the CSRF token cookie (`CRAFT_CSRF_TOKEN` by default), `PHPSESSID`, and
Craft's login cookies.

Cookies in this bucket **never affect serving**:

- they are **never** part of the cache key (so two visitors with different session
  IDs still share one cached copy),
- they **never** trigger a bypass (so a returning visitor, or a signed-in one, still
  gets a hit),
- they are **never** a `Vary` (so the edge doesn't fragment the cache per-cookie).

> **Why this matters:** a naive "bypass if any cookie is present" rule means the *second*
> page view a visitor makes (when they already have a `CraftSessionId`) misses the
> cache, and every signed-in visitor misses it forever. Your hit rate collapses, and it
> collapses hardest for exactly the visitors who use the site most. Ignoring cookies for
> serving is what makes the cache actually get used.

The session/CSRF cookie names come from your Craft config (`phpSessionName`,
`csrfTokenName`), so a renamed session or CSRF cookie is still recognised as anonymous.

> **One caveat on the guard.** Nothing in bucket 1 bypasses, because
> [`bypassCookies`](configuration.md#bypasscookies) is empty by default. On top of that,
> Edge hard-ignores the session and CSRF cookie names even if you *do* list them, so a
> misconfiguration can't accidentally disable your cache for every returning visitor.
> That hard-ignore list covers the session and CSRF cookies only, **not** Craft's identity
> cookie: if you deliberately add it to `bypassCookies`, signed-in visitors will bypass.

**Signed-in visitors get hits too.** The cached shell is anonymous and identical for
everyone, so the edge serves it to a visitor carrying a login cookie exactly as it would
to anyone else, and their account menu, cart, and CSRF tokens hydrate client-side from the
uncached island endpoints ([the next section](#making-a-cached-page-personal-again)). The
asymmetry that keeps this safe lives at the **origin**: while any visitor may be *served*
the shared file, a logged-in render is not **stored** by default. If a signed-in visitor is
the one who misses (no file yet), PHP renders their page live, marks it `private, no-store`,
and persists nothing; the shared file is written from an anonymous render (a real anonymous
visitor, or the cookie-free warmer).

That default is deliberately conservative, because Edge cannot verify your templates. It
does have a cost: if only signed-in staff browse a page, it never warms. When your shell
really is identity-independent — every per-visitor fragment is an island, and nothing in
the cacheable shell branches on `currentUser`, customer group, or permission-scoped element
queries — you can set
[`cacheLoggedInRenders`](configuration.md#cacheloggedinrenders) to `true` and let signed-in
visits populate the cache like any other.

Before you do, be clear about what Edge checks and what it doesn't. It refuses to store a
response containing the signed-in user's email, username or full name, and logs which field
matched. That catches the common leak — a greeting, an account link — and nothing more. It
cannot see customer-group pricing, an "edit this entry" link, or an element that is visible
to one visitor and not another. Prove it yourself first: load a page signed in, fetch the
same URL cookie-free, and diff the two.

### Bucket 2: opt-in bypass cookies (bypass)

The names you list in [`bypassCookies`](configuration.md#bypasscookies), **empty by
default**. This is the escape hatch for a page state that genuinely must never come from
the shared copy, e.g. a commerce cookie that means "this visitor has a live cart", or your
own `edge_bypass` debugging cookie.

When a request carries one of these, Edge **bypasses the cache completely**, at the edge
tier *and* at the origin. The visitor gets a freshly rendered, fully personal page every
time, and nothing about that page is ever stored.

> Bypass is a per-*visitor* hammer: everyone carrying the cookie gets zero caching on
> every page. Prefer islands (per-*region* personalization on a still-cached page) and
> reach for a bypass cookie only when a whole visit truly can't be served shared HTML.

### Bucket 3: `Set-Cookie` responses (stripped before storing)

Even after buckets 1 and 2, a page Edge decides to cache might still *try* to set a
cookie (a plugin, a Twig template, or Craft itself starting a session mid-render). So the
cookie-safety layer, right before storing, **removes every `Set-Cookie`** from the
response: Yii-managed cookies, any queued `Set-Cookie` header, and even PHP's native
session cookie via `header_remove()`. It also strips `Vary`.

And then a **belt-and-braces** check: if a `Set-Cookie` somehow *still* survived (a plugin
wrote the header in a way that dodged the strip), Edge **refuses to store the response at
all** and downgrades it to `private, no-store`. A response that carries a cookie is never
cached, full stop.

> **The result:** a stored page is provably cookie-free. There is no code path that stores
> a response carrying a `Set-Cookie`. That's what makes the session leak impossible rather
> than merely unlikely.

Uncached responses (logins, POSTs, the `edge/csrf` and `edge/island` endpoints, bypassed
pages) keep their cookies untouched and are marked `private, no-store`. **Sessions still
start; they just start on pages that are never cached.**

## Making a cached page personal again

If cached pages are cookie-free and identical for everyone, how does a form on a cached
page get *this* visitor's CSRF token? How does a "Hi, Sarah" greeting work?

The answer: the personal bits are filled in **by the browser, after the cached shell
loads**, from endpoints that are never cached.

Edge ships a tiny vanilla-JS runtime (`edge-hydrate.js`, no framework, no build step)
that does two things when a cached page loads:

1. **CSRF hydration.** It fetches `edge/csrf`, an uncached endpoint that starts the
   visitor's session and returns *their* token, and injects that token into every CSRF
   hidden input and `<meta>` tag on the page. Now the form on the cached page carries the
   visitor's own valid token and submits normally.
2. **Island hydration.** For every `<div data-edge-island="cart">` placeholder in the
   cached HTML, it fetches `edge/island?name=cart` (again uncached, rendered for *this*
   visitor) and swaps the real content in.

So a cached page is an **anonymous shell** with **personal holes**, and the holes are
filled from uncached endpoints. The shell is shared by everyone and served from the edge;
the holes are per-visitor and rendered live. [Templating for the cache](templating.md) is
entirely about building pages this way.

```
┌─────────────────────────── cached, shared, cookie-free ──────────────────────────┐
│  <header> … </header>                                                             │
│  <h1>Autumn Collection</h1>                                                        │
│  <p>… marketing copy, product grid, everything anonymous …</p>                     │
│                                                                                    │
│   ┌── data-edge-island="cart" ──┐     ┌── CSRF input (empty in the cached HTML) ─┐ │
│   │ filled by edge/island in    │     │ filled by edge/csrf in the browser       │ │
│   │ the browser, per visitor    │     │ per visitor                              │ │
│   └─────────────────────────────┘     └──────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────────────────────┘
```

## Keeping the cache correct: invalidation

A cached page is a copy that can go stale. The hard problem is knowing *exactly which*
cached pages a given content change affects. Edge solves it by recording two kinds of
dependency for every page it caches, and taking the **union** of both when something
changes. It deliberately errs toward purging **too much**: a stale page is a bug, an
extra purge is just a re-render.

### The two dependency maps

While a cacheable page renders (step 3 of the miss above), Edge records:

1. **Element IDs: "which elements appear on this page."** Every element the page
   actually rendered (the entry you're viewing, entries pulled in by a related field, a
   category, etc.) is recorded by ID, in the `edge_cache_elements` table.
2. **Query tags: "what kind of content this page depends on."** Every element *query*
   the page ran exposes Craft's own cache tags via `ElementQuery::getCacheTags()` (e.g.
   "any entry in section 5"). Those tags are recorded in the `edge_cache_tags` table.

Why both? Because they catch different changes:

- The **ID map** catches edits to content *already on* the page. Edit entry 42 -> purge
  every page that rendered entry 42.
- The **tag map** catches content that *would now match a listing but didn't exist when
  the page was cached*. Publish a brand-new blog post -> it has no ID on any cached page
  yet, but the blog index recorded the tag "any entry in the blog section", so the index
  is purged and rebuilt with the new post. The ID map alone would miss this; this is the
  single most common way naive caches go stale.

### From a save to a set of purges

When an editor saves, deletes, restores, or reorders content, Craft fires its own cache
invalidation with a set of tags (Edge listens on `Elements::EVENT_INVALIDATE_CACHES`,
plus save/delete/restore/structure events as a backstop). Edge:

1. Collects the fired tags and the changed element IDs for the whole request.
2. Resolves the **union**: every cached URL whose recorded tags match, **plus** every
   cached URL that recorded one of the changed element IDs.
3. Deletes those rows and dispatches **background queue jobs** to purge them at the edge,
   and, optionally, to re-warm them so the next visitor still gets a hit.

Nothing purges *inline*. The editor's Save returns immediately; the purge happens a moment
later when the queue runs. Draft and revision saves never purge live URLs.

### When precise resolution isn't possible: the coarse flush

Some changes can't be resolved to a precise URL set, so Edge flushes the **entire managed
tier** instead (still asynchronously). These are:

- **Global set** changes: global content is read via the `globals` variable, not an
  element query, so there's no query tag to match. Everything flushes.
- **Plugin settings saved**, or **any plugin installed / removed / enabled / disabled**:
  these can change how *any* page renders.
- Craft firing its "**all element caches**" signal.

Coarse flushes are rare in normal editing. They're the safety valve: when Edge can't
prove which pages are affected, it assumes all of them are. Full detail on
[the invalidation page](invalidation.md).

## The `X-Edge-Origin` header

Every response that **PHP actually rendered** carries `X-Edge-Origin: 1`. A response
served from the static cache by nginx does **not** have it (nginx never added it, and PHP
never ran). This single header is how you (and Edge's own `verify` command) can tell a
hit from a miss at a glance:

- `X-Edge-Origin: 1` present -> PHP rendered this (a miss, or an uncacheable/bypassed page).
- header absent on `nginx-static` -> served from a static file (a **hit**).

The FastCGI and Cloudflare tiers add their own status headers too (`X-Edge-Cache: HIT`
from `$upstream_cache_status`, and Cloudflare's `CF-Cache-Status: HIT`). The
[troubleshooting page](troubleshooting.md) shows how to read all of them with `curl`.

## Where the pieces live in the code

If you want to read along in the source, here's the map:

| Concept | File |
| --- | --- |
| Request lifecycle wiring, events | `src/Plugin.php` |
| The cacheability decision (every rule) | `src/services/Cacheability.php` |
| Request snapshot | `src/models/RequestContext.php` |
| Tracking + storing a page, the two maps | `src/services/Generator.php` |
| Resolving changes -> purges, coarse flush | `src/services/Invalidator.php` |
| Cookie-safety layer, cache headers | `src/drivers/BaseDriver.php` |
| Per-tier storage/purge | `src/drivers/{NginxStatic,NginxFastCgi,Cloudflare}Driver.php` |
| Queue jobs (purge with retry, warm) | `src/jobs/{PurgeJob,WarmJob}.php` |
| CSRF / island endpoints | `src/controllers/DynamicController.php` |
| Browser hydration runtime | `src/web/assets/hydrate/dist/edge-hydrate.js` |
| `{{ edgeIsland() }}` Twig function | `src/twig/EdgeExtension.php` |
| Settings + config keys | `src/models/Settings.php`, `config/edge.php` |

Next: **[Installation](installation.md)**.
