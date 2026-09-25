#!/bin/sh
# Beim Start des Backend-Containers:
#  1. JWT-Schluessel anlegen (nur beim ersten Start)
#  2. Datenbankschema auf den aktuellen Stand bringen
#  3. ersten Chef anlegen, falls ADMIN_EMAIL gesetzt ist und es ihn noch nicht gibt
#  4. Cache aufwaermen
set -e

php bin/console lexik:jwt:generate-keypair --skip-if-exists
php bin/console doctrine:schema:update --force

if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
    php bin/console app:create-user "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$ADMIN_FIRSTNAME" "$ADMIN_LASTNAME" \
        --role=chef --skip-if-exists
fi

php bin/console cache:warmup
chown -R www-data:www-data var config/jwt

exec "$@"
