#!/usr/bin/env bash
# Kurzbefehle fuer die Entwicklung – Aufruf: ./dev.sh <befehl>
# Die Datenbank laeuft in Docker, Backend und Frontend lokal.
set -euo pipefail
cd "$(dirname "$0")"

case "${1:-help}" in
  # PostgreSQL starten und warten, bis sie Verbindungen annimmt
  db)
    docker compose up -d database
    printf 'warte auf die Datenbank '
    for _ in $(seq 1 60); do
      if docker compose exec -T database pg_isready -U zeitmanagement -q 2>/dev/null; then
        echo '– bereit.'
        exit 0
      fi
      printf '.'
      sleep 1
    done
    echo
    echo 'Die Datenbank ist nicht hochgekommen. Logs: docker compose logs database'
    exit 1
    ;;

  db-stop)
    docker compose stop database
    ;;

  # Achtung: loescht die Datenbank samt Inhalt
  db-reset)
    docker compose down -v
    ;;

  # Abhaengigkeiten installieren (laeuft lokal, nicht im Container)
  install)
    cd backend
    [ -f .env ] || cp env.dist .env
    composer install
    php bin/console lexik:jwt:generate-keypair --skip-if-exists
    cd ../frontend
    npm install
    echo
    echo 'Fertig. Weiter mit: ./dev.sh setup'
    ;;

  # Schema anlegen und Demodaten laden
  setup)
    cd backend
    php bin/console doctrine:database:create --if-not-exists
    php bin/console doctrine:schema:update --force --complete
    php bin/console doctrine:fixtures:load --no-interaction
    ;;

  # Nur die Demodaten neu laden
  seed)
    cd backend && php bin/console doctrine:fixtures:load --no-interaction
    ;;

  backend)
    cd backend && php -S 127.0.0.1:8000 -t public
    ;;

  frontend)
    cd frontend && npm run dev
    ;;

  console)
    shift
    cd backend && php bin/console "$@"
    ;;

  test)
    (cd backend && vendor/bin/phpunit)
    (cd frontend && npm run type-check)
    ;;

  *)
    cat <<'USAGE'
Verwendung: ./dev.sh <befehl>

  db         PostgreSQL im Container starten
  install    composer install und npm install (lokal)
  setup      Schema anlegen und Demodaten laden
  backend    Symfony-Devserver auf http://127.0.0.1:8000
  frontend   Vite-Devserver auf http://localhost:5173
  seed       Demodaten neu laden
  console    Symfony-Befehl, z. B. ./dev.sh console debug:router
  test       PHPUnit und TypeScript-Check
  db-stop    Datenbank stoppen
  db-reset   Datenbank samt Inhalt loeschen
USAGE
    ;;
esac
