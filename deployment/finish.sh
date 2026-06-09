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
PHP=$(command -v php8.2 || command -v php)
COMPOSER=$(command -v composer || find /usr/local/bin /usr/bin /home -name composer 2>/dev/null | head -1)
NODE=$(command -v node || command -v nodejs)
NPM=$(command -v npm)

GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
step() { echo -e "\n${CYAN}── $* ${NC}"; }

echo ""
echo -e "${CYAN}╔══════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia — App Bootstrap       ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════╝${NC}"
echo ""

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
"${NPM}" ci --silent
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
"${PHP}" artisan config:cache  --quiet
"${PHP}" artisan route:cache   --quiet
"${PHP}" artisan view:cache    --quiet
"${PHP}" artisan event:cache   --quiet
ok "All caches warmed"

step "Permissions"
chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
chmod -R 775 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
ok "Permissions set"

step "Supervisor"
cp "${APP_DIR}/deployment/supervisord.conf" /etc/supervisor/conf.d/skymedia.conf
supervisorctl reread  >/dev/null 2>&1 || true
supervisorctl update  >/dev/null 2>&1 || true
supervisorctl start skymedia-monitor   2>/dev/null || supervisorctl restart skymedia-monitor   2>/dev/null || true
supervisorctl start skymedia-scheduler 2>/dev/null || supervisorctl restart skymedia-scheduler 2>/dev/null || true
supervisorctl start skymedia-queue     2>/dev/null || supervisorctl restart skymedia-queue     2>/dev/null || true
ok "Supervisor services running"

echo ""
echo -e "${GREEN}╔═════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║   SkyMedia is ready!                        ║${NC}"
echo -e "${GREEN}╚═════════════════════════════════════════════╝${NC}"
echo ""
APP_URL=$(grep '^APP_URL' .env | cut -d= -f2 | tr -d ' ')
echo -e "  URL     : ${CYAN}${APP_URL}${NC}"
echo -e "  SSL     : ${YELLOW}certbot --nginx -d $(echo "${APP_URL}" | sed 's|https\?://||')${NC}"
echo ""
echo -e "  Supervisor status:"
supervisorctl status 2>/dev/null | sed 's/^/    /'
echo ""
