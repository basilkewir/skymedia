#!/usr/bin/env bash
# =============================================================================
# SkyMedia — Ubuntu 22.04 LTS  |  First-time Installation Script
#
# IDEMPOTENT — safe to run multiple times. Skips anything already installed.
# Database, .env, and DVR data are NEVER touched if they already exist.
#
# Usage:
#   sudo bash deployment/install.sh
#   sudo bash deployment/install.sh --domain=skymedia.example.com
#   sudo bash deployment/install.sh --domain=skymedia.example.com --skip-ffmpeg-build
# =============================================================================
set -euo pipefail

# ── Defaults (override via flags) ────────────────────────────────────────────
APP_DOMAIN="skymedia.example.com"
APP_DIR="/var/www/skymedia"
LOG_DIR="/var/log/skymedia"
DVR_DIR="/var/skymedia/dvr"
PHP_VER="8.2"
DB_NAME="skymedia"
DB_USER="skymedia"
SKIP_FFMPEG_BUILD=false
REPO_URL=""          # optional: git repo to clone

# ── Parse flags ──────────────────────────────────────────────────────────────
for arg in "$@"; do
    case $arg in
        --domain=*)         APP_DOMAIN="${arg#*=}" ;;
        --app-dir=*)        APP_DIR="${arg#*=}" ;;
        --dvr-dir=*)        DVR_DIR="${arg#*=}" ;;
        --repo=*)           REPO_URL="${arg#*=}" ;;
        --skip-ffmpeg-build) SKIP_FFMPEG_BUILD=true ;;
    esac
done

CREDS_FILE="/root/skymedia_credentials.txt"

# ── Colours ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }
step() { echo -e "\n${CYAN}──────────────────────────────────────────${NC}"; echo -e "${CYAN}  $*${NC}"; echo -e "${CYAN}──────────────────────────────────────────${NC}"; }

# ── Root check ────────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}[ERROR] This script must be run as root (sudo bash install.sh)${NC}"
    exit 1
fi

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia — First-time Installation         ║${NC}"
echo -e "${CYAN}║   Ubuntu 22.04/24.04 LTS                     ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
echo ""
info "Domain  : ${APP_DOMAIN}"
info "App dir : ${APP_DIR}"
info "DVR dir : ${DVR_DIR}"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
step "1 / 10  System packages"
# ─────────────────────────────────────────────────────────────────────────────
export DEBIAN_FRONTEND=noninteractive
apt-get update -y -q
apt-get install -y -q curl git unzip wget gnupg2 ca-certificates lsb-release \
    software-properties-common apt-transport-https build-essential openssl
ok "Core tools installed"

# ─────────────────────────────────────────────────────────────────────────────
step "2 / 10  Nginx"
# ─────────────────────────────────────────────────────────────────────────────
if ! command -v nginx &>/dev/null; then
    apt-get install -y -q nginx
    ok "Nginx installed"
else
    ok "Nginx already installed — skipping"
fi
systemctl enable nginx --quiet

# ─────────────────────────────────────────────────────────────────────────────
step "3 / 10  PHP ${PHP_VER}"
# ─────────────────────────────────────────────────────────────────────────────
if ! php -v 2>/dev/null | grep -q "PHP ${PHP_VER}"; then
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -y -q
    apt-get install -y -q \
        php${PHP_VER}-fpm php${PHP_VER}-cli php${PHP_VER}-common \
        php${PHP_VER}-mysql php${PHP_VER}-redis php${PHP_VER}-xml \
        php${PHP_VER}-curl php${PHP_VER}-mbstring php${PHP_VER}-zip \
        php${PHP_VER}-bcmath php${PHP_VER}-intl php${PHP_VER}-gd
    ok "PHP ${PHP_VER} installed"
else
    ok "PHP ${PHP_VER} already installed — skipping"
fi
systemctl enable php${PHP_VER}-fpm --quiet

# ─────────────────────────────────────────────────────────────────────────────
step "4 / 10  MySQL 8"
# ─────────────────────────────────────────────────────────────────────────────
if ! command -v mysql &>/dev/null; then
    apt-get install -y -q mysql-server
    systemctl enable mysql --quiet
    ok "MySQL installed"
else
    ok "MySQL already installed — skipping"
fi

# Generate or read existing DB password
if [[ -f "${CREDS_FILE}" ]]; then
    DB_PASS=$(grep 'DB_PASSWORD' "${CREDS_FILE}" 2>/dev/null | head -1 | cut -d= -f2 | tr -d ' ' || true)
fi
if [[ -z "${DB_PASS:-}" ]]; then
    DB_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
fi

# Create DB and user only if they don't exist
DB_EXISTS=$(mysql -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}';" 2>/dev/null | grep -c "${DB_NAME}" || true)
if [[ "${DB_EXISTS}" -eq 0 ]]; then
    mysql -e "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    ok "Database '${DB_NAME}' created"
