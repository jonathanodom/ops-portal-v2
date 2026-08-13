#!/usr/bin/env bash
set -euo pipefail

# Install or replace the root-owned cron entry. Run this script with sudo.
# The update itself runs as viktor-deploy and is serialized with flock.

APP_DIR="${APP_DIR:-/var/www/ops-portal-v2}"
DEPLOY_USER="${DEPLOY_USER:-viktor-deploy}"
LOG_DIR="${APP_DIR}/storage/logs"
CRON_FILE="/etc/cron.d/ops-portal-v2-nightly"

[[ "${EUID}" -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }
[[ -x "${APP_DIR}/scripts/update-production.sh" ]] || {
  echo "Missing executable update script at ${APP_DIR}/scripts/update-production.sh" >&2
  exit 1
}

install -d -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "${LOG_DIR}"
cat > "${CRON_FILE}" <<EOF
17 2 * * * ${DEPLOY_USER} cd ${APP_DIR} && /usr/bin/flock -n /tmp/ops-portal-v2-nightly.lock ${APP_DIR}/scripts/update-production.sh >> ${LOG_DIR}/nightly-deploy.log 2>&1
EOF
chmod 0644 "${CRON_FILE}"
echo "Nightly update installed for 02:17 server time."
