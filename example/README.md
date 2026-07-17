# Edge example: Craft CMS 5 + nginx-static, in Docker

A complete, runnable Craft CMS 5 site demonstrating the Edge plugin's `nginx-static`
driver: anonymous pages served by nginx as static files (PHP never boots on a hit),
per-visitor content hydrated through islands, and exact queue-driven invalidation.

The stack follows the plugin docs: [`docs/installation.md`](../docs/installation.md),
[`docs/driver-nginx-static.md`](../docs/driver-nginx-static.md) and
[`docs/templating.md`](../docs/templating.md). The plugin itself is loaded from the
repository root via a Composer path repository, so changes to `../src` apply directly.

## Requirements

Docker with the compose plugin, and port 80 free on your machine. Nothing else: PHP
and Composer run inside the containers.

## Start it

```bash
cd example
./setup.sh
```

First run takes a few minutes (image build + composer install + Craft install). When it
finishes:

| What | Where |
| --- | --- |
| Site | http://localhost/ |
| Control panel | http://localhost/admin (`admin` / `EdgeExample123!`) |
| Second user (front-end sign-in) | `jane` / `EdgeExample123!` |

`./setup.sh reset` tears everything down (containers, database volume, generated files)
and `./setup.sh` rebuilds from scratch. The script is idempotent; re-run it freely.

## The stack

| Service | Image | Role |
| --- | --- | --- |
| `web` | nginx:1.27-alpine | Serves cache files from `storage/edge-cache` (outside the web root) via the internal `@edge` location before PHP; config in `nginx/default.conf`, merged from `docs/nginx-static.conf` |
| `php` | `Dockerfile` (php:8.3-fpm-alpine) | Craft CMS 5 (Pro trial), Edge plugin |
| `queue` | same image | `craft queue/listen`, which runs the purge/warm jobs |
| `db` | mysql:8.0 | Craft's database |

`php` and `queue` share `web`'s network namespace, so `http://localhost` resolves to
nginx from inside every container exactly as it does from your browser. One site URL
works for browser hits, `edge/nginx/verify`, and the queue's warm requests, like a
single-VPS deployment.

## Things to try

- **Watch a hit.** `curl -sI http://localhost/` twice. The first response carries
  `X-Edge-Origin: 1` (PHP rendered it); the second doesn't (nginx served the file).
  The files live in `storage/edge-cache/localhost/`, outside the web root: try
  `curl -sI http://localhost/storage/edge-cache/localhost/index.html` and note you
  can't fetch one directly.
- **Watch invalidation.** Publish or edit a blog post in the CP, then reload
  http://localhost/blog: the queue purges and re-warms the affected pages within
  seconds. `docker compose logs -f queue` shows the jobs.
- **Prove session isolation.** Open the site in two browsers (or one normal + one
  private window). Each gets its own visit counter and greeting; the shell around them
  is byte-identical. Sign in as `jane` in one: both browsers keep getting cache hits
  on the same shared shell, while the account-menu island greets jane in hers and
  shows "Sign in" in the other.
- **Watch the bypass decision.** Every response carries a demo-only `X-Edge-Debug`
  header showing nginx's serve-from-cache decision (`skip=1` means "must reach PHP").
  Try `curl -sI 'http://localhost/?no-cache=1'`, or send the example's opt-in bypass
  cookie, `Cookie: edge_bypass=1`, and watch `X-Edge-Origin: 1` come back.
- **Verify end to end.** `docker compose exec php php craft edge/nginx/verify` runs
  the same check as the CP utility (Utilities -> Edge Cache).

## Useful commands

```bash
docker compose exec php php craft edge/cache/clear      # purge everything
docker compose exec php php craft edge/cache/generate   # rebuild from all live URLs
docker compose exec php php craft edge/nginx/verify     # MISS -> HIT, no cookie leak
docker compose logs -f queue                             # watch purge/warm jobs
```

## Notes

- php-fpm runs as root inside the container so bind-mounted project files are writable
  on every host OS / Docker file-sharing driver. Fine for a local example; don't copy
  that bit into production.
- `docker compose restart web` breaks the shared network namespace for `php` and
  `queue`; restart those two as well (or just use `docker compose up -d`).
- The config file `config/edge.php` overrides every plugin setting, so the CP settings
  screen shows all fields as read-only. That's the documented override behaviour, not
  a bug. Edit the file instead.