else
    ok "Database '${DB_NAME}' already exists — preserved"
fi

USER_EXISTS=$(mysql -e "SELECT User FROM mysql.user WHERE User='${DB_USER}' AND Host='localhost';" 2>/dev/null | grep -c "${DB_USER}" || true)
if [[ "${USER_EXISTS}" -eq 0 ]]; then
    mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
    ok "MySQL user '${DB_USER}' created"
else
    # Ensure grants are up to date
    mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null || true
    ok "MySQL user '${DB_USER}' already exists — preserved"
fi

# Persist credentials (won't overwrite password if already saved)
if ! grep -q "DB_PASSWORD" "${CREDS_FILE}" 2>/dev/null; then
    {
        echo "# SkyMedia DB credentials — $(date)"
        echo "DB_NAME=${DB_NAME}"
        echo "DB_USER=${DB_USER}"
        echo "DB_PASSWORD=${DB_PASS}"
    } >> "${CREDS_FILE}"
    chmod 600 "${CREDS_FILE}"
    ok "DB credentials saved to ${CREDS_FILE}"
fi

# ─────────────────────────────────────────────────────────────────────────────
step "5 / 10  Redis"
# ─────────────────────────────────────────────────────────────────────────────
if ! command -v redis-server &>/dev/null; then
    apt-get install -y -q redis-server
    ok "Redis installed"
else
    ok "Redis already installed — skipping"
fi
systemctl enable redis-server --quiet

# ─────────────────────────────────────────────────────────────────────────────
step "6 / 10  Composer & Node.js 20"
# ─────────────────────────────────────────────────────────────────────────────
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet
    ok "Composer installed"
else
    composer self-update --quiet 2>/dev/null || true
    ok "Composer already installed — updated"
fi

if ! node -v 2>/dev/null | grep -q "v20"; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null
    apt-get install -y -q nodejs
    ok "Node.js 20 installed"
else
    ok "Node.js 20 already installed — skipping"
fi

# ─────────────────────────────────────────────────────────────────────────────
step "7 / 10  FFmpeg (with SRT support)"
# ─────────────────────────────────────────────────────────────────────────────
FFMPEG_HAS_SRT=false
if command -v ffmpeg &>/dev/null; then
    ffmpeg -protocols 2>&1 | grep -q srt && FFMPEG_HAS_SRT=true || true
fi

if [[ "${FFMPEG_HAS_SRT}" == "true" ]]; then
    ok "FFmpeg with SRT already installed — skipping"
elif [[ "${SKIP_FFMPEG_BUILD}" == "true" ]]; then
    apt-get install -y -q ffmpeg
    warn "Installed system FFmpeg (may lack SRT support). Use --skip-ffmpeg-build=false to build from source."
else
    info "Building FFmpeg with SRT support from source (this takes ~10 minutes)..."

    apt-get install -y -q cmake libssl-dev pkg-config yasm nasm \
        libx264-dev libx265-dev libvpx-dev libfdk-aac-dev \
        libmp3lame-dev libopus-dev libass-dev libfreetype6-dev

    # Build libsrt
    BUILD_TMP=$(mktemp -d)
    cd "${BUILD_TMP}"

    if [[ ! -d /usr/local/include/srt ]]; then
        info "Building libsrt..."
        git clone --depth 1 --quiet https://github.com/Haivision/srt.git srt-src
        cmake -S srt-src -B srt-src/build \
            -DENABLE_C_DEPS=ON -DENABLE_SHARED=ON -DENABLE_STATIC=OFF \
            -DCMAKE_INSTALL_PREFIX=/usr/local >/dev/null
        make -C srt-src/build -j"$(nproc)" >/dev/null
        make -C srt-src/build install >/dev/null
        ldconfig
        ok "libsrt built and installed"
    else
        ok "libsrt already installed"
    fi

    # Build FFmpeg
    info "Building FFmpeg..."
    git clone --depth 1 --quiet https://github.com/FFmpeg/FFmpeg.git ffmpeg-src
    cd ffmpeg-src
    ./configure \
        --prefix=/usr/local \
        --enable-gpl --enable-nonfree \
        --enable-libsrt \
        --enable-libx264 --enable-libx265 \
        --enable-libfdk-aac --enable-libmp3lame \
        --enable-libopus --enable-libass \
        --enable-libfreetype \
        --extra-libs="-lpthread -lm" \
        --disable-debug \
        --quiet
    make -j"$(nproc)" >/dev/null
    make install >/dev/null
    ldconfig
    cd / && rm -rf "${BUILD_TMP}"
    ok "FFmpeg with SRT support built and installed"
fi

# Verify
FFMPEG_VER=$(ffmpeg -version 2>&1 | head -1)
info "Using: ${FFMPEG_VER}"

