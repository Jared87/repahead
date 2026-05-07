FROM serversideup/php:8.5-frankenphp-alpine

# Composer for installing PHP deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV SERVER_NAME=":8080" \
    AUTOMATIC_HTTPS="off" \
    PHP_OPCACHE_ENABLE="1" \
    APP_BASE_URL="http://localhost:8080" \
    STORAGE_DSN="local:/var/www/html/zips" \
    CACHE_DIR="/var/www/html/cache" \
    LISTING_TTL_SECONDS="30" \
    AUTH_USER="ci"

USER www-data
WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist \
    && composer clear-cache

COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data .env.example ./.env.example

# zips/ and cache/ are mounted as volumes by compose.yml
RUN mkdir -p zips cache
