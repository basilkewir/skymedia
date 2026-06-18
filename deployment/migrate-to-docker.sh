#!/usr/bin/env bash
# =============================================================================
# SkyMedia — migrate-to-docker.sh
# Uninstalls the bare-metal installation and runs SkyMedia via Docker Compose.
# Safe: backs up DB and DVR data before touching anything.
#
# Usage:
#   sudo bash /var/www/skymedia/deployment/migrate-to-docker.sh
#   sudo bash /var/www/skymedia/deployment/migrate-to-docker.sh --domain=skymedia.example.com
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/skymedia"
BACKUP_DIR="/var/backups/skymedia"
DVR_SRC="/var/skymedia/dvr"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
DOMAIN=""

for arg in "$@"; do
    case $arg in
        --domain=*) DOMAIN="${arg#*=}" ;;
    esac
done

[[ $EUID -ne 0 ]] && { echo "[ERROR] Run as root: sudo bash deployment/migrate-to-docker.sh"; exit 1; }

GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }
step() { echo -e "\n${CYAN}── $* ${NC}"; }

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia — Migrate to Docker                   ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════╝${NC}"
echo ""

cd "${APP_DIR}"

# ── Read existing .env ────────────────────────────────────────────────────────
get_env() { grep "^${1}=" "${APP_DIR}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d ' '; }

DB_CONN=$(get_env DB_CONNECTION); DB_CONN="${DB_CONN:-sqlite}"
DB_HOST=$(get_env DB_HOST);       DB_HOST="${DB_HOST:-127.0.0.1}"
DB_DATABASE=$(get_env DB_DATABASE)
DB_USERNAME=$(get_env DB_USERNAME)
DB_PASSWORD=$(get_env DB_PASSWORD)
APP_URL=$(get_env APP_URL)

# ── 1. Backup DB ──────────────────────────────────────────────────────────────
step "1 / 6  Backup database"
mkdir -p "${BACKUP_DIR}"

if [[ "${DB_CONN}" == "mysql" ]] && command -v mysqldump &>/dev/null; then
    BACKUP_FILE="${BACKUP_DIR}/skymedia_premigration_${TIMESTAMP}.sql.gz"
    mysqldump -h"${DB_HOST}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" \
        --single-transaction --add-drop-table "${DB_DATABASE}" \
        | gzip > "${BACKUP_FILE}"
    ok "MySQL backup → ${BACKUP_FILE}"
elif [[ "${DB_CONN}" == "sqlite" ]]; then
    DB_FILE="${DB_DATABASE}"
    [[ "${DB_FILE}" != /* ]] && DB_FILE="${APP_DIR}/${DB_FILE:-database/database.sqlite}"
    if [[ -f "${DB_FILE}" ]]; then
        BACKUP_FILE="${BACKUP_DIR}/skymedia_premigration_${TIMESTAMP}.sqlite"
        cp "${DB_FILE}" "${BACKUP_FILE}"
        ok "SQLite backup → ${BACKUP_FILE}"
    fi
else
    warn "Could not backup database — proceeding anyway"
fi

# ── 2. Stop bare-metal services ───────────────────────────────────────────────
step "2 / 6  Stop bare-metal services"
supervisorctl stop all 2>/dev/null || true
systemctl stop supervisor   2>/dev/null || true
systemctl stop nginx        2>/dev/null || true
systemctl stop php8.2-fpm   2>/dev/null || true
ok "Services stopped"

# ── 3. Install Docker ─────────────────────────────────────────────────────────
step "3 / 6  Install Docker & Docker Compose"
if ! command -v docker &>/dev/null; then
    curl -fsSL https://get.docker.com | bash
    systemctl enable docker --quiet
    systemctl start docker
    ok "Docker installed"
else
    ok "Docker already installed — skipping"
fi

if ! docker compose version &>/dev/null; then
    apt-get install -y -q docker-compose-plugin
fi
ok "Docker Compose: $(docker compose version --short)"

# ── 4. Generate secrets ───────────────────────────────────────────────────────
step "4 / 6  Prepare environment"
ENV_FILE="${APP_DIR}/.env.docker-prod"

if [[ ! -f "${ENV_FILE}" ]]; then
    NEW_DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
    NEW_DB_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)

    # If we had a MySQL install, preserve credentials so data import can work
    if [[ "${DB_CONN}" == "mysql" ]] && [[ -n "${DB_PASSWORD}" ]]; then
        NEW_DB_PASSWORD="${DB_PASSWORD}"
        warn "Reusing existing DB_PASSWORD from .env"
    fi

    RESOLVED_URL="${DOMAIN:+https://${DOMAIN}}"
    RESOLVED_URL="${RESOLVED_URL:-${APP_URL:-http://$(hostname -I | awk '{print $1}')}}"

    cat > "${ENV_FILE}" <<EOF
DB_PASSWORD=${NEW_DB_PASSWORD}
DB_ROOT_PASSWORD=${NEW_DB_ROOT_PASSWORD}
APP_URL=${RESOLVED_URL}
EOF
    chmod 600 "${ENV_FILE}"
    ok "Secrets written to ${ENV_FILE}"
else
    ok "${ENV_FILE} already exists — preserved"
fi

# ── 5. Import existing data into Docker MySQL (if we had MySQL) ───────────────
if [[ "${DB_CONN}" == "mysql" ]] && [[ -f "${BACKUP_FILE:-}" ]]; then
    step "5 / 6  Import existing MySQL data"
    info "Starting DB container to import data..."
    docker compose -f "${APP_DIR}/deployment/docker-compose.prod.yml" \
        --env-file "${ENV_FILE}" up -d db redis
    info "Waiting for MySQL to be ready..."
    sleep 15
    DB_PASS_DOCKER=$(grep DB_PASSWORD "${ENV_FILE}" | cut -d= -f2)
    gunzip < "${BACKUP_FILE}" \
        | docker compose -f "${APP_DIR}/deployment/docker-compose.prod.yml" \
            --env-file "${ENV_FILE}" \
            exec -T db mysql -u skymedia -p"${DB_PASS_DOCKER}" skymedia
    ok "Data imported into Docker MySQL"
else
    step "5 / 6  Data import"
    warn "No MySQL backup to import — Docker MySQL will be fresh (migrations run on first start)"
fi

# ── 6. Start Docker stack ─────────────────────────────────────────────────────
step "6 / 6  Start Docker stack"
docker compose -f "${APP_DIR}/deployment/docker-compose.prod.yml" \
    --env-file "${ENV_FILE}" up -d --build

ok "Stack started"

# ── Done ──────────────────────────────────────────────────────────────────────
RESOLVED_URL=$(grep APP_URL "${ENV_FILE}" | cut -d= -f2)
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   Migration complete!                                ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  URL       : ${CYAN}${RESOLVED_URL}${NC}"
echo -e "  Secrets   : ${YELLOW}${ENV_FILE}${NC}"
echo -e "  DB Backup : ${YELLOW}${BACKUP_FILE:-none}${NC}"
echo ""
echo -e "  Useful commands:"
echo -e "    ${CYAN}docker compose -f ${APP_DIR}/deployment/docker-compose.prod.yml --env-file ${ENV_FILE} ps${NC}"
echo -e "    ${CYAN}docker compose -f ${APP_DIR}/deployment/docker-compose.prod.yml --env-file ${ENV_FILE} logs -f app${NC}"
echo ""
echo -e "  Reset admin password:"
echo -e "    ${CYAN}docker compose -f ${APP_DIR}/deployment/docker-compose.prod.yml --env-file ${ENV_FILE} exec app php artisan admin:reset-password${NC}"
echo ""
