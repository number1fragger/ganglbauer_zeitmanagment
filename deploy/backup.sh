#!/usr/bin/env bash
# Sichert die Datenbank nach backups/ und behaelt die letzten 30 Tage.
# Taeglich per Cron, z. B.:  30 2 * * *  /opt/werkstatt/deploy/backup.sh
set -euo pipefail
cd "$(dirname "$0")/.."

mkdir -p backups
file="backups/werkstatt-$(date +%F-%H%M).sql.gz"

docker compose exec -T database sh -c \
  'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers werkstatt' \
  | gzip > "$file.tmp"
mv "$file.tmp" "$file"
chmod 600 "$file"

find backups -name 'werkstatt-*.sql.gz' -mtime +30 -delete
echo "Gesichert: $file (aus $(docker compose ps --format '{{.Name}}' database))"
