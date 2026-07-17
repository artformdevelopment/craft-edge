# Driver: nginx-static

Edge renders each cacheable page once and writes the finished HTML to a file. nginx serves
that file directly with `try_files`, **before PHP is loaded**. Purging a page is just
deleting its file. No special nginx modules are required, which makes this the simplest and
most predictable driver, and the recommended default.

- [How it works](#how-it-works)
- [Step 1: prepare the environment](#step-1-prepare-the-environment)
- [Step 2: connect Edge to it](#step-2-connect-edge-to-it)
- [Step 3: verify](#step-3-verify)
- [How files are laid out](#how-files-are-laid-out)
- [Keeping other tiers pass-through](#keeping-other-tiers-pass-through)
- [Operational notes](#operational-notes)

## How it works

1. An anonymous request misses (no file yet) -> nginx passes it to PHP -> Craft renders it ->
   Edge strips cookies and **writes the HTML to `cachePath/host/uri/index.html`**.
2. The next anonymous request for that URL -> nginx's `try_files` finds the file and returns
   it directly. PHP never runs.
3. When the content changes, Edge's queue job **deletes the file(s)**. The next visitor
   misses, re-renders, and re-writes, or the warm job does it for them first.

nginx decides *per request* whether it may serve a file, using a small `map`/`if` block
that mirrors the plugin's own rules: only `GET`/`HEAD`, no opt-in bypass cookie, no
preview/token/no-cache query param. If any of those fail, nginx skips the file and falls
through to PHP, which serves a fresh `private, no-store` response. Login cookies are
deliberately **not** in the bypass map: the shell is anonymous by construction, so a
signed-in visitor is served the same shared file as everyone else and personalizes
client-side through the [island endpoints](templating.md#islands-per-visitor-content).

The cache directory sits **outside the web root** (default `@storage/edge-cache`), so a
cache file has no URL of its own; nobody can fetch one directly. nginx reads it through an
internal named location (`@edge` in the reference config) that `location /` falls through
to before PHP: real files from the web root first, then the cache, then Craft.

> **Why this is safe even without nginx knowing your exclude rules:** if the origin decided
> a page shouldn't be cached, it simply never wrote a file for it, so there's nothing for
> `try_files` to find and the request falls through to PHP automatically. The nginx bypass
> `map` only needs to handle the case where a file *does* exist but *this* request must not
> be served it (a preview token, a configured bypass cookie). Missing files take care of
> themselves.

## Step 1: prepare the environment

You need nginx (any build, no modules) and a writable cache directory.

1. **Install the server config.** Take
   [`docs/nginx-static.conf`](nginx-static.conf) and merge its `map` blocks (http/server
   scope) and `location` blocks into your site's existing nginx server block. Read the
   header comment; there are four things to adjust, most importantly the `@edge` cache
   `root` and (if you configure any bypass cookies) the bypass-cookie map. The config's
   two load-bearing details, spelled out in its header: `location /` must fall through
   straight to `@edge` (no `$uri/` entry, or the homepage serves `index.php` instead of
   the cache), and the `.php` location must pin `root` back to the web root because
   `@edge` switches it to the cache directory.

2. **Make the cache directory writable** by the PHP-FPM user. With the default
   `cachePath` of `@storage/edge-cache`:

   ```bash
   mkdir -p storage/edge-cache
   chown -R www-data:www-data storage/edge-cache   # match your php-fpm user
   ```

   nginx needs read access to it too. It's regenerated content, not source; `storage/` is
   already in a standard Craft deploy-ignore list.

3. **Keep the bypass-cookie map in sync.** The default
   [`bypassCookies`](configuration.md#bypasscookies) is empty, so the shipped map matches
   nothing. If you add a live-cart cookie or your own `edge_bypass` cookie to
   `bypassCookies`, list it in the map too, otherwise nginx would serve a cached page to a
   visitor the plugin intended to bypass.

4. **Reload nginx:** `nginx -t && systemctl reload nginx`.

## Step 2: connect Edge to it

Set the driver and the cache path so the plugin writes files where nginx looks for them.
The two paths **must agree**.

```php
// config/edge.php
return [
    'driver'    => 'nginx-static',
    'cachePath' => '@storage/edge-cache',   // must equal the @edge `root` in the nginx config
];
```

`cachePath` supports Craft aliases (`@storage`) and environment variables
(`$EDGE_CACHE_PATH`). The `root` inside the nginx config's `@edge` location is that same
directory as an absolute path: with the default, `@storage/edge-cache` on the plugin side
is `/var/www/html/storage/edge-cache` (or wherever your project lives) on the nginx side.

That's the whole connection. There is nothing to "set up" at the tier the way Cloudflare
has: nginx serving files is stateless.

## Step 3: verify

```bash
./craft edge/nginx/verify --url=https://your-site.test/
```

Expected:

```
GET #1: HTTP 200 (rendered by PHP, MISS)
GET #2: HTTP 200 (served by nginx static file, HIT)
Set-Cookie on cached response: none (correct)
Cache-Control: (none: normal for a static-file hit; nginx serves the raw file)
nginx verification PASSED.
```

(A static-file hit carries no `Cache-Control` header: nginx serves the raw file, and the
`public, max-age` header the origin sent only appears on the priming miss. That's fine;
correctness comes from purging the file, not from browser TTLs.)

The check that matters: **GET #2 must be served by nginx**, i.e. it must **not** carry
`X-Edge-Origin`. If GET #2 says "rendered by PHP, NOT served from the static cache", nginx
didn't find the file. Usual causes:

- `cachePath` and the `@edge` location's `root` don't point at the same directory.
- `location /` doesn't fall through to `@edge` (its `try_files` must be
  `try_files $uri @edge;`), or another `location` is shadowing it.
- The file was never written: check permissions and look for an Edge warning in the logs.

By hand:

```bash
# Miss then hit: the second response should NOT contain x-edge-origin:
curl -sSI https://your-site.test/ | grep -i x-edge-origin   # 1st: present
curl -sSI https://your-site.test/ | grep -i x-edge-origin   # 2nd: absent -> HIT
```

## How files are laid out

Understanding the layout helps when debugging:

| Request | Stored file (under `cachePath`) |
| --- | --- |
| `https://example.com/` | `example.com/index.html` |
| `https://example.com/blog` | `example.com/blog/index.html` |
| `https://example.com/blog/hello-world` | `example.com/blog/hello-world/index.html` |

The host segment is the site's base-URL host with any port stripped (so it matches
nginx's `$host`, which never includes a port; a site on `http://localhost:8080` stores
under `localhost/`). The plugin **refuses to write a file whose path would escape the site
directory**: a traversal-shaped URI (`../`) never produces a cache file. You can safely
`ls` the cache directory to see exactly what's cached, and `rm -rf` it to clear everything
(or use `./craft edge/cache/clear`, which also clears the dependency tables).

## Keeping other tiers pass-through

If your site *also* sits behind Cloudflare while you use `nginx-static`, Cloudflare must
**not** cache HTML: keep its cache level at Standard (which respects origin headers) and
don't add a "Cache Everything" page rule for HTML. Edge sends `public, max-age=...` on
cacheable pages, so Cloudflare *would* cache them if told to "cache everything", but Edge
only purges nginx, so Cloudflare copies would go stale. Let nginx be the one HTML cache.
Static assets (CSS/JS/images) behind Cloudflare are fine.

## Operational notes

- **A write failure never breaks the page.** If the cache directory is read-only or full,
  Edge logs a warning and serves the response dynamically. Your site stays up; it just
  isn't cached until you fix the disk.
- **Clearing:** `./craft edge/cache/clear` empties the directory and the dependency tables.
  Deleting the directory by hand is also safe, the plugin recreates paths as needed. If you
  delete files out from under the dependency tables, run `clear` to resync.
- **Deploys:** the cache survives a deploy unless you clear it. If a deploy changes
  templates in a way that should invalidate everything, clear the cache as a deploy step
  (or bump content). Editorial changes are handled automatically; template/code changes are
  not something Edge can detect.
- **Multiple app servers:** each server has its own `cachePath` on its own disk. Edge's
  purge deletes files on the server running the queue. For a multi-server setup, either
  share the cache directory (NFS), run the queue on each node, or use the `cloudflare`
  driver where the cache is central.

Next: [Configuration reference](configuration.md) | [Templating for the cache](templating.md).
