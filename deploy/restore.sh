#!/usr/bin/env bash
# Spielt eine Sicherung aus backups/ zurueck – ACHTUNG: ersetzt alle aktuellen Daten.
# Aufruf:  ./deploy/restore.sh backups/werkstatt-2026-10-20-0230.sql.gz
set -euo pipefail
cd "$(dirname "$0")/.."

file="${1:?Verwendung: $0 <backup.sql.gz>}"
[ -f "$file" ] || { echo "Datei nicht gefunden: $file" >&2; exit 1; }

container=$(docker compose ps --format '{{.Name}}' database)
[ -n "$container" ] || { echo "Datenbank-Container laeuft nicht." >&2; exit 1; }

echo "Ziel: Datenbank im Container $container (Ordner $(pwd))"
read -r -p "Alle aktuellen Daten dort durch $file ersetzen? (ja/nein) " answer
[ "$answer" = "ja" ] || { echo "Abgebrochen."; exit 1; }

gunzip -c "$file" | docker compose exec -T database sh -c \
  'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" werkstatt'
echo "Zurueckgespielt. Backend neu starten (fuehrt ggf. neuere Migrationen aus):"
echo "  docker compose restart backend"
