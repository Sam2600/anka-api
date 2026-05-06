# ---------- Stage 1: composer dependencies ----------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

# ---------- Stage 2: runtime ----------
FROM php:8.3-fpm-alpine AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false

RUN apk add --no-cache \
        nginx \
        supervisor \
        libpq \
        oniguruma \
        tini \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpq-dev \
        oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        mbstring \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

RUN { \
        echo "memory_limit=256M"; \
        echo "upload_max_filesize=20M"; \
        echo "post_max_size=20M"; \
        echo "expose_php=Off"; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# Bring in composer-installed vendor first so subsequent code copies invalidate fewer layers.
COPY --from=vendor /app/vendor ./vendor

# Application source
COPY . .

# Container config
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/php-fpm.pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
