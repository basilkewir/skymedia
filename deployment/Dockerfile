FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive \
    APP_DIR=/var/www/skymedia

# ── System packages ────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg lsb-release software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update && apt-get install -y --no-install-recommends \
    php8.2-cli php8.2-fpm php8.2-sqlite3 php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-bcmath php8.2-zip php8.2-gd \
    php8.2-intl php8.2-redis \
    nginx supervisor ffmpeg git unzip sqlite3 \
    nodejs npm \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ── Composer ───────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ── Nginx config ────────────────────────────────────────────────────
COPY deployment/nginx-docker.conf /etc/nginx/sites-enabled/skymedia
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-enabled/*default* 2>/dev/null; \
    mkdir -p /run/php && chown www-data:www-data /run/php; \
    sed -i 's/^user = www-data/user = root/' /etc/php/8.2/fpm/pool.d/www.conf; \
    sed -i 's/^group = www-data/group = root/' /etc/php/8.2/fpm/pool.d/www.conf

# ── Supervisor ─────────────────────────────────────────────────────
COPY deployment/supervisord-docker.conf /etc/supervisor/conf.d/skymedia.conf

# ── App code ────────────────────────────────────────────────────────
WORKDIR ${APP_DIR}
COPY . .

# ── PHP deps ────────────────────────────────────────────────────────
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# ── Entrypoint ──────────────────────────────────────────────────────
COPY deployment/docker-entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint"]
