#!/usr/bin/env bash
# SkyMedia — Zero-downtime Docker deployment
# Build image first (no downtime), run migrations, then quick-swap containers.
set -euo pipefail

COMPOSE_FILE="${1:-docker-compose.prod.yml}"
APP_SERVICE="app"
HEALTH_URL="http://localhost:8080"
HEALTH_TIMEOUT=30

cd "$(dirname "$0")"

echo "=== SkyMedia Docker Deploy ==="
echo "Compose file: ${COMPOSE_FILE}"

# ── 1. Build new image (zero downtime) ──────────────────────────────
echo ""
echo "[1/5] Building new image..."
docker compose -f "${COMPOSE_FILE}" build --no-cache "${APP_SERVICE}"
NEW_IMAGE=$(docker compose -f "${COMPOSE_FILE}" images -q "${APP_SERVICE}" | head -1)
echo "  New image: ${NEW_IMAGE:0:12}"

# ── 2. Run migrations on new image ──────────────────────────────────
echo ""
echo "[2/5] Running migrations..."
docker compose -f "${COMPOSE_FILE}" run --rm "${APP_SERVICE}" php artisan migrate --force
echo "  Migrations complete."

# ── 3. Quick-swap: stop old, start new ──────────────────────────────
echo ""
echo "[3/5] Swapping containers..."

# Remember old image for rollback
OLD_IMAGE=$(docker compose -f "${COMPOSE_FILE}" images -q "${APP_SERVICE}" 2>/dev/null | head -1 || echo "")

# Stop old app container only (keep db, redis, rtmp alive)
docker compose -f "${COMPOSE_FILE}" stop "${APP_SERVICE}" 2>/dev/null || true
docker compose -f "${COMPOSE_FILE}" rm -f "${APP_SERVICE}" 2>/dev/null || true

# Start new app container
docker compose -f "${COMPOSE_FILE}" up -d "${APP_SERVICE}"

# ── 4. Health check ─────────────────────────────────────────────────
echo ""
echo "[4/5] Waiting for health check..."
elapsed=0
while [ $elapsed -lt $HEALTH_TIMEOUT ]; do
    if curl -sf "${HEALTH_URL}" > /dev/null 2>&1; then
        echo "  App healthy after ${elapsed}s"
        break
    fi
    sleep 1
    elapsed=$((elapsed + 1))
done

if [ $elapsed -ge $HEALTH_TIMEOUT ]; then
    echo ""
    echo "  HEALTH CHECK FAILED after ${HEALTH_TIMEOUT}s!"
    echo ""

    # ── 5. Rollback ─────────────────────────────────────────────────
    echo "[5/5] Rolling back..."
    docker compose -f "${COMPOSE_FILE}" stop "${APP_SERVICE}" 2>/dev/null || true
    docker compose -f "${COMPOSE_FILE}" rm -f "${APP_SERVICE}" 2>/dev/null || true
    docker compose -f "${COMPOSE_FILE}" up -d "${APP_SERVICE}"
    echo "  Rolled back to previous image."
    exit 1
fi

# ── 5. Post-deploy cleanup ──────────────────────────────────────────
echo ""
echo "[5/5] Post-deploy..."
docker compose -f "${COMPOSE_FILE}" exec -T "${APP_SERVICE}" php artisan config:clear 2>/dev/null || true
docker compose -f "${COMPOSE_FILE}" exec -T "${APP_SERVICE}" php artisan route:clear 2>/dev/null || true

# Prune old images
docker image prune -f 2>/dev/null || true

echo ""
echo "=== Deploy complete ==="
echo "App: ${HEALTH_URL}"
