#!/bin/bash
set -euo pipefail

DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="kasir_pos"
DB_USER="kasir_user"
DB_PASS="kasirpass123"
BACKUP_DIR="/var/www/kasir-pos/backups"
RETENTION_DAYS="7"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
ARCHIVE_PATH="$BACKUP_DIR/kasir_pos_$TIMESTAMP.sql.gz"

mkdir -p "$BACKUP_DIR"

mysqldump --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" \
  | gzip > "$ARCHIVE_PATH"

find "$BACKUP_DIR" -type f -name 'kasir_pos_*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Database backup created: $ARCHIVE_PATH"
