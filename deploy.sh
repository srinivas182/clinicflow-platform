#!/usr/bin/env bash
# Clinic Flow — update on cPanel hosting. Run from the app folder:  bash deploy.sh
# Pulls the "deploy" branch (code + built front end), installs, migrates every database and refreshes caches.
set -euo pipefail
cd "$(dirname "$0")"
PHP="${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}"
COMPOSER="${COMPOSER:-$HOME/bin/composer.phar}"

# cPanel writes blocks into public/.htaccess (e.g. MultiPHP Manager's PHP version handler). They are
# not part of the app, so keep them across updates — the site must never fall back to the wrong PHP.
HTACCESS=public/.htaccess
CPANEL_BLOCKS="$(awk '/BEGIN cPanel-generated/{keep=1} keep{print} /END cPanel-generated/{keep=0}' "$HTACCESS" 2>/dev/null || true)"
restore_cpanel_blocks() {
    if [ -n "$CPANEL_BLOCKS" ] && ! grep -q "BEGIN cPanel-generated" "$HTACCESS" 2>/dev/null; then
        printf '\n%s\n' "$CPANEL_BLOCKS" >> "$HTACCESS"
    fi
}

# If any step fails: put cPanel's blocks back, clear caches (uncached is always correct), bring the site back up, and say so.
trap 'echo; echo "✗ Deploy stopped at the step above. The site has been brought back up; fix the error and run deploy.sh again."; restore_cpanel_blocks; "$PHP" artisan optimize:clear >/dev/null 2>&1 || true; "$PHP" artisan up >/dev/null 2>&1 || true' ERR

echo "→ Fetching the latest release"
git fetch origin deploy
git checkout -q -- "$HTACCESS" 2>/dev/null || true
git checkout -q -B deploy origin/deploy
restore_cpanel_blocks
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
