# Backend: Symfony auf PHP 8.4 mit Apache.
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip \
    && docker-php-ext-install pdo_mysql intl zip opcache \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache: DocumentRoot auf public/, alle Anfragen an index.php.
#  - SetEnvIf: sonst verwirft Apache den Authorization-Header (jeder Request nach dem Login waere 401)
#  - PassEnv:  sonst sieht PHP die Umgebungsvariablen aus docker-compose nicht und faellt auf die
#              .env zurueck (APP_ENV=dev, falsche Datenbank)
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
       '<Directory /var/www/html/public>' \
       '    AllowOverride None' \
       '    FallbackResource /index.php' \
       '</Directory>' \
       'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' \
       'PassEnv APP_ENV APP_DEBUG APP_SECRET APP_TIMEZONE DATABASE_URL JWT_PASSPHRASE' \
       'ServerName localhost' \
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
