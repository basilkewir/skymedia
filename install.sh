#!/usr/bin/env bash
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}    $*"; }
info() { echo -e "${CYAN}[INFO]${NC}  $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }
step() { echo -e "\n${CYAN}── $*${NC}"; }

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$APP_DIR"

PHP=php
COMPOSER=composer
NPM=npm

echo ""
echo -e "${CYAN}╔══════════════════════════════════╗${NC}"
echo -e "${CYAN}║   SkyMedia — Install             ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════╝${NC}"
echo ""

# ── .env ──────────────────────────────────────────────────────────────
step "1/9  .env file"
if [[ ! -f .env ]]; then
    cp .env.example .env
    ok ".env created from .env.example — edit DB credentials if needed"
else
    ok ".env already exists — preserved"
fi

# ── App key ────────────────────────────────────────────────────────────
step "2/9  App key"
if grep -q "^APP_KEY=$" .env || ! grep -q "^APP_KEY=" .env; then
    $PHP artisan key:generate --force
    ok "App key generated"
else
    ok "App key already set"
fi

# ── Composer ──────────────────────────────────────────────────────────
step "3/9  PHP dependencies"
$COMPOSER install --no-interaction --prefer-dist --optimize-autoloader
ok "PHP packages installed"

# ── Frontend ──────────────────────────────────────────────────────────
step "4/9  Frontend assets"
$NPM install --no-audit --no-fund
$NPM run build
ok "Frontend assets built"

# ── Storage symlink ───────────────────────────────────────────────────
step "5/9  Storage symlink"
if [[ ! -L public/storage ]]; then
    $PHP artisan storage:link
    ok "public/storage → storage/app/public"
else
    ok "Symlink already exists"
fi

# ── Database ──────────────────────────────────────────────────────────
step "6/9  Database"
DB_CONN=$(grep "^DB_CONNECTION=" .env | cut -d= -f2- | tr -d '"' | tr -d ' ' || echo "sqlite")

if [[ "$DB_CONN" == "sqlite" ]]; then
    mkdir -p database
    touch database/database.sqlite
    chmod 664 database/database.sqlite
    ok "SQLite database ready"
else
    ok "MySQL/other — ensure your DB server is running and .env credentials are correct"
fi

$PHP artisan migrate --force
ok "Migrations applied"

# ── Caches ────────────────────────────────────────────────────────────
step "7/9  Caches"
$PHP artisan optimize:clear 2>/dev/null || true
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache
ok "Caches warmed"

# ── Permissions ──────────────────────────────────────────────────────
step "8/9  Permissions"
mkdir -p storage/framework/{cache,sessions,testing,views}
mkdir -p storage/logs/streams
mkdir -p storage/app/dvr
chmod -R 777 storage bootstrap/cache database
ok "Permissions set"

# ── Admin user (only if fresh DB) ─────────────────────────────────────
step "9/9  Admin user"
USER_COUNT=$($PHP artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | grep -E '^[0-9]+$' | tail -1 || echo "0")
if [[ "$USER_COUNT" == "0" ]]; then
    $PHP artisan db:seed --force 2>/dev/null || true
    $PHP artisan tinker --execute="
        \App\Models\User::firstOrCreate(
            ['email' => 'admin@skymedia.local'],
            ['name' => 'Admin', 'password' => bcrypt('password'), 'email_verified_at' => now()]
        );
    " 2>/dev/null || true
    ok "Admin created: admin@skymedia.local / password"
else
    ok "Admin user already exists — preserved"
fi

echo ""
echo -e "${GREEN}╔══════════════════════════════════╗${NC}"
echo -e "${GREEN}║   SkyMedia is ready!              ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════╝${NC}"
echo ""
info "Run:  php artisan serve"
echo ""
