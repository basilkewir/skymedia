#!/usr/bin/env bash
set -e

APP_DIR=/var/www/skymedia
cd "${APP_DIR}"

echo "=== SkyMedia Docker ==="

# ── .env ──────────────────────────────────────────────────────────
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --no-interaction
fi
sed -i 's|APP_URL=.*|APP_URL=http://localhost:8888|' .env
sed -i 's|DB_CONNECTION=.*|DB_CONNECTION=sqlite|' .env
sed -i 's|DB_HOST=.*|DB_HOST=|' .env
sed -i 's|DB_PORT=.*|DB_PORT=|' .env
sed -i 's|DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' .env
sed -i 's|DB_USERNAME=.*|DB_USERNAME=|' .env
sed -i 's|DB_PASSWORD=.*|DB_PASSWORD=|' .env
sed -i 's|CACHE_DRIVER=.*|CACHE_DRIVER=file|' .env
sed -i 's|SESSION_DRIVER=.*|SESSION_DRIVER=file|' .env
sed -i 's|REDIS_HOST=.*|REDIS_HOST=|' .env

# ── Permissions ────────────────────────────────────────────────────
mkdir -p storage/framework/{cache,sessions,testing,views}
mkdir -p storage/logs/streams
mkdir -p storage/app/{pids,dvr}
chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# ── NPM build (only if not already built) ──────────────────────────
if [ ! -f public/build/manifest.json ] || [ ! -d public/build ]; then
    npm ci --silent 2>&1 | tail -3
    php artisan optimize:clear --quiet
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    npm run build 2>&1 | tail -3
    php artisan view:cache --quiet
fi

# ── Database ────────────────────────────────────────────────────────
touch database/database.sqlite
chmod 664 database/database.sqlite

php artisan migrate --force --quiet 2>&1 || php artisan migrate --force

# ── Admin user ──────────────────────────────────────────────────────
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1)
if [ "${USER_COUNT}" = "0" ] || [ -z "${USER_COUNT}" ]; then
    php artisan tinker --execute="
        \App\Models\User::create([
            'name'              => 'Admin',
            'email'             => 'admin@skymedia.local',
            'password'          => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
    " 2>/dev/null
    echo "Admin created: admin@skymedia.local / password"
fi

# ── Clear caches for runtime ────────────────────────────────────────
php artisan config:clear --quiet 2>/dev/null || true
php artisan route:clear --quiet 2>/dev/null || true

echo "=== Ready ==="

# ── Start supervisor (nginx + php-fpm + monitor) ────────────────────
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
