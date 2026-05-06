FROM serversideup/php:8.4-frankenphp

# Composer for installing PHP deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

USER www-data
WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist \
    && composer clear-cache

COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data src ./src
COPY --chown=www-data:www-data .env.example ./.env.example

# zips/ and cache/ are mounted as volumes by compose.yml
RUN mkdir -p zips cache
