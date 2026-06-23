#!/usr/bin/env bash
# =============================================================================
# SkyMedia — update.sh
# Safe zero-data-loss update. Never drops DB, never overwrites .env.
# Supports SQLite and MySQL (auto-detected from .env).
#
# Usage:
#   sudo bash /var/www/skymedia/deployment/update.sh
#   sudo bash /var/www/skymedia/deployment/update.sh --branch=main
#   sudo bash /var/www/skymedia/deployment/update.sh --skip-git
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/skymedia"
BACKUP_DIR="/var/backups/skymedia"
BRANCH="main"
SKIP_GIT=false
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")

for arg in "$@"; do
    case $arg in
        --branch=*)  BRANCH="${arg#*=}" ;;
        --skip-git)  SKIP_GIT=true ;;
        --app-dir=*) APP_DIR="${arg#*=}" ;;
    esac
done

[[ $EUID -ne 0 ]] && { echo "[ERROR] Run as root: sudo bash deployment/update.sh"; exit 1; }

cd "${APP_DIR}"

PHP=$(command -v php8.2 || command -v php)
PHP_VER=$("${PHP}" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.2")
COMPOSER=$(command -v composer || find /usr/local/bin /usr/bin -name composer 2>/dev/null | head -1)
NPM=$(command -v npm)

GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }
step() { echo -e "\n${CYAN}── $* ${NC}"; }
fail() { echo -e "${RED}[FAIL]${NC}  $*"; exit 1; }
get_env() { grep "^${1}=" "${APP_DIR}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d ' '; }

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia — Update  [${TIMESTAMP}]   ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════╝${NC}"
echo ""

DB_CONN=$(get_env DB_CONNECTION); DB_CONN="${DB_CONN:-sqlite}"
[[ -f "${APP_DIR}/.env" ]] || fail ".env not found in ${APP_DIR}"

# ── 1. Database backup ────────────────────────────────────────────────────────
step "1 / 8  Database backup"
mkdir -p "${BACKUP_DIR}"

if [[ "${DB_CONN}" == "sqlite" ]]; then
    DB_DATABASE=$(get_env DB_DATABASE)
    [[ "${DB_DATABASE}" != /* ]] && DB_DATABASE="${APP_DIR}/${DB_DATABASE:-database/database.sqlite}"
    if [[ -f "${DB_DATABASE}" ]]; then
        BACKUP_FILE="${BACKUP_DIR}/skymedia_${TIMESTAMP}.sqlite"
        cp "${DB_DATABASE}" "${BACKUP_FILE}"
        ok "SQLite backup → ${BACKUP_FILE}"
    else
        warn "SQLite file not found at ${DB_DATABASE} — skipping backup"
    fi
else
    DB_DATABASE=$(get_env DB_DATABASE)
    DB_USERNAME=$(get_env DB_USERNAME)
    DB_PASSWORD=$(get_env DB_PASSWORD)
    DB_HOST=$(get_env DB_HOST); DB_HOST="${DB_HOST:-127.0.0.1}"
    [[ -z "${DB_DATABASE}" ]] && fail "DB_DATABASE not set in .env"
    BACKUP_FILE="${BACKUP_DIR}/skymedia_${TIMESTAMP}.sql.gz"
    mysqldump -h"${DB_HOST}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" \
        --single-transaction --routines --triggers --add-drop-table \
        "${DB_DATABASE}" | gzip > "${BACKUP_FILE}"
    ok "MySQL backup → ${BACKUP_FILE}"
fi

# Keep last 10 backups
ls -t "${BACKUP_DIR}"/skymedia_* 2>/dev/null | tail -n +11 | xargs rm -f 2>/dev/null || true

# ── 2. Pull code ──────────────────────────────────────────────────────────────
step "2 / 8  Pull latest code"
if [[ "${SKIP_GIT}" == "true" ]]; then
    warn "Skipping git pull (--skip-git)"
elif [[ -d "${APP_DIR}/.git" ]]; then
    git stash push -m "pre-update-${TIMESTAMP}" -- ':!.env' ':!storage' ':!bootstrap/cache' 2>/dev/null || true
    git fetch origin --quiet
    git checkout "${BRANCH}" --quiet
    git pull origin "${BRANCH}" --quiet
    ok "Code updated to latest ${BRANCH}"
else
    warn "Not a git repo — deploy files manually"
fi

[[ -f "${APP_DIR}/.env" ]] || fail ".env missing after pull — restore from backup"
APP_URL_CURRENT=$(get_env APP_URL)
if [[ -z "${APP_URL_CURRENT}" ]] || [[ "${APP_URL_CURRENT}" == "http://localhost"* ]] || [[ "${APP_URL_CURRENT}" =~ ^http://[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+ ]]; then
    SERVER_IP=$(hostname -I | awk '{print $1}')
    sed -i "s|APP_URL=.*|APP_URL=http://${SERVER_IP}|" "${APP_DIR}/.env"
    ok ".env APP_URL → http://${SERVER_IP}"
else
    ok ".env APP_URL preserved → ${APP_URL_CURRENT}"
fi

# ── 3. Stop daemons ───────────────────────────────────────────────────────────
step "3 / 8  Stop daemons"
supervisorctl stop skymedia-monitor skymedia-queue skymedia-scheduler 2>/dev/null || true
ok "Daemons stopped"

# ── 4. PHP dependencies ───────────────────────────────────────────────────────
step "4 / 8  PHP dependencies"
COMPOSER_ALLOW_SUPERUSER=1 "${PHP}" "${COMPOSER}" install --no-dev --optimize-autoloader --no-interaction --quiet
"${PHP}" artisan package:discover --ansi 2>/dev/null || true
ok "Composer packages updated"

# ── 5. Clear caches + generate routes BEFORE frontend build ───────────────────
# Ziggy bakes the route list into the JS bundle at BUILD TIME.
# Routes must be cached BEFORE npm run build or Ziggy uses stale routes.
step "5a / 8  Pre-build: clear & cache routes"
"${PHP}" artisan optimize:clear --quiet 2>/dev/null || true
"${PHP}" artisan config:cache   --quiet
"${PHP}" artisan route:cache    --quiet
ok "Routes cached for Ziggy"

# ── 5b. Frontend ──────────────────────────────────────────────────────────────
step "5b / 8  Frontend assets (clean build)"
# Always delete old build so browsers never load stale hashed JS files
rm -rf "${APP_DIR}/public/build"
"${NPM}" ci --silent
"${NPM}" run build
ok "Frontend built"

# ── 6. Migrations ─────────────────────────────────────────────────────────────
step "6 / 8  Migrations"
# Ensure SQLite file exists and is writable before migrating
if [[ "${DB_CONN}" == "sqlite" ]]; then
    DB_DATABASE=$(get_env DB_DATABASE)
    [[ "${DB_DATABASE}" != /* ]] && DB_DATABASE="${APP_DIR}/${DB_DATABASE:-database/database.sqlite}"
    mkdir -p "$(dirname "${DB_DATABASE}")"
    [[ ! -f "${DB_DATABASE}" ]] && touch "${DB_DATABASE}"
    chown www-data:www-data "${DB_DATABASE}" "$(dirname "${DB_DATABASE}")"
    chmod 664 "${DB_DATABASE}"
fi
"${PHP}" artisan migrate --force --quiet
ok "Migrations applied"

# ── 7. Caches & permissions ───────────────────────────────────────────────────
step "7 / 8  Caches & permissions"
# Restart PHP-FPM to clear opcache
systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
# Routes & config already cached before build in step 5a
# Just add view + event caches here
"${PHP}" artisan view:cache  --quiet
"${PHP}" artisan event:cache --quiet
ok "All caches warm"

chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" "${APP_DIR}/database" 2>/dev/null || true
chmod -R 777 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" "${APP_DIR}/database" 2>/dev/null || true
[[ ! -L "${APP_DIR}/public/storage" ]] && "${PHP}" artisan storage:link --quiet
ok "Permissions set"

# ── 8. Restart services ───────────────────────────────────────────────────────
step "8 / 8  Restart services"
systemctl enable supervisor --quiet 2>/dev/null || true
systemctl start supervisor --quiet 2>/dev/null || true
cp "${APP_DIR}/deployment/supervisord.conf" /etc/supervisor/conf.d/skymedia.conf
supervisorctl reread >/dev/null 2>&1 || true
supervisorctl update >/dev/null 2>&1 || true

for svc in skymedia-monitor skymedia-scheduler skymedia-queue; do
    supervisorctl start "${svc}" 2>/dev/null || supervisorctl restart "${svc}" || true
done

nginx -t && systemctl reload nginx
ok "Nginx reloaded"

info "Re-activating streams..."
"${PHP}" artisan streams:activate-all 2>/dev/null || warn "No active channels or streams:activate-all error"

echo ""
echo -e "${GREEN}╔═══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   Update complete — all data preserved        ║${NC}"
echo -e "${GREEN}╚═══════════════════════════════════════════════╝${NC}"
echo ""
if [[ -n "${BACKUP_FILE:-}" ]]; then
    echo -e "  Backup  : ${CYAN}${BACKUP_FILE}${NC}"
fi
echo ""
supervisorctl status 2>/dev/null | sed 's/^/    /'
echo ""
if [[ "${DB_CONN}" != "sqlite" ]]; then
    echo -e "  To rollback DB: ${YELLOW}gunzip < ${BACKUP_FILE:-backup.sql.gz} | mysql -u${DB_USERNAME} -p ${DB_DATABASE}${NC}"
else
    echo -e "  To rollback DB: ${YELLOW}cp ${BACKUP_FILE:-backup.sqlite} ${DB_DATABASE:-database/database.sqlite}${NC}"
fi
echo ""
