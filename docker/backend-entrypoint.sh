#!/bin/sh
# Beim Start: JWT-Schluessel anlegen (falls noch keine da sind),
# Datenbankschema aktualisieren und den Cache aufwaermen.
set -e

php bin/console lexik:jwt:generate-keypair --skip-if-exists
php bin/console doctrine:schema:update --force
php bin/console cache:warmup
chown -R www-data:www-data var config/jwt

exec "$@"
