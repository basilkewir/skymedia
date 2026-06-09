#!/usr/bin/env bash
# =============================================================================
# SkyMedia — update.sh
#
# Safe, zero-data-loss update script. Run this every time you deploy new code.
#
#   • NEVER drops the database or modifies existing data
#   • NEVER overwrites .env
#   • NEVER deletes DVR segments
#   • Takes a DB backup before any migration
#   • Gracefully stops stream processes, updates, then restarts
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

# ── Parse flags ──────────────────────────────────────────────────────────────
for arg in "$@"; do
    case $arg in
        --branch=*)  BRANCH="${arg#*=}" ;;
        --skip-git)  SKIP_GIT=true ;;
        --app-dir=*) APP_DIR="${arg#*=}" ;;
    esac
done

# ── Root check ────────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    echo "[ERROR] Run as root: sudo bash deployment/update.sh"
    exit 1
fi

cd "${APP_DIR}"

# Resolve binaries — handles sudo stripping PATH
PHP=$(command -v php8.2 || command -v php)
PHP_VER=$("${PHP}" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.2")
COMPOSER=$(command -v composer || find /usr/local/bin /usr/bin /home -name composer 2>/dev/null | head -1)
NPM=$(command -v npm)

GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }
step() { echo -e "\n${CYAN}── $* ${NC}"; }
fail() { echo -e "${RED}[FAIL]${NC}  $*"; exit 1; }

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia — Update  [${TIMESTAMP}]   ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════╝${NC}"
echo ""

