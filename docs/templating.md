# Templating for the cache

This is the page that matters most day to day. Full-page caching changes one rule of how
you write templates:

> **A cacheable page is rendered once and shown to everyone. So a cached template must not
> bake in anything that belongs to a single visitor or a single moment.**

Get that right and everything works. Get it wrong and you either leak one visitor's data
into the cache, or you cache a "logged out" navbar that every logged-in visitor then sees.
This page shows you how to think about it, the two tools Edge gives you (empty CSRF inputs
and islands), and a checklist of things that quietly break caching.

- [The anonymous shell](#the-anonymous-shell)
- [What counts as per-visitor](#what-counts-as-per-visitor)
- [Forms & CSRF on cached pages](#forms--csrf-on-cached-pages)
- [Islands: per-visitor content](#islands-per-visitor-content)
- [The hydration script](#the-hydration-script)
- [The logged-in navbar problem](#the-logged-in-navbar-problem)
- [Anti-patterns checklist](#anti-patterns-checklist)
- [Testing your templates](#testing-your-templates)

## The anonymous shell

Think of every cacheable page as an **anonymous shell**: the HTML that is identical for
every not-logged-in visitor. The article body, the product grid, the marketing copy, the
footer: none of that changes per person, so all of it caches perfectly and needs no special
handling. Write those templates exactly as you always have.

The only parts that need thought are the **holes** in the shell: the bits that *are*
per-visitor. Edge gives you two ways to fill a hole, both filled **in the browser after the
cached shell loads**, from endpoints that are never cached:

| Hole | Fill it with |
| --- | --- |
| A form's CSRF token | An **empty** CSRF input that the hydration script fills. |
| A chunk of personalized markup (cart, "Hi Sarah", recently-viewed) | An **island**: `{{ edgeIsland('name') }}`. |

Everything else is just the shell. If a page has *no* forms and *no* personalized regions,
it needs zero changes; it caches as-is.

## What counts as per-visitor

Before you cache a page, scan its template for anything that differs between two anonymous
visitors, or between two page loads. If you find any of these in a cacheable template, it
needs to move into an island (or the page needs to be excluded):

- **The current user**: `currentUser`, `craft.app.user.identity`, "logged in as ...",
  account menus. (Every visitor, signed in or not, is served the shared shell, so whatever
  user state the shell rendered is what everyone sees, see
  [the navbar problem](#the-logged-in-navbar-problem).)
- **CSRF tokens**: `csrfInput()`, `craft.app.request.csrfToken`. These are per-session.
  See [Forms & CSRF](#forms--csrf-on-cached-pages).
- **Session / flash data**: `craft.app.session.getFlash(...)`, cart contents, "you have 3
  items", one-time notices.
- **Request specifics**: the visitor's IP, `craft.app.request.userAgent`, cookies,
  geolocation, A/B-test buckets.
- **"Now"**: `now`, `date()`, "posted 3 minutes ago" relative times, countdowns. These
  freeze at the moment the page was first rendered and then stay frozen until the next
  purge. Absolute dates (`entry.postDate|date('M j, Y')`) are fine, they don't change.
  Relative times and countdowns are not; make them client-side, or accept the freeze.
- **Randomness**: `|shuffle`, `random()`, "featured product of the moment". Whatever was
  random at first render is now the same for everyone until purge.

None of these are *errors* in Twig; they'll render fine. The issue is that the result gets
frozen into a shared copy. That's what islands are for.

## Forms & CSRF on cached pages

Craft protects forms with a CSRF token tied to the visitor's session. But a cached page is
shared and cookie-free, so there's no single visitor to make a token for. If you called
`csrfInput()` in a cacheable template, the **first** visitor's token would be baked into the
HTML and served to everyone; their submissions would fail CSRF validation (or worse, share
a token).

**The pattern:** render the CSRF field **empty** and let the hydration script fill it with
each visitor's real token after load.

```twig
{# A guestbook / contact / newsletter form on a CACHED page #}
<form method="post" accept-charset="UTF-8">
    {# Empty on purpose: edge-hydrate.js fills it in the browser, per visitor. #}
    <input type="hidden"
           name="{{ craft.app.config.general.csrfTokenName }}"
           value="">

    <input type="hidden" name="action" value="guest-entries/save">
    {{ redirectInput('thanks') }}

    <input type="text" name="fields[authorName]" required>
    <textarea name="fields[body]" required></textarea>
    <button type="submit">Sign the guestbook</button>
</form>
```

What happens at runtime:

1. The cached shell arrives with `value=""` in the CSRF input.
2. `edge-hydrate.js` fetches `edge/csrf` (uncached: this is where the visitor's session
   legitimately starts and their token is minted) and sets the `value` of every input named
   after `csrfTokenName`, plus any `<meta name="csrf-token">`.
3. The visitor submits. The POST is an action request (never cached) carrying their own
   cookies and token. Craft validates it normally.

> **Do not call `csrfInput()` on a cacheable page.** It's the single most common mistake.
> Use the empty-input pattern above. On pages that are *always* dynamic (excluded URIs,
> logged-in-only pages), `csrfInput()` is fine as usual: those aren't cached.

### JavaScript that needs the token

If your JS reads the token from a meta tag, render it empty too and listen for the
`edge:csrf` event, which fires once hydration has filled it:

```twig
<meta name="csrf-token" content="">
<meta name="csrf-param" content="{{ craft.app.config.general.csrfTokenName }}">
```

```js
document.addEventListener('edge:csrf', function (e) {
    // e.detail = { token, param }. Safe to fire authenticated fetches now.
    myApi.setCsrf(e.detail.param, e.detail.token);
});
```

If CSRF protection is disabled (`enableCsrfProtection: false`), `edge/csrf` returns
`{token: null}` and hydration simply does nothing: forms submit without a token, as Craft
expects.

## Islands: per-visitor content

An **island** is a region of a cached page whose content is rendered fresh for each visitor,
in the browser, from an uncached endpoint. Use one wherever you'd otherwise bake per-visitor
markup into the shell: a mini-cart, a greeting, "recently viewed", a personalized CTA.

### 1. Drop a placeholder in the cached template

```twig
{# In a cached template, e.g. the site header #}
<div class="site-header">
    <a href="/">{{ siteName }}</a>
    <nav>{# ...anonymous nav... #}</nav>

    {# Per-visitor. Renders nothing in the cached HTML; filled after load. #}
    {{ edgeIsland('account-menu') }}

    {# Optional placeholder shown until the real content arrives: #}
    {{ edgeIsland('cart', '<span class="cart-loading">Cart</span>') }}
</div>
```

`{{ edgeIsland('name') }}` outputs `<div data-edge-island="name">...placeholder...</div>` and
registers the hydration script. The **placeholder** (second argument, optional) is what
shows in the cached HTML until the real fragment swaps in. Use it to avoid layout shift or
to give no-JS visitors something sensible.

### 2. Create the island template

Islands render from the `islandsTemplatePath` folder (default `templates/_edge/islands/`).
An island named `account-menu` renders `templates/_edge/islands/account-menu.twig`, live,
for the current visitor, so **inside an island, all the per-visitor data you avoided in the
shell is allowed**:

```twig
{# templates/_edge/islands/account-menu.twig #}
{% if currentUser %}
    <a href="/account">Hi, {{ currentUser.friendlyName }}</a>
    <form method="post"><input type="hidden" name="{{ craft.app.config.general.csrfTokenName }}" value="{{ craft.app.request.csrfToken }}">
        <input type="hidden" name="action" value="users/logout">
        <button>Log out</button>
    </form>
{% else %}
    <a href="/login">Sign in</a>
{% endif %}
```

Because the island endpoint is uncached and runs per request, `currentUser`,
`craft.app.request.csrfToken`, session data, and the cart are all real and correct here.
(An island fetched by a *logged-in* visitor's browser carries their cookies, so
`currentUser` is populated: that's the whole point.)

### How islands behave

- The fetch to `edge/island?name=account-menu` is `private, no-store` and carries the
  visitor's cookies (`credentials: same-origin`).
- On success, the placeholder `<div>`'s `innerHTML` is replaced, it gets
  `data-edge-hydrated="1"`, and an `edge:island` event fires (`detail: {name, element}`).
- **A failed or missing island never breaks the page.** A bad `name` 404s, a template that
  throws returns an empty fragment, and either way the placeholder is left in place with a
  `console.warn`; the surrounding cached page is unaffected.
- Island names are validated: letters, numbers, `_`, `-`, `/` only, no `..`. So
  `edgeIsland('shop/cart')` -> `_edge/islands/shop/cart.twig` works; path traversal doesn't.

### Islands vs. exclude-the-page

If **most** of a page is per-visitor (an account dashboard, a checkout), don't island every
piece, just [exclude the whole URI](configuration.md#excludeduripatterns) and let it render
dynamically. Islands are for a **few** personal holes in a **mostly-shared** page. A page
that's more hole than shell shouldn't be cached at all.

## The hydration script

`edge-hydrate.js` is a tiny (~2 KB) vanilla-JS runtime: no framework, no build step. With
`autoInjectHydrationScript: true` (the default) it's added automatically to every front-end
GET page, including bypassed and logged-in pages (so forms hydrate everywhere, not just on
cached pages). On load it:

1. Fetches `edge/csrf` and injects the token into CSRF inputs and meta tags -> fires
   `edge:csrf`.
2. Fetches every `[data-edge-island]` fragment and swaps it in -> fires `edge:island` per
   island.

It runs on `DOMContentLoaded` (or immediately if the DOM is already ready). It's pure
progressive enhancement: with JS disabled, the cached shell still renders, forms still show
(with empty tokens: they'll fail validation without JS, which is the same trade-off any
token-hydration approach makes), and island placeholders stay put.

If you'd rather control loading yourself, set `autoInjectHydrationScript: false` and register
the asset where you want it, but note that `{{ edgeIsland() }}` also registers the script on
demand, so a page with an island always gets it regardless of the setting.

## The logged-in navbar problem

A subtle trap worth calling out. Suppose your header does this in a **cached** template:

```twig
{% if currentUser %}<a href="/account">My account</a>{% else %}<a href="/login">Sign in</a>{% endif %}
```

The cached copy is rendered from an anonymous request, so it contains the "Sign in"
branch, and **every** visitor served the shell sees that, including a visitor who is
signed in. Their account link only looks right on the rare page that happens to render
live for them; on every cached page they're told to sign in again. The shell must not
carry user state at all: move any `currentUser`-dependent markup into an
[island](#islands-per-visitor-content) so it's always resolved per visitor, live, in the
browser.

Rule of thumb: **if a branch depends on `currentUser`, it belongs in an island**, not in the
cached shell.

## Anti-patterns checklist

Quick scan before you cache a template. Each of these belongs in an island, a dynamic page,
or client-side JS, not in cached HTML:

| Don't put this in a cached shell | Do this instead |
| --- | --- |
| `csrfInput()` | An empty CSRF input, hydrated by the script. |
| `{{ currentUser.friendlyName }}` | An island. |
| Cart count / totals | An island. |
| `craft.app.session.getFlash('notice')` | An island, or show it on the (uncached) redirect target. |
| `now`, relative "x minutes ago", countdowns | Client-side JS, or accept the freeze. |
| `\|shuffle`, `random()` for "featured now" | An island, or accept it's fixed until the next purge. |
| Visitor IP / geo / user-agent branching | An island, or edge-level logic. |

Calling `csrfInput()` *inside* an island is redundant but fine: islands are live, so
`craft.app.request.csrfToken` works there directly.

## Testing your templates

- **Bypass on demand:** append `?no-cache=1` to any URL to force a fresh, uncached render.
  Handy for comparing "what an anonymous visitor's fresh render looks like" against the
  cached copy.
- **Verify no leak:** `./craft edge/nginx/verify --url=...` (or the cloudflare variant) checks
  a second hit is served from the edge with **no `Set-Cookie`**. If it ever reports a
  Set-Cookie leak, a template or plugin is setting a cookie on a cacheable page; find it
  before shipping.
- **Two-browser test for personalization:** open the same cached URL in two different
  browsers/profiles, log into one. The cached shell should be identical in both; only the
  islands and form tokens should differ. If browser B ever shows browser A's name or cart,
  that content is in the shell and must move to an island.
- **Confirm islands hydrate:** load a page with an island and watch the network tab for the
  `edge/island?name=...` request and the DOM swap (the placeholder gets
  `data-edge-hydrated="1"`).

Next: [Invalidation & warming](invalidation.md) | [Troubleshooting](troubleshooting.md).
