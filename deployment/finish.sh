#!/usr/bin/env bash
# =============================================================================
# SkyMedia — finish.sh
# Run once after install.sh. Safe to re-run. Never drops data.
# Supports both SQLite and MySQL (auto-detected from .env).
# Usage: sudo bash /var/www/skymedia/deployment/finish.sh
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/skymedia"
cd "${APP_DIR}"

PHP=$(command -v php8.2 || command -v php || true)
PHP_VER=$("${PHP}" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.2")
COMPOSER=$(command -v composer || find /usr/local/bin /usr/bin -name composer 2>/dev/null | head -1 || true)
NODE=$(command -v node || command -v nodejs || true)
NPM=$(command -v npm || true)

GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
step() { echo -e "\n${CYAN}── $* ${NC}"; }
get_env() { grep "^${1}=" "${APP_DIR}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d ' '; }

echo ""
echo -e "${CYAN}╔══════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia — App Bootstrap       ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════╝${NC}"
echo ""

# ── Node.js ───────────────────────────────────────────────────────────────────
step "Node.js & npm"
if [[ -z "${NODE}" ]] || [[ -z "${NPM}" ]]; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null
    apt-get install -y -q nodejs
    NODE=$(command -v node); NPM=$(command -v npm)
fi
ok "Node.js $(${NODE} -v) / npm $(${NPM} -v)"

# ── .env ──────────────────────────────────────────────────────────────────────
step ".env file"
if [[ ! -f "${APP_DIR}/.env" ]]; then
    cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
    ok ".env created from example"
fi
APP_URL_CURRENT=$(get_env APP_URL)
if [[ -z "${APP_URL_CURRENT}" ]] || [[ "${APP_URL_CURRENT}" == "http://localhost"* ]] || [[ "${APP_URL_CURRENT}" =~ ^http://[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+ ]]; then
    SERVER_IP=$(hostname -I | awk '{print $1}')
    sed -i "s|APP_URL=.*|APP_URL=http://${SERVER_IP}|" "${APP_DIR}/.env"
    ok ".env APP_URL → http://${SERVER_IP}"
else
    ok ".env APP_URL preserved → ${APP_URL_CURRENT}"
fi

# ── Ownership ─────────────────────────────────────────────────────────────────
step "Ownership"
chown -R root:root "${APP_DIR}"
chmod -R 755 "${APP_DIR}"
ok "Done"

# ── Composer ──────────────────────────────────────────────────────────────────
step "PHP dependencies"
if [[ -z "${COMPOSER}" ]]; then
    curl -sS https://getcomposer.org/installer | "${PHP}" -- --install-dir=/usr/local/bin --filename=composer --quiet
    COMPOSER=/usr/local/bin/composer
fi
COMPOSER_ALLOW_SUPERUSER=1 "${PHP}" "${COMPOSER}" install --no-dev --optimize-autoloader --no-interaction --quiet
"${PHP}" artisan package:discover --ansi 2>/dev/null || true
ok "Packages installed"

# ── Frontend ──────────────────────────────────────────────────────────────────
step "Frontend assets"
"${NPM}" ci --silent
"${NPM}" run build
ok "Vite build complete"

# ── App key ───────────────────────────────────────────────────────────────────
step "App key"
KEY=$(get_env APP_KEY)
if [[ -z "${KEY}" ]]; then
    "${PHP}" artisan key:generate --force --quiet
    ok "App key generated"
else
    ok "App key already set — preserved"
fi

# ── Storage symlink ───────────────────────────────────────────────────────────
step "Storage symlink"
if [[ ! -L "${APP_DIR}/public/storage" ]]; then
    "${PHP}" artisan storage:link --quiet
    ok "Symlink created"
else
    ok "Already exists"
fi

# ── DVR & log directories ─────────────────────────────────────────────────────
step "DVR and log directories"
DVR_DIR=$(get_env DVR_BASE_PATH)
DVR_DIR="${DVR_DIR:-/var/skymedia/dvr}"
mkdir -p "${DVR_DIR}" /var/log/skymedia
chown -R www-data:www-data "${DVR_DIR}" /var/log/skymedia 2>/dev/null || true
ok "${DVR_DIR}"

# ── Database ──────────────────────────────────────────────────────────────────
step "Database"
DB_CONN=$(get_env DB_CONNECTION)
DB_CONN="${DB_CONN:-sqlite}"
info "Driver: ${DB_CONN}"

if [[ "${DB_CONN}" == "sqlite" ]]; then
    DB_DATABASE=$(get_env DB_DATABASE)
    # If relative path or empty, default to database/database.sqlite
    if [[ -z "${DB_DATABASE}" ]] || [[ "${DB_DATABASE}" != /* ]]; then
        DB_DATABASE="${APP_DIR}/database/database.sqlite"
        # Ensure .env has the absolute path
        if grep -q "^DB_DATABASE=" "${APP_DIR}/.env"; then
            sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE}|" "${APP_DIR}/.env"
        else
            echo "DB_DATABASE=${DB_DATABASE}" >> "${APP_DIR}/.env"
        fi
    fi
    mkdir -p "$(dirname "${DB_DATABASE}")"
    [[ ! -f "${DB_DATABASE}" ]] && touch "${DB_DATABASE}" && ok "SQLite file created: ${DB_DATABASE}" || ok "SQLite file exists: ${DB_DATABASE}"

    # Check if tables exist via artisan
    TABLES=$("${PHP}" artisan tinker --execute="echo \Illuminate\Support\Facades\Schema::hasTable('channels') ? '1' : '0';" 2>/dev/null | grep -E '^[01]$' | tail -1 || echo "0")
else
    DB_HOST=$(get_env DB_HOST);     DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_DATABASE=$(get_env DB_DATABASE)
    DB_USERNAME=$(get_env DB_USERNAME)
    DB_PASSWORD=$(get_env DB_PASSWORD)
    TABLES=$(mysql -h"${DB_HOST}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" \
        -e "SHOW TABLES LIKE 'channels';" 2>/dev/null | grep -c "channels" || echo "0")
fi

if [[ "${TABLES}" == "0" ]]; then
    info "First run — migrating and seeding..."
    "${PHP}" artisan migrate --force --seed --quiet
    ok "Database migrated and seeded"
else
    "${PHP}" artisan migrate --force --quiet
    ok "Migrations applied (data preserved)"
fi

# ── Caches ────────────────────────────────────────────────────────────────────
step "Caches"
systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
"${PHP}" artisan optimize:clear --quiet 2>/dev/null || true
"${PHP}" artisan config:cache  --quiet
"${PHP}" artisan route:cache   --quiet
"${PHP}" artisan view:cache    --quiet
"${PHP}" artisan event:cache   --quiet
ok "All caches warmed"

# ── Permissions ───────────────────────────────────────────────────────────────
step "Permissions"
chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" "${APP_DIR}/database" 2>/dev/null || true
chmod -R 777 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" "${APP_DIR}/database" 2>/dev/null || true
# SQLite file must be writable by www-data
if [[ "${DB_CONN}" == "sqlite" ]] && [[ -f "${DB_DATABASE:-}" ]]; then
    chown www-data:www-data "${DB_DATABASE}" "$(dirname "${DB_DATABASE}")" 2>/dev/null || true
    chmod 777 "${DB_DATABASE}" "$(dirname "${DB_DATABASE}")" 2>/dev/null || true
fi
ok "Done"

# ── Nginx ─────────────────────────────────────────────────────────────────────
step "Nginx"
cp "${APP_DIR}/deployment/nginx.conf" /etc/nginx/sites-available/skymedia
ln -sf /etc/nginx/sites-available/skymedia /etc/nginx/sites-enabled/skymedia
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl enable nginx --quiet && systemctl restart nginx
ok "Nginx configured and started"

# ── Admin user ────────────────────────────────────────────────────────────────
step "Admin user"
ADMIN_EMAIL="admin@skymedia.local"
CREDS_FILE="/root/skymedia_credentials.txt"
USER_COUNT=$("${PHP}" artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | grep -E '^[0-9]+$' | tail -1 || echo "0")

if [[ "${USER_COUNT}" == "0" ]]; then
    ADMIN_PASS=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)
    "${PHP}" artisan tinker --execute="
        \App\Models\User::create([
            'name'               => 'Admin',
            'email'              => '${ADMIN_EMAIL}',
            'password'           => bcrypt('${ADMIN_PASS}'),
            'email_verified_at'  => now(),
        ]);
    " 2>/dev/null
    { echo "# SkyMedia Admin — $(date)"; echo "ADMIN_EMAIL=${ADMIN_EMAIL}"; echo "ADMIN_PASSWORD=${ADMIN_PASS}"; } >> "${CREDS_FILE}"
    chmod 600 "${CREDS_FILE}"
    ok "Admin created — creds in ${CREDS_FILE}"
else
    ADMIN_PASS=$(grep 'ADMIN_PASSWORD' "${CREDS_FILE}" 2>/dev/null | tail -1 | cut -d= -f2 || echo "(see ${CREDS_FILE})")
    ok "Admin already exists"
fi

# ── Supervisor ────────────────────────────────────────────────────────────────
step "Supervisor"
systemctl enable supervisor --quiet 2>/dev/null || true
systemctl start supervisor --quiet 2>/dev/null || true
cp "${APP_DIR}/deployment/supervisord.conf" /etc/supervisor/conf.d/skymedia.conf
supervisorctl reread  >/dev/null 2>&1 || true
supervisorctl update  >/dev/null 2>&1 || true
for svc in skymedia-monitor skymedia-scheduler skymedia-queue; do
    supervisorctl restart "${svc}" 2>/dev/null || true
done
supervisorctl start skymedia-boot 2>/dev/null || true
ok "Services running"

NGINX_PORT=8888
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║          SkyMedia is ready!                          ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║  🌐 URL      : ${CYAN}http://${SERVER_IP}:${NGINX_PORT}${GREEN}                    ║${NC}"
echo -e "${GREEN}║  📧 Email    : ${YELLOW}${ADMIN_EMAIL}${GREEN}            ║${NC}"
echo -e "${GREEN}║  🔒 Password : ${YELLOW}${ADMIN_PASS}${GREEN}                      ║${NC}"
echo -e "${GREEN}║  💾 Creds    : /root/skymedia_credentials.txt        ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
supervisorctl status 2>/dev/null | sed 's/^/    /'
echo ""
