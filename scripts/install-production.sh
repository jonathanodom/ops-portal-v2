#!/usr/bin/env bash
set -euo pipefail

# Initial Vultr install. Run once on a fresh server as viktor-deploy:
#   APP_DIR=/var/www/ops-portal-v2 GIT_REPO_URL=... ./scripts/install-production.sh
#
# The script creates the app checkout, writes no secrets, and leaves .env setup
# to the operator. It never imports or destroys an existing database.

APP_DIR="${APP_DIR:-/var/www/ops-portal-v2}"
REPO_URL="${GIT_REPO_URL:-https://github.com/jonathanodom/ops-portal-v2.git}"
BRANCH="${DEPLOY_BRANCH:-main}"
WEB_USER="${WEB_USER:-www-data}"

for command in git php composer npm; do
  command -v "${command}" >/dev/null || {
    echo "Missing required command: ${command}" >&2
    exit 1
  }
done

if [[ -e "${APP_DIR}" ]]; then
  echo "${APP_DIR} already exists; refusing to overwrite it." >&2
  exit 1
fi

sudo -n /usr/bin/install -d -o "${USER}" -g "${WEB_USER}" "$(dirname "${APP_DIR}")"
git clone --branch "${BRANCH}" "${REPO_URL}" "${APP_DIR}"
cd "${APP_DIR}"

cp .env.example .env
sed -i \
  -e 's/^APP_ENV=.*/APP_ENV=production/' \
  -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' \
  -e 's#^APP_URL=.*#APP_URL=https://portal.newdaytech.net#' \
  -e 's/^DB_PORT=.*/DB_PORT=3306/' \
  -e 's/^DB_DATABASE=.*/DB_DATABASE=newday_ops/' \
  -e 's/^DB_USERNAME=.*/DB_USERNAME=newday_ops/' \
  -e 's/^DB_PASSWORD=.*/DB_PASSWORD=CHANGE_ME/' \
  .env
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan key:generate --force

echo "Edit ${APP_DIR}/.env now: set the real database, mail, session, and app settings."
echo "Then run: ${APP_DIR}/scripts/update-production.sh"
echo "The update script performs the first dependency install, migration, seed, and asset build."

sudo -n /usr/bin/install -d -o "${USER}" -g "${WEB_USER}" storage bootstrap/cache
sudo -n /bin/chown -R "${USER}:${WEB_USER}" storage bootstrap/cache
sudo -n /bin/chmod -R ug+rwX storage bootstrap/cache

echo "Initial checkout created at ${APP_DIR}."
echo "Install the nightly cron separately with scripts/install-nightly-update.sh."
