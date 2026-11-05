# syntax=docker/dockerfile:1

# ---- base: PHP runtime with only the extensions the app needs ----
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev linux-headers \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip gd opcache pcntl \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

# ---- vendor: production composer dependencies ----
FROM base AS vendor

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-interaction

# ---- assets: Vite + Tailwind build ----
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Tailwind scans resources/ and class strings in app/; pagination views via @source
COPY vite.config.js ./
COPY resources resources
COPY app app
COPY --from=vendor /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---- app: final runtime image (php-fpm, queue, scheduler) ----
# Code stays root-owned; only storage/ and bootstrap/cache are writable.
FROM base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    PHP_FPM_PM_MAX_CHILDREN=5

COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && rm -rf storage && mkdir -p storage/app/public storage/app/private \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && ln -sfn ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---- web: nginx with the built public/ directory ----
FROM nginx:1.28-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/templates/default.conf.template
COPY --from=app /var/www/html/public /var/www/html/public
