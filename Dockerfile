FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize

FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.3-apache-bookworm AS runtime

RUN apt-get update \
    && apt-get install --no-install-recommends -y libonig-dev libpq-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring pcntl pdo_pgsql \
    && a2enmod headers rewrite \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /app/public/build public/build
COPY docker/php.ini /usr/local/etc/php/conf.d/cosplaynesia.ini
COPY docker/entrypoint.sh /usr/local/bin/cosplaynesia-entrypoint

RUN chmod +x /usr/local/bin/cosplaynesia-entrypoint \
    && mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

ENTRYPOINT ["cosplaynesia-entrypoint"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r '$body = @file_get_contents("http://127.0.0.1/ready"); exit($body !== false && str_contains($body, "ready") ? 0 : 1);'
