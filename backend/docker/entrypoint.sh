#!/bin/sh
# Bereitet den Backend-Container vor und startet dann PHP-FPM.
set -e

case "$APP_SECRET$JWT_PASSPHRASE" in
  *nicht-fuer-produktion*)
    echo "WARNUNG: APP_SECRET/JWT_PASSPHRASE sind noch die Entwicklungswerte." >&2
    echo "         Am Server zuerst ./deploy/init-env.sh <domain> ausfuehren." >&2
    ;;
esac

# PHP-FPM laeuft als www-data. Die Konsolenbefehle laufen deshalb auch so –
# sonst legen sie Cache-Dateien als root an, in die PHP-FPM nicht schreiben
# kann (z. B. die Fehlversuche der Login-Sperre).
chown -R www-data:www-data var config/jwt
console() { su-exec www-data php bin/console "$@"; }

# JWT-Schluessel einmalig erzeugen; sie liegen im Volume "jwt-keys".
console lexik:jwt:generate-keypair --skip-if-exists

console cache:clear

# Datenbank-Schema aktualisieren. Die DB ist laut Healthcheck schon da,
# die Schleife faengt nur kurze Verzoegerungen beim ersten Start ab.
attempt=0
until console doctrine:migrations:migrate --no-interaction --allow-no-migration; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 10 ]; then
    echo "Datenbank nicht erreichbar – Abbruch." >&2
    exit 1
  fi
  echo "Warte auf die Datenbank ($attempt/10) ..."
  sleep 3
done

exec "$@"
