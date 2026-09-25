# Backend: Symfony auf PHP 8.4 mit Apache.
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip \
    && docker-php-ext-install pdo_mysql intl zip opcache \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache: DocumentRoot auf public/, alle Anfragen an index.php. Ohne SetEnvIf verwirft
# Apache den Authorization-Header – dann waere jeder Request nach dem Login ein 401.
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
       '<Directory /var/www/html/public>' \
       '    AllowOverride None' \
       '    FallbackResource /index.php' \
       '</Directory>' \
       'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' \
       'ServerTokens Prod' \
       'ServerSignature Off' \
       > /etc/apache2/conf-enabled/symfony.conf

WORKDIR /var/www/html
ENV APP_ENV=prod APP_DEBUG=0 COMPOSER_ALLOW_SUPERUSER=1

# Erst nur die Abhaengigkeiten – dieser Schritt bleibt im Cache, solange sich composer.lock nicht aendert.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY backend/ .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var && chown -R www-data:www-data var

COPY docker/backend-entrypoint.sh /usr/local/bin/backend-entrypoint
RUN chmod +x /usr/local/bin/backend-entrypoint

ENTRYPOINT ["backend-entrypoint"]
CMD ["apache2-foreground"]
