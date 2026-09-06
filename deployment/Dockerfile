FROM jrottenberg/ffmpeg:7-ubuntu

ENV DEBIAN_FRONTEND=noninteractive \
    APP_DIR=/var/www/skymedia

# ── System packages ────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg lsb-release software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update && apt-get install -y --no-install-recommends \
    php8.2-cli php8.2-fpm php8.2-sqlite3 php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-bcmath php8.2-zip php8.2-gd \
    php8.2-intl php8.2-redis php8.2-mysql \
    nginx supervisor git unzip sqlite3 \
    nodejs fonts-dejavu-core fonts-noto \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ── yt-dlp (YouTube stream extraction) ─────────────────────────────
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp

# ── Composer ───────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ── Nginx config ────────────────────────────────────────────────────
COPY deployment/nginx-docker.conf /etc/nginx/sites-enabled/skymedia
COPY deployment/nginx-hls-map.conf /etc/nginx/conf.d/hls-map.conf
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-enabled/*default* 2>/dev/null; \
    mkdir -p /run/php && chown www-data:www-data /run/php; \
    sed -i 's/^user = www-data/user = root/' /etc/php/8.2/fpm/pool.d/www.conf; \
    sed -i 's/^group = www-data/group = root/' /etc/php/8.2/fpm/pool.d/www.conf; \
    printf 'upload_max_filesize = 512M\npost_max_size = 512M\nmax_execution_time = 300\nmax_input_time = 300\n' \
        > /etc/php/8.2/cli/conf.d/99-uploads.ini; \
    printf 'upload_max_filesize = 512M\npost_max_size = 512M\nmax_execution_time = 300\nmax_input_time = 300\n' \
        > /etc/php/8.2/fpm/conf.d/99-uploads.ini

# ── Supervisor ─────────────────────────────────────────────────────
COPY deployment/supervisord-main.conf   /etc/supervisor/supervisord.conf
COPY deployment/supervisord-docker.conf /etc/supervisor/conf.d/skymedia.conf

# ── App code ────────────────────────────────────────────────────────
WORKDIR ${APP_DIR}
COPY . .

# ── PHP deps ────────────────────────────────────────────────────────
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# ── Frontend assets ─────────────────────────────────────────────────
RUN npm install && npm run build

# ── Entrypoint ──────────────────────────────────────────────────────
COPY deployment/docker-entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint"]