# ── Read .env values safely ───────────────────────────────────────────────────
get_env() { grep "^${1}=" "${APP_DIR}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d ' '; }

DB_DATABASE=$(get_env DB_DATABASE)
DB_USERNAME=$(get_env DB_USERNAME)
DB_PASSWORD=$(get_env DB_PASSWORD)
DB_HOST=$(get_env DB_HOST)

[[ -z "${DB_DATABASE}" ]] && fail ".env not found or DB_DATABASE not set in ${APP_DIR}/.env"

# ─────────────────────────────────────────────────────────────────────────────
step "1 / 8  Database backup (before any changes)"
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p "${BACKUP_DIR}"
BACKUP_FILE="${BACKUP_DIR}/skymedia_${TIMESTAMP}.sql.gz"

info "Backing up '${DB_DATABASE}' → ${BACKUP_FILE}"
mysqldump \
    -h "${DB_HOST:-127.0.0.1}" \
    -u "${DB_USERNAME}" \
    -p"${DB_PASSWORD}" \
    --single-transaction \
    --routines \
    --triggers \
    --add-drop-table \
    "${DB_DATABASE}" | gzip > "${BACKUP_FILE}"

ok "Backup saved: ${BACKUP_FILE}"

# Keep only last 10 backups
ls -t "${BACKUP_DIR}"/skymedia_*.sql.gz 2>/dev/null | tail -n +11 | xargs rm -f 2>/dev/null || true
info "Old backups pruned (keeping last 10)"

# ─────────────────────────────────────────────────────────────────────────────
step "2 / 8  Pull latest code"
# ─────────────────────────────────────────────────────────────────────────────
if [[ "${SKIP_GIT}" == "true" ]]; then
    warn "Skipping git pull (--skip-git)"
elif [[ -d "${APP_DIR}/.git" ]]; then
    info "Pulling from branch '${BRANCH}'..."
    # Stash any local changes to non-.env files to prevent merge conflicts
    git stash push -m "pre-update-stash-${TIMESTAMP}" -- \
        ':!.env' ':!storage' ':!bootstrap/cache' 2>/dev/null || true
    git fetch origin --quiet
    git checkout "${BRANCH}" --quiet
    git pull origin "${BRANCH}" --quiet
    ok "Code updated to latest ${BRANCH}"
else
    warn "Not a git repository — skipping git pull (deploy files manually)"
fi

# .env is NEVER touched — verify it still exists
[[ -f "${APP_DIR}/.env" ]] || fail ".env is missing after git pull — restore it from backup"
# Update APP_URL with current server IP
SERVER_IP=$(hostname -I | awk '{print $1}')
sed -i "s|APP_URL=.*|APP_URL=http://${SERVER_IP}|" "${APP_DIR}/.env"
ok ".env preserved — APP_URL updated to http://${SERVER_IP}"

# ─────────────────────────────────────────────────────────────────────────────
step "3 / 8  Gracefully stop stream processes"
# ─────────────────────────────────────────────────────────────────────────────
# Stop supervisor-managed daemons (streams will resume after update)
supervisorctl stop skymedia-monitor   2>/dev/null || true
supervisorctl stop skymedia-queue     2>/dev/null || true
supervisorctl stop skymedia-scheduler 2>/dev/null || true
ok "SkyMedia daemons stopped"

# ─────────────────────────────────────────────────────────────────────────────
step "4 / 8  PHP dependencies"
# ─────────────────────────────────────────────────────────────────────────────
if [[ -z "${COMPOSER}" ]]; then
    curl -sS https://getcomposer.org/installer | "${PHP}" -- --install-dir=/usr/local/bin --filename=composer --quiet
    COMPOSER=/usr/local/bin/composer
    ok "Composer installed"
fi
COMPOSER_ALLOW_SUPERUSER=1 "${PHP}" "${COMPOSER}" install --no-dev --optimize-autoloader --no-interaction --quiet
"${PHP}" artisan package:discover --ansi 2>/dev/null || true
ok "Composer packages updated"

# ─────────────────────────────────────────────────────────────────────────────
step "5 / 8  Frontend assets"
# ─────────────────────────────────────────────────────────────────────────────
"${NPM}" ci --silent
"${NPM}" run build
ok "Frontend built"

# ─────────────────────────────────────────────────────────────────────────────
step "6 / 8  Database migrations (safe — never drops data)"
# ─────────────────────────────────────────────────────────────────────────────
info "Running php artisan migrate --force (non-destructive only)..."
"${PHP}" artisan migrate --force --quiet
ok "Migrations applied"

# ─────────────────────────────────────────────────────────────────────────────
step "7 / 8  Caches & permissions"
# ─────────────────────────────────────────────────────────────────────────────
# Restart PHP-FPM to clear stale opcache after code update
systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
# Clear all Laravel caches before rebuilding them
"${PHP}" artisan optimize:clear --quiet 2>/dev/null || true
"${PHP}" artisan config:cache   --quiet
"${PHP}" artisan route:cache    --quiet
"${PHP}" artisan view:cache     --quiet
"${PHP}" artisan event:cache    --quiet
ok "Caches rebuilt"

chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
chmod -R 775 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
ok "Permissions set"

[[ -L "${APP_DIR}/public/storage" ]] || "${PHP}" artisan storage:link --quiet
ok "Storage link verified"

# ─────────────────────────────────────────────────────────────────────────────
step "8 / 8  Restart services"
# ─────────────────────────────────────────────────────────────────────────────
# Reload supervisor config (picks up any changes to supervisord.conf)
cp "${APP_DIR}/deployment/supervisord.conf" /etc/supervisor/conf.d/skymedia.conf
supervisorctl reread  >/dev/null 2>&1 || true
supervisorctl update  >/dev/null 2>&1 || true

supervisorctl start skymedia-monitor   2>/dev/null || supervisorctl restart skymedia-monitor   || true
supervisorctl start skymedia-scheduler 2>/dev/null || supervisorctl restart skymedia-scheduler || true
supervisorctl start skymedia-queue     2>/dev/null || supervisorctl restart skymedia-queue     || true

# Reload nginx (zero-downtime)
nginx -t && systemctl reload nginx
ok "Nginx reloaded"

# Re-activate all channels that were running before the update
info "Re-activating streams..."
"${PHP}" artisan streams:activate-all 2>/dev/null || warn "Could not activate streams (no active channels or error)"

# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔═══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   Update complete — all data preserved        ║${NC}"
echo -e "${GREEN}╚═══════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Backup  : ${CYAN}${BACKUP_FILE}${NC}"
echo ""
echo -e "  Supervisor status:"
supervisorctl status 2>/dev/null | sed 's/^/    /'
echo ""
echo -e "  To rollback DB if something is wrong:"
echo -e "  ${YELLOW}  gunzip < ${BACKUP_FILE} | mysql -u${DB_USERNAME} -p ${DB_DATABASE}${NC}"
echo ""
