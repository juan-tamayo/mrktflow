# MRKTFlow demo deployment image (see deploy/docker-compose.yml).
# Multi-stage: Vite assets (pinned Node) -> PHP 8.4 FPM + nginx (single container,
# supervisord) mirroring the other Laravel stacks on the same VPS.
# syntax=docker/dockerfile:1.7

FROM node:22.23.3-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY . .
RUN npm run build

FROM php:8.4-fpm-alpine AS app
ARG INSTALL_DEV=false
RUN apk add --no-cache bash curl nginx supervisor icu-libs libpng libzip libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpng-dev libzip-dev oniguruma-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl gd zip exif pcntl \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN if [ "$INSTALL_DEV" = "true" ]; then \
      composer install --no-scripts --no-plugins --no-interaction --prefer-dist; \
    else \
      composer install --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist; \
    fi
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN cp .env.example .env \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && rm .env \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache .runtime \
    && chmod -R a+rX /var/www/html \
    && chown -R www-data:www-data storage bootstrap/cache .runtime
COPY deploy/nginx.conf /etc/nginx/http.d/default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-mrktflow.ini
COPY deploy/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-mrktflow.conf
COPY deploy/supervisord-app.conf /etc/supervisor/app.conf
COPY deploy/supervisord-queue.conf /etc/supervisor/queue.conf
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["app"]
