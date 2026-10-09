#!/usr/bin/env bash
# Kurzbefehle fuer die Entwicklung – Aufruf: ./dev.sh <befehl>
# Die Datenbank laeuft in Docker, Backend (PHP 8.4) und Frontend lokal.
set -euo pipefail
cd "$(dirname "$0")"

case "${1:-help}" in
  # MariaDB starten und warten, bis sie bereit ist
  db)
    [ -f .env ] || cp .env.example .env
    docker compose up -d --wait database
    ;;

  # Abhaengigkeiten installieren und JWT-Schluessel erzeugen
  install)
    (cd backend && composer install && php bin/console lexik:jwt:generate-keypair --skip-if-exists)
    (cd frontend && npm install)
    ;;

  # Schema anlegen und Demodaten laden (Passwort aller Demo-Konten: werkstatt)
  setup)
    cd backend
    php bin/console doctrine:migrations:migrate --no-interaction
    php bin/console doctrine:fixtures:load --no-interaction
    ;;

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
    (cd frontend && npm run type-check && npm run lint)
    ;;

  # Achtung: loescht die Datenbank samt Inhalt
  db-reset)
    docker compose down -v database
    ;;

  *)
    cat <<'USAGE'
Verwendung: ./dev.sh <befehl>

  db         MariaDB im Container starten
  install    composer install, npm install, JWT-Schluessel
  setup      Schema anlegen und Demodaten laden
  seed       Demodaten neu laden
  backend    Symfony-Devserver auf http://127.0.0.1:8000
  frontend   Vite-Devserver auf http://localhost:5173
  console    Symfony-Befehl, z. B. ./dev.sh console debug:router
  test       PHPUnit, TypeScript-Check und Lint
  db-reset   Datenbank samt Inhalt loeschen
USAGE
    ;;
esac
