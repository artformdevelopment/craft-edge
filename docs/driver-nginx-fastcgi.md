# Driver: nginx-fastcgi

nginx's built-in `fastcgi_cache` stores PHP responses as they stream through nginx, and
serves them on the next request before re-invoking PHP. Edge doesn't write files for this
driver: it controls **what** gets stored (through the origin's `Cache-Control` headers)
and **purges** through the `ngx_cache_purge` module.

Choose this driver if you already run FastCGI caching, or you want nginx to own the cache
storage/eviction. If you're unsure, [`nginx-static`](driver-nginx-static.md) is simpler.

- [How it works](#how-it-works)
- [Step 1: prepare the environment](#step-1-prepare-the-environment)
- [Step 2: connect Edge to it](#step-2-connect-edge-to-it)
- [Step 3: verify](#step-3-verify)
- [The purge path, explained](#the-purge-path-explained)
- [Limitations](#limitations)

## How it works

1. Anonymous request misses -> nginx passes to PHP -> Craft renders -> the response streams
   back through nginx, which **stores it in the FastCGI cache** because Edge marked it
   `public, max-age=...` and cookie-free.
2. Next anonymous request -> nginx serves it from the FastCGI cache, adding
   `X-Edge-Cache: HIT`. PHP isn't invoked.
3. On a content change, Edge's queue job sends an HTTP request to the **purge location**
   (`ngx_cache_purge`), which evicts that key from the cache.

Two independent safeguards keep private pages out of the cache:

- **Origin headers (primary).** nginx will not store a response carrying `Set-Cookie` or
  `Cache-Control: private/no-store`. Since Edge sends exactly those on every uncacheable
  or bypassed page, they're never stored, even if the nginx bypass map were misconfigured.
- **The bypass map (serving-side guard).** `fastcgi_cache_bypass`/`fastcgi_no_cache` on
  `$edge_skip` means a request that must be answered live (non-GET, a preview/token
  param, or an opt-in bypass cookie) is never *served* a cached copy and never *writes*
  one.

## Step 1: prepare the environment

1. **Install the `ngx_cache_purge` module.** This is the one hard requirement.

   ```bash
   # Debian / Ubuntu:
   apt-get install libnginx-mod-http-cache-purge
   ```

   Stock nginx does **not** include it. If it's missing, nginx will reject the
   `fastcgi_cache_purge` directive with "unknown directive" and fail to start. On distros
   where modules aren't auto-loaded, add near the top of `nginx.conf`:

   ```nginx
   load_module /usr/lib/nginx/modules/ngx_http_cache_purge_module.so;
   ```

2. **Install the server config.** Merge [`docs/nginx-fastcgi.conf`](nginx-fastcgi.conf)
   into your Craft server block. It adds: a `fastcgi_cache_path` zone, the
   `fastcgi_cache*` directives inside your PHP `location`, and a locked-down
   `/edge-purge` location. Read the header: adjust the bypass-cookie regex and the cache
   zone size.

3. **Create the cache directory** if your `fastcgi_cache_path` points somewhere that
   doesn't exist yet, and make sure nginx can write to it.

4. **Reload:** `nginx -t && systemctl reload nginx`.

## Step 2: connect Edge to it

```php
// config/edge.php
return [
    'driver'          => 'nginx-fastcgi',
    'fastCgiPurgeUrl' => 'http://127.0.0.1/edge-purge',   // the purge location
];
```

`fastCgiPurgeUrl` is the base URL of the `/edge-purge` location from the config. Edge
appends the URI to purge and sends a `GET` with the site's `Host` header. Point it at
`127.0.0.1` (or a Unix path fronted by nginx) so purges never leave the box. It supports
`$ENV_VAR` references.

## Step 3: verify

```bash
./craft edge/nginx/verify --url=https://your-site.test/
```

Expected:

```
GET #1: HTTP 200 X-Edge-Cache: MISS
GET #2: HTTP 200 X-Edge-Cache: HIT
Set-Cookie on cached response: none (correct)
Cache-Control: public, max-age=31536000
nginx verification PASSED.
```

If `X-Edge-Cache` is `(missing)`, the `add_header X-Edge-Cache ...` line isn't in your PHP
`location`, or the request isn't matching that location. If GET #2 is `MISS`, the response
isn't being stored: check that the origin is actually sending `public, max-age` (i.e. the
page is cacheable) and that the bypass map isn't set to skip it.

Confirm purging works end to end:

```bash
# prime a page:
curl -sSI https://your-site.test/blog | grep -i x-edge-cache   # MISS then HIT
# purge just that URL:
./craft edge/cache/clear-url https://your-site.test/blog
# next request is a MISS again:
curl -sSI https://your-site.test/blog | grep -i x-edge-cache
```

## The purge path, explained

This is the fiddliest part of the driver, so here's the whole chain:

1. Edge wants to purge `https://example.com/blog`. It sends:
   `GET http://127.0.0.1/edge-purge/blog` with header `Host: example.com`.
2. nginx matches `location ~ ^/edge-purge(/.*)$`, capturing `$1 = /blog`.
3. `fastcgi_cache_purge edge "$host$1$is_args$args"` rebuilds the key
   `example.com/blog`, exactly the key the page was stored under
   (`$host$request_uri` = `example.com/blog`), and evicts it.

Because the key is **scheme-less** (`$host...`, not `$scheme$host...`), the plaintext purge
request over `http` correctly matches a page that visitors loaded over `https`. That's why
the config uses `$host$request_uri` and not `$scheme$host$request_uri`.

Edge treats a purge that returns HTTP **200** (purged) or **404** (wasn't cached, already
gone) as success. If a purge is accidentally routed to PHP instead of the purge module, the
response carries `X-Edge-Origin`, and Edge raises a clear error telling you the purge
location is missing. It never silently pretends a purge succeeded.

## Limitations

- **No native wildcard flush.** `ngx_cache_purge` only supports wildcard purges (`/*`) when
  compiled with that option, which most packaged builds are not. So on a **coarse flush**
  (global set change, plugin change, or `./craft edge/cache/clear`), Edge purges every URL
  it knows about **individually** rather than relying on a wildcard. If your cache also
  contains URLs Edge never recorded (e.g. cached before install), clear the
  `fastcgi_cache_path` directory by hand and reload nginx to be thorough.
- **Cache lives on one node.** Like `nginx-static`, the FastCGI cache is per-server. For
  multi-server setups, run the purge queue on each node (each pointing `fastCgiPurgeUrl` at
  its own nginx) or use the `cloudflare` driver.

Next: [Configuration reference](configuration.md) | [Templating for the cache](templating.md).
