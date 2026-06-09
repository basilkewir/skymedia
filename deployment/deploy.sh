#!/usr/bin/env bash
# SkyMedia — post-deploy script (run after git pull)
set -euo pipefail
APP_DIR="/var/www/skymedia"
cd ${APP_DIR}

echo "[1/6] Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "[2/6] Building frontend assets..."
npm ci && npm run build

echo "[3/6] Running migrations..."
php artisan migrate --force

echo "[4/6] Clearing and warming caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "[5/6] Setting permissions..."
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "[6/6] Restarting services..."
supervisorctl restart skymedia-monitor skymedia-queue skymedia-scheduler

echo "[OK] Deploy complete."
