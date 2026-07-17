#!/usr/bin/env bash
#
# One-shot setup for the Edge example stack. Safe to re-run.
#
#   ./setup.sh          build, install and start everything
#   ./setup.sh reset    tear down containers, volumes and generated files first
#
set -euo pipefail
cd "$(dirname "$0")"

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker (with the compose plugin) is required." >&2
    exit 1
fi

if [ "${1:-}" = "reset" ]; then
    echo "Resetting: removing containers, volumes and generated files..."
    docker compose down -v --remove-orphans
    rm -rf vendor storage web/cpresources .env
fi

if [ ! -f .env ]; then
    # `|| true` absorbs tr's SIGPIPE status once head has its 40 chars (pipefail).
    key=$(LC_ALL=C tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40 || true)
    sed "s/^CRAFT_SECURITY_KEY=\$/CRAFT_SECURITY_KEY=$key/" .env.example > .env
    echo "Created .env with a fresh security key."
fi

echo "Building and starting db, web and php..."
docker compose build php
docker compose up -d db web php

echo "Installing composer dependencies..."
docker compose exec php composer install --no-interaction --no-progress

if ! docker compose exec php php craft install/check 2>/dev/null | grep -q 'is installed'; then
    echo "Installing Craft (admin / EdgeExample123!)..."
    docker compose exec php php craft install/craft \
        --interactive=0 \
        --email=admin@example.com \
        --username=admin \
        --password='EdgeExample123!' \
        --site-name='Edge Example' \
        --site-url='$PRIMARY_SITE_URL' \
        --language=en-US
fi

echo "Running content migrations (Blog section + sample posts)..."
docker compose exec php php craft migrate/up --interactive=0

# Second (non-admin) account for the multi-user demo; ignore "already exists".
docker compose exec php php craft users/create \
    --interactive=0 --email=jane@example.com --username=jane \
    --password='EdgeExample123!' >/dev/null 2>&1 || true

echo "Starting the queue daemon and generating the cache..."
docker compose up -d queue
docker compose exec php php craft edge/cache/generate >/dev/null 2>&1 || true

echo "Verifying the edge cache end to end..."
sleep 5
docker compose exec php php craft edge/nginx/verify --url=http://localhost/ | grep -v 'root/super'

cat <<'EOF'

Done.

  Site           http://localhost/
  Control panel  http://localhost/admin  (admin / EdgeExample123!)
  Second user    jane / EdgeExample123!  (front-end sign-in form)

Try it:
  - Load the homepage twice; the second response has no X-Edge-Origin header (a HIT).
  - Publish a blog entry in the CP and watch the listings update within seconds.
  - Open the site in two browsers; each gets its own island content, same shell.
EOF
