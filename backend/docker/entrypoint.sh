#!/bin/sh
# Bereitet den Backend-Container vor und startet dann PHP-FPM.
set -e

# JWT-Schluessel einmalig erzeugen; sie liegen im Volume "jwt-keys".
php bin/console lexik:jwt:generate-keypair --skip-if-exists
chown www-data:www-data config/jwt/*.pem

php bin/console cache:clear
chown -R www-data:www-data var

# Datenbank-Schema aktualisieren. Die DB ist laut Healthcheck schon da,
# die Schleife faengt nur kurze Verzoegerungen beim ersten Start ab.
attempt=0
until php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 10 ]; then
    echo "Datenbank nicht erreichbar – Abbruch." >&2
    exit 1
  fi
  echo "Warte auf die Datenbank ($attempt/10) ..."
  sleep 3
done

exec "$@"
