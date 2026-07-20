# Reverse proxy and client IP

Edge puts a caching tier in front of Craft. That tier is a reverse proxy, and the moment
one exists, Craft stops seeing the client directly: the TCP connection comes from the
proxy, and the real client details arrive in `X-Forwarded-*` headers instead.

Get this wrong in the obvious direction and you break HTTPS detection. Get it wrong in the
other direction and every visitor can choose their own apparent IP address, which quietly
disables login throttling, rate limiting, and `preventUserEnumeration`.

This page covers the two configurations that are correct. Edge itself contains no IP
handling at all — this is Craft and proxy configuration — but Edge is the reason you now
need it, so it belongs here.

## The trap

Craft's `trustedHosts` decides whether the forwarded headers are believed. It is checked
against `REMOTE_ADDR`. So the question that decides everything is: **what does your origin
see in `REMOTE_ADDR`?**

If the answer is "the proxy", trusting the proxy's address works. If something upstream has
already rewritten `REMOTE_ADDR` to the client's address — Apache's `mod_remoteip` does
exactly this — then the trusted-host check compares the *client* IP against your proxy list,
fails, and Craft ignores `X-Forwarded-Proto`. Your site starts emitting `http://` asset URLs
and mixed-content warnings.

The tempting fix is `trustedHosts(['any'])`. Do not stop there. On its own that is a
security hole, because Yii reads the **leftmost** `X-Forwarded-For` entry, and the standard
nginx idiom *appends* to that header:

```nginx
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
```

A client that sends `X-Forwarded-For: 203.0.113.9` gets `203.0.113.9, <real client>`
forwarded. A correctly configured proxy walks that list from the right and gets the real
address. Craft, trusting everything and reading from the left, gets the forged one.

## Recipe A — the proxy sets `REMOTE_ADDR`, Craft does nothing

Use this when your origin already resolves the real client IP, e.g. Apache with
`mod_remoteip`, which is also what your access logs and any WAF rules depend on:

```apache
RemoteIPHeader X-Forwarded-For
RemoteIPTrustedProxy 127.0.0.1
RemoteIPTrustedProxy ::1
```

Then in `config/general.php`, tell Craft **not** to re-derive the IP:

```php
->trustedHosts(['any'])
->ipHeaders([])
```

`ipHeaders([])` makes Craft use `REMOTE_ADDR`, which the origin has already validated
against its own trusted-proxy list. The forged header cannot win, because Craft never reads
it. `trustedHosts(['any'])` is required here — `REMOTE_ADDR` is now a client address, so no
CIDR could ever match — and it is safe **only if both of these hold**:

- the origin is not reachable except through your proxy (bind it to `127.0.0.1`), and
- the proxy sets every forwarded header explicitly, so a client cannot inject them:

```nginx
proxy_set_header Host              $host;
proxy_set_header X-Real-IP         $remote_addr;
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host  $host;
```

Pair it with a catch-all `default_server` that refuses unmatched hosts, so `Host` itself is
constrained to your real hostnames. See `docs/nginx-static.conf`.

## Recipe B — the origin sees the proxy, Craft resolves the client

Use this when nothing has rewritten `REMOTE_ADDR`, e.g. nginx straight to php-fpm, or Apache
with `mod_remoteip` disabled:

```php
->trustedHosts(['127.0.0.1', '::1'])
->ipHeaders(['X-Forwarded-For', 'X-Real-IP'])
```

Substitute your actual proxy addresses. Craft only believes the headers when the connection
came from one of them, so a forged `X-Forwarded-For` from a real client is ignored.

If you take this route with Apache in the mix, remember that removing `RemoteIPHeader` means
Apache logs and any ModSecurity rules now see `127.0.0.1` for every request. Move that
responsibility deliberately, not by accident.

## Which one you have

```
Does anything rewrite REMOTE_ADDR before Craft sees it?
├─ yes (mod_remoteip, or a PaaS that does it for you)  ->  Recipe A
└─ no                                                  ->  Recipe B
```

Check it on the box rather than guessing:

```bash
apachectl -M | grep remoteip
grep -rn "RemoteIP" /etc/apache2/
```

## Verifying

Edge warns about the dangerous combination in the **Edge Cache** utility and in
`php craft edge/nginx/verify`: `trustedHosts` containing `any` while `ipHeaders` is set is
always wrong, because it is exactly the leftmost-entry hole above.

To confirm the IP Craft actually resolves, hit any uncached URL with a forged header and
check what gets logged or throttled:

```bash
curl -H 'X-Forwarded-For: 203.0.113.9' https://example.com/some-uncached-page
```

Under either correct recipe, `203.0.113.9` must not appear as the client address.

## Why Edge cares

Two of Edge's guarantees depend on this being right:

- **Absolute URLs.** Craft builds `siteUrl()`, `@web`, canonical tags and form actions from
  the request `Host`. A cached page is shared by every visitor, so a response rendered
  against the wrong `Host` would hand those URLs to everyone. Edge refuses to store a
  response whose `Host` doesn't match the site's configured base URL, but a
  `default_server` that rejects unmatched hosts is the better first line.
- **HTTPS detection.** If Craft doesn't trust `X-Forwarded-Proto`, it renders `http://`
  URLs, and those get cached too.
