#!/bin/bash

BACKUP_DIR="/var/backups/wordpress"
DB_NAME="WordPress"
DB_USER="WordPressUser"
DB_PASSWORD="wordpress"

DATE=$(date +"%Y-%m-%d_%H-%M-%S")
BACKUP_FILE="$BACKUP_DIR/wordpress_$DATE.sql.gz"

mysqldump --no-tablespaces -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" | gzip > "$BACKUP_FILE"

find "$BACKUP_DIR" -type f -name "wordpress_*.sql.gz" -mtime +6 -delete
