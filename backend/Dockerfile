# --- Abhaengigkeiten ---------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Die PHP-Erweiterungen bringt erst das Laufzeit-Image mit.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative

# --- Laufzeit: PHP-FPM ---------------------------------------------------------
FROM php:8.4-fpm-alpine

RUN apk add --no-cache icu-libs \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
 && docker-php-ext-install -j"$(nproc)" intl opcache pdo_mysql \
 && apk del .build-deps

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-werkstatt.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /app
COPY --from=vendor /app /app
COPY docker/.env /app/.env
RUN mkdir -p var config/jwt && chown -R www-data:www-data var config/jwt

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    TZ=Europe/Vienna

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
