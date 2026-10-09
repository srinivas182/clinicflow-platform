#!/usr/bin/env bash
# Clinic Flow — update on cPanel hosting. Run from the app folder:  bash deploy.sh
# Pulls the "deploy" branch (code + built front end), installs, migrates every database and refreshes caches.
set -euo pipefail
cd "$(dirname "$0")"
PHP="${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}"
COMPOSER="${COMPOSER:-$HOME/bin/composer.phar}"

echo "→ Fetching the latest release"
git fetch origin deploy
git checkout -q -B deploy origin/deploy
git log --oneline -1

echo "→ Installing PHP packages"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "→ Maintenance mode on"
"$PHP" artisan down --retry=30 || true

echo "→ Migrating databases (platform, network hub, every practice)"
"$PHP" artisan migrate --force
"$PHP" artisan migrate --database=hub --path=database/migrations/hub --force
"$PHP" artisan tenants:migrate --force

echo "→ Refreshing caches"
"$PHP" artisan optimize
"$PHP" artisan queue:restart

echo "→ Maintenance mode off"
"$PHP" artisan up

echo "→ Security self-check"
"$PHP" artisan security:check || true
echo "✓ Deployed"