# ─────────────────────────────────────────────────────────────────────────────
step "8 / 10  Supervisor"
# ─────────────────────────────────────────────────────────────────────────────
if ! command -v supervisorctl &>/dev/null; then
    apt-get install -y -q supervisor
    ok "Supervisor installed"
else
    ok "Supervisor already installed — skipping"
fi
systemctl enable supervisor --quiet
systemctl start supervisor --quiet || true

# ─────────────────────────────────────────────────────────────────────────────
step "9 / 10  Directories & permissions"
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p "${LOG_DIR}" "${DVR_DIR}" "${APP_DIR}"
chown -R www-data:www-data "${DVR_DIR}" "${LOG_DIR}"
ok "Directories ready"

# ─────────────────────────────────────────────────────────────────────────────
step "10 / 10  App clone / config / services"
# ─────────────────────────────────────────────────────────────────────────────

# Clone repo if URL given — re-clone if directory exists but has no artisan
if [[ -n "${REPO_URL}" ]]; then
    if [[ -f "${APP_DIR}/artisan" ]]; then
        ok "Application already present in ${APP_DIR} — pulling latest code"
        git -C "${APP_DIR}" pull origin main --quiet || true
    else
        info "Cloning ${REPO_URL} → ${APP_DIR}"
        rm -rf "${APP_DIR}"
        git clone "${REPO_URL}" "${APP_DIR}"
        ok "Repository cloned to ${APP_DIR}"
    fi
elif [[ -f "${APP_DIR}/artisan" ]]; then
    ok "Application already present in ${APP_DIR}"
else
    warn "No --repo URL given. Copy your app to ${APP_DIR} manually before running finish.sh"
fi

# Write .env only if it doesn't exist yet
if [[ ! -f "${APP_DIR}/.env" ]]; then
    DB_PASS_SAVED=$(grep 'DB_PASSWORD' "${CREDS_FILE}" | head -1 | cut -d= -f2 | tr -d ' ')
    cat > "${APP_DIR}/.env" <<ENV
APP_NAME=SkyMedia
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${APP_DOMAIN}

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS_SAVED}

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

BROADCAST_DRIVER=log

FFMPEG_BINARY=ffmpeg
FFPROBE_BINARY=ffprobe
DVR_BASE_PATH=${DVR_DIR}
SKYMEDIA_MONITOR_TICK=3
SKYMEDIA_SRT_LATENCY=200
ENV
    ok ".env created from template"
else
    ok ".env already exists — preserved (not overwritten)"
fi

# Nginx site config
if [[ ! -f /etc/nginx/sites-available/skymedia ]]; then
    cp "${APP_DIR}/deployment/nginx.conf" /etc/nginx/sites-available/skymedia
    # Replace placeholder domain
    sed -i "s/skymedia\.example\.com/${APP_DOMAIN}/g" /etc/nginx/sites-available/skymedia
    ln -sf /etc/nginx/sites-available/skymedia /etc/nginx/sites-enabled/skymedia
    rm -f /etc/nginx/sites-enabled/default
    nginx -t && systemctl reload nginx
    ok "Nginx site configured"
else
    ok "Nginx site already configured — skipping (edit /etc/nginx/sites-available/skymedia manually)"
fi

# Supervisor config
if [[ ! -f /etc/supervisor/conf.d/skymedia.conf ]]; then
    sed "s|/var/www/skymedia|${APP_DIR}|g" "${APP_DIR}/deployment/supervisord.conf" \
        > /etc/supervisor/conf.d/skymedia.conf
    supervisorctl reread >/dev/null
    supervisorctl update >/dev/null
    ok "Supervisor programs registered"
else
    ok "Supervisor already configured — skipping"
fi

# Log rotation
if [[ ! -f /etc/logrotate.d/skymedia ]]; then
    cat > /etc/logrotate.d/skymedia <<LOGROTATE
${LOG_DIR}/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data adm
    sharedscripts
    postrotate
        supervisorctl restart skymedia-monitor > /dev/null 2>&1 || true
    endscript
}
LOGROTATE
    ok "Log rotation configured"
fi

# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   Infrastructure installation complete!                  ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  DB credentials : ${YELLOW}${CREDS_FILE}${NC}"
echo ""

if [[ -f "${APP_DIR}/artisan" ]]; then
    echo -e "  App detected — running ${CYAN}finish.sh${NC} to complete setup..."
    bash "${APP_DIR}/deployment/finish.sh"
else
    echo -e "  ${YELLOW}Next:${NC} copy your app to ${APP_DIR}, then run:"
    echo -e "    ${CYAN}sudo bash ${APP_DIR}/deployment/finish.sh${NC}"
    echo ""
    echo -e "  ${YELLOW}SSL (after finish.sh):${NC}"
    echo -e "    ${CYAN}certbot --nginx -d ${APP_DOMAIN}${NC}"
fi
echo ""
