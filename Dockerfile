# syntax=docker/dockerfile:1.7
#
# Multi-stage build for anka-api (Laravel 13, PHP 8.3).
#
# Stage 1: composer — resolves vendor/ with --no-dev --optimize-autoloader
# Stage 2: php-fpm — runtime image. nginx talks FastCGI to this on :9000.
#                    Same image is reused for queue worker + scheduler.

# ── Stage 1: composer ────────────────────────────────────────────────────────
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./

# Install vendor/ without dev deps. --no-scripts because artisan isn't here yet
# (the app code is copied in the next stage and scripts run there).
#
# --ignore-platform-req=ext-gd because the composer:2 image (Alpine) doesn't
# ship gd, but the runtime stage below installs it. Composer would otherwise
# refuse to resolve phpoffice/phpspreadsheet + phpoffice/phpword.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-req=ext-gd

# ── Stage 2: php-fpm runtime ─────────────────────────────────────────────────
FROM php:8.3-fpm-bookworm AS runtime

# System deps needed by the PHP extensions below + libs phpoffice needs at runtime.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        ca-certificates \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libfreetype6-dev \
        libxml2-dev \
        libonig-dev \
        libicu-dev \
        libpq-dev \
        zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure gd with jpeg + webp + freetype support (phpspreadsheet uses gd for images).
RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype

# Install all the PHP extensions our code uses at runtime.
#   pdo_pgsql, pgsql  → Supabase Postgres
#   zip               → phpspreadsheet, phpword
#   gd                → phpspreadsheet image cells
#   xml, dom          → phpspreadsheet, phpword, smalot/pdfparser
#   mbstring          → string handling throughout
#   bcmath            → currency arithmetic + decimal columns
#   intl              → currency formatting + ICU collation
#   opcache           → production performance
RUN docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        zip \
        gd \
        mbstring \
        bcmath \
        intl \
        opcache \
        exif

# OPcache config — tuned for a long-lived php-fpm worker pool.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Realpath cache + uploads sized for xlsx contract docs (≤25MB per the deal_contract_documents migration).
RUN { \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
        echo 'max_execution_time=120'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

# Make php-fpm listen on a TCP socket so the nginx container can reach it
# across the Docker network. Default config uses a unix socket which doesn't
# cross container boundaries.
RUN sed -i 's|^listen = .*|listen = 9000|' /usr/local/etc/php-fpm.d/www.conf \
    && echo 'clear_env = no' >> /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# Bring in vendored composer deps from stage 1.
COPY --from=composer /app/vendor ./vendor

# Bring the composer binary too — we need it for `composer dump-autoload`
# after the app code is copied in (the vendor stage only knew about
# composer.json/composer.lock, not the app's namespaces).
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Application code. .dockerignore strips secrets + cache + node_modules.
COPY . .

# Bake the estimation template into the image. Without this the first
# /estimation page request that triggers lazy XLSX regen will 500 because
# EstimationXlsxService can't find storage/app/templates/estimation_template.xlsx.
# The actual binary is not in git — `docker build` will fail loudly if missing,
# which is the correct behavior because deploying without it would 500 in prod.
RUN test -f storage/app/templates/estimation_template.xlsx \
    || (echo "ERROR: storage/app/templates/estimation_template.xlsx is missing. Copy it from your dev machine before building." && exit 1)

# Compile autoload + Laravel caches at build time so the runtime worker is fast.
# config:cache deliberately NOT run here — env values aren't present at build
# time. It runs at container start via entrypoint instead.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# Storage + bootstrap/cache must be writable by www-data (the php-fpm user).
RUN chown -R www-data:www-data storage bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 775 {} \; \
    && find storage bootstrap/cache -type f -exec chmod 664 {} \;

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]
