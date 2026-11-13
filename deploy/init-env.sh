#!/usr/bin/env bash
# Legt am Server die .env mit zufaelligen Passwoertern an.
# Aufruf:  ./deploy/init-env.sh zeit.ganglbauer.at
#          ./deploy/init-env.sh :80        (nur HTTP, z. B. im Firmennetz ohne Domain)
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -f .env ]; then
  echo ".env gibt es schon – nichts geaendert. (Zum Neuanlegen erst loeschen.)" >&2
  exit 1
fi
if [ $# -ne 1 ]; then
  echo "Verwendung: $0 <domain>   z. B. $0 zeit.ganglbauer.at" >&2
  exit 1
fi

secret() { openssl rand -hex 24; }

cat > .env <<ENV
# Erzeugt von deploy/init-env.sh am $(date +%F). Nicht ins Git, nicht weitergeben.
COMPOSE_FILE=docker-compose.yml:docker-compose.server.yml

DOMAIN=$1

DB_PASSWORD=$(secret)
DB_ROOT_PASSWORD=$(secret)
APP_SECRET=$(secret)
JWT_PASSPHRASE=$(secret)

# Login-Gueltigkeit in Sekunden (12 h = eine Schicht)
JWT_TTL=43200

# Port 8080 nur am Server selbst – von aussen geht alles ueber Caddy (80/443).
HTTP_BIND=127.0.0.1
HTTP_PORT=8080
ENV
chmod 600 .env
echo ".env angelegt (Domain: $1). Weiter mit: docker compose up -d --build"
