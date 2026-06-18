#!/usr/bin/env bash
# =============================================================================
# SkyMedia — docker-update.sh
# Pull latest code, rebuild the app container, run migrations, restart.
# Never drops data. DB and DVR volumes are preserved.
#
# Usage:
#   sudo bash /var/www/skymedia/deployment/docker-update.sh
#   sudo bash /var/www/skymedia/deployment/docker-update.sh --branch=main
#   sudo bash /var/www/skymedia/deployment/docker-update.sh --skip-git
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/skymedia"
BACKUP_DIR="/var/backups/skymedia"
BRANCH="main"
SKIP_GIT=false
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
ENV_FILE="${APP_DIR}/.env.docker-prod"
COMPOSE="docker compose -f ${APP_DIR}/deployment/docker-compose.prod.yml --env-file ${ENV_FILE}"

for arg in "$@"; do
    case $arg in
        --branch=*)  BRANCH="${arg#*=}" ;;
        --skip-git)  SKIP_GIT=true ;;
    esac
done

[[ $EUID -ne 0 ]] && { echo "[ERROR] Run as root: sudo bash deployment/docker-update.sh"; exit 1; }
[[ -f "${ENV_FILE}" ]] || { echo "[ERROR] ${ENV_FILE} not found. Run migrate-to-docker.sh first."; exit 1; }

GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }
step() { echo -e "\n${CYAN}── $* ${NC}"; }

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia Docker — Update [${TIMESTAMP}]${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════╝${NC}"
echo ""

cd "${APP_DIR}"

# ── 1. Backup DB ──────────────────────────────────────────────────────────────
step "1 / 4  Backup database"
mkdir -p "${BACKUP_DIR}"
BACKUP_FILE="${BACKUP_DIR}/skymedia_${TIMESTAMP}.sql.gz"
DB_PASS=$(grep DB_PASSWORD "${ENV_FILE}" | cut -d= -f2)
${COMPOSE} exec -T db mysqldump -u skymedia -p"${DB_PASS}" \
    --single-transaction --add-drop-table skymedia \
    | gzip > "${BACKUP_FILE}"
ls -t "${BACKUP_DIR}"/skymedia_*.sql.gz 2>/dev/null | tail -n +11 | xargs rm -f || true
ok "Backup → ${BACKUP_FILE}"

# ── 2. Pull code ──────────────────────────────────────────────────────────────
step "2 / 4  Pull latest code"
if [[ "${SKIP_GIT}" == "true" ]]; then
    warn "Skipping git pull (--skip-git)"
elif [[ -d "${APP_DIR}/.git" ]]; then
    git -C "${APP_DIR}" fetch origin --quiet
    git -C "${APP_DIR}" checkout "${BRANCH}" --quiet
    git -C "${APP_DIR}" pull origin "${BRANCH}" --quiet
    ok "Code updated to latest ${BRANCH}"
else
    warn "Not a git repo — skipping pull"
fi

# ── 3. Rebuild app container ──────────────────────────────────────────────────
step "3 / 4  Rebuild & restart app container"
${COMPOSE} build app
${COMPOSE} up -d --no-deps app
ok "App container rebuilt and restarted"

# ── 4. Migrate ────────────────────────────────────────────────────────────────
step "4 / 4  Run migrations"
${COMPOSE} exec app php artisan migrate --force
${COMPOSE} exec app php artisan streams:activate-all 2>/dev/null || warn "No active channels"
ok "Migrations applied"

echo ""
echo -e "${GREEN}╔═══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   Update complete — all data preserved        ║${NC}"
echo -e "${GREEN}╚═══════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Backup : ${CYAN}${BACKUP_FILE}${NC}"
echo -e "  Rollback: ${YELLOW}gunzip < ${BACKUP_FILE} | docker compose ... exec -T db mysql -u skymedia -p${DB_PASS} skymedia${NC}"
echo ""
${COMPOSE} ps
echo ""
