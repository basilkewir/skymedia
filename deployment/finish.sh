#!/usr/bin/env bash
# =============================================================================
# SkyMedia — finish.sh
#
# Run ONCE after install.sh, once your app files are in place.
# Safe to re-run — will skip steps already done, NEVER drops data.
#
# Usage:
#   sudo bash /var/www/skymedia/deployment/finish.sh
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/skymedia"
cd "${APP_DIR}"

# Resolve binaries — handles sudo stripping PATH
PHP=$(command -v php8.2 || command -v php || true)
PHP_VER=$("${PHP}" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.2")
COMPOSER=$(command -v composer || find /usr/local/bin /usr/bin /home -name composer 2>/dev/null | head -1 || true)
NODE=$(command -v node || command -v nodejs || true)
NPM=$(command -v npm || true)

GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
step() { echo -e "\n${CYAN}── $* ${NC}"; }

echo ""
echo -e "${CYAN}╔══════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia — App Bootstrap       ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════╝${NC}"
echo ""

step "Node.js & npm"
if [[ -z "${NODE}" ]] || [[ -z "${NPM}" ]]; then
    info "Node.js not found — installing Node.js 20..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null
    apt-get install -y -q nodejs
    NODE=$(command -v node)
    NPM=$(command -v npm)
    ok "Node.js $(${NODE} -v) installed"
else
    ok "Node.js $(${NODE} -v) / npm $(${NPM} -v)"
fi

step ".env file"
if [[ ! -f "${APP_DIR}/.env" ]]; then
    # Auto-detect server IP
    SERVER_IP=$(hostname -I | awk '{print $1}')
    cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
    sed -i "s|APP_URL=.*|APP_URL=http://${SERVER_IP}|" "${APP_DIR}/.env"
    ok ".env created — APP_URL set to http://${SERVER_IP}"
else
    # Update APP_URL with current IP even if .env exists
    SERVER_IP=$(hostname -I | awk '{print $1}')
    sed -i "s|APP_URL=.*|APP_URL=http://${SERVER_IP}|" "${APP_DIR}/.env"
    ok ".env already exists — APP_URL updated to http://${SERVER_IP}"
fi

step "Fix ownership"
chown -R root:root "${APP_DIR}"
chmod -R 755 "${APP_DIR}"
ok "Ownership fixed"

step "PHP dependencies"
if [[ -z "${COMPOSER}" ]]; then
    curl -sS https://getcomposer.org/installer | "${PHP}" -- --install-dir=/usr/local/bin --filename=composer --quiet
    COMPOSER=/usr/local/bin/composer
    ok "Composer installed"
fi
info "Using composer: ${COMPOSER}"
info "Using PHP: ${PHP}"
COMPOSER_ALLOW_SUPERUSER=1 "${PHP}" "${COMPOSER}" install --no-dev --optimize-autoloader --no-interaction --quiet
"${PHP}" artisan package:discover --ansi 2>/dev/null || true
ok "Composer packages installed"

step "Frontend assets"
info "Using node: ${NODE} ($(${NODE} -v))"
"${NPM}" ci
"${NPM}" run build
ok "Vite build complete"

step "App key"
if grep -q '^APP_KEY=$' .env 2>/dev/null || grep -q "^APP_KEY=\"\"" .env 2>/dev/null; then
    "${PHP}" artisan key:generate --force --quiet
    ok "App key generated"
else
    ok "App key already set — preserved"
fi

step "Storage symlink"
if [[ ! -L "${APP_DIR}/public/storage" ]]; then
    "${PHP}" artisan storage:link --quiet
    ok "Storage symlink created"
else
    ok "Storage symlink already exists"
fi

step "Database migrations (--seed on first run)"
get_env() { grep "^${1}=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'"'" | tr -d ' '; }
TABLES_EXIST=$(mysql -u"$(get_env DB_USERNAME)" -p"$(get_env DB_PASSWORD)" "$(get_env DB_DATABASE)" \
    -e "SHOW TABLES LIKE 'channels';" 2>/dev/null | grep -c "channels" || true)

if [[ "${TABLES_EXIST}" -eq 0 ]]; then
    info "First run — seeding default settings..."
    "${PHP}" artisan migrate --force --seed --quiet
    ok "Database migrated and seeded"
else
    "${PHP}" artisan migrate --force --quiet
    ok "Database migrations applied (existing data preserved)"
fi

step "Caches"
# Restart PHP-FPM to clear stale opcache after code update
systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
# Clear all Laravel caches before rebuilding them
"${PHP}" artisan optimize:clear --quiet 2>/dev/null || true
"${PHP}" artisan config:cache  --quiet
"${PHP}" artisan route:cache   --quiet
"${PHP}" artisan view:cache    --quiet
"${PHP}" artisan event:cache   --quiet
ok "All caches warmed"

step "Permissions"
chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
chmod -R 775 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
ok "Permissions set"

step "Nginx"
# Install nginx config — always refresh it so server_name stays correct
cp "${APP_DIR}/deployment/nginx.conf" /etc/nginx/sites-available/skymedia
ln -sf /etc/nginx/sites-available/skymedia /etc/nginx/sites-enabled/skymedia
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl enable nginx --quiet && systemctl restart nginx
ok "Nginx configured and started"

step "Admin user"
ADMIN_EMAIL="admin@skymedia.local"
ADMIN_PASS=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)
# Only create if no users exist
USER_EXISTS=$(mysql -u"$(get_env DB_USERNAME)" -p"$(get_env DB_PASSWORD)" "$(get_env DB_DATABASE)" \
    -se "SELECT COUNT(*) FROM users;" 2>/dev/null || echo "0")
if [[ "${USER_EXISTS}" == "0" ]]; then
    "${PHP}" artisan tinker --execute="
        \App\Models\User::create([
            'name' => 'Admin',
            'email' => '${ADMIN_EMAIL}',
            'password' => bcrypt('${ADMIN_PASS}'),
            'email_verified_at' => now(),
        ]);
    " 2>/dev/null
    # Save credentials
    {
        echo "# SkyMedia Admin credentials — $(date)"
        echo "ADMIN_EMAIL=${ADMIN_EMAIL}"
        echo "ADMIN_PASSWORD=${ADMIN_PASS}"
    } >> /root/skymedia_credentials.txt
    ok "Admin user created"
else
    ADMIN_PASS=$(grep 'ADMIN_PASSWORD' /root/skymedia_credentials.txt 2>/dev/null | tail -1 | cut -d= -f2 || echo "(see /root/skymedia_credentials.txt)")
    ok "Admin user already exists"
fi
cp "${APP_DIR}/deployment/supervisord.conf" /etc/supervisor/conf.d/skymedia.conf
supervisorctl reread  >/dev/null 2>&1 || true
supervisorctl update  >/dev/null 2>&1 || true
supervisorctl start skymedia-monitor   2>/dev/null || supervisorctl restart skymedia-monitor   2>/dev/null || true
supervisorctl start skymedia-scheduler 2>/dev/null || supervisorctl restart skymedia-scheduler 2>/dev/null || true
supervisorctl start skymedia-queue     2>/dev/null || supervisorctl restart skymedia-queue     2>/dev/null || true
ok "Supervisor services running"

NGINX_PORT=8888

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║            SkyMedia is ready!                            ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║  🌐 Admin URL   : ${CYAN}http://${SERVER_IP}:${NGINX_PORT}${GREEN}                     ║${NC}"
echo -e "${GREEN}║  🔑 Login page  : ${CYAN}http://${SERVER_IP}:${NGINX_PORT}/login${GREEN}              ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║  📧 Email       : ${YELLOW}${ADMIN_EMAIL}${GREEN}              ║${NC}"
echo -e "${GREEN}║  🔒 Password    : ${YELLOW}${ADMIN_PASS}${GREEN}                        ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║  💾 Credentials saved to: /root/skymedia_credentials.txt   ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Supervisor status:"
supervisorctl status 2>/dev/null | sed 's/^/    /'
echo ""
