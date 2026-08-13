#!/usr/bin/env bash
set -euo pipefail

# Sequential, non-destructive production update. Every run applies only
# migrations not already recorded by Laravel. It never resets or wipes data.

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BRANCH="${DEPLOY_BRANCH:-main}"
DEPLOY_USER="${DEPLOY_USER:-$(id -un)}"
WEB_USER="${WEB_USER:-www-data}"
MANIFEST="public/build/manifest.json"
MAINTENANCE_ENABLED=0

cd "${APP_DIR}"

[[ -f artisan ]] || { echo "artisan not found in ${APP_DIR}" >&2; exit 1; }
[[ -f .env ]] || { echo "Missing ${APP_DIR}/.env" >&2; exit 1; }
grep -q '^APP_ENV=production' .env || {
  echo "Refusing production update unless .env contains APP_ENV=production." >&2
  exit 1
}

cleanup() {
  if [[ "${MAINTENANCE_ENABLED}" -eq 1 ]]; then
    php artisan up || true
  fi
}
trap cleanup EXIT

php artisan down --retry=60 --refresh=15 || true
MAINTENANCE_ENABLED=1

git fetch origin "${BRANCH}"
git checkout "${BRANCH}"
git pull --ff-only origin "${BRANCH}"

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
[[ -f "${MANIFEST}" ]] || {
  echo "Missing ${MANIFEST}; refusing to finish deployment." >&2
  exit 1
}

php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart || true

sudo -n /bin/chown -R "${DEPLOY_USER}:${WEB_USER}" storage bootstrap/cache
sudo -n /bin/chmod -R ug+rwX storage bootstrap/cache

php artisan up
MAINTENANCE_ENABLED=0
trap - EXIT

echo "Deployment complete at $(git rev-parse --short HEAD)"
