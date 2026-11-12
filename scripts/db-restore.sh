#!/bin/sh
# Restores a backups/*.sql.gz dump into the running `db` container, replacing the current
# database. Usage: scripts/db-restore.sh <file> [--yes]
set -eu

file="${1:-}"
confirmed="${2:-}"

. "$(dirname "$0")/lib.sh"

if [ -z "$file" ] || [ ! -f "$file" ]; then
    echo "Usage: scripts/db-restore.sh <backups/file.sql.gz> [--yes]" >&2
    exit 1
fi

if [ "$confirmed" != "--yes" ]; then
    printf "This will REPLACE all data in '%s' with the contents of %s. Continue? [y/N] " "$DB_DATABASE" "$file"
    read -r reply
    case "$reply" in
        y|Y|yes|YES) ;;
        *) echo "Aborted."; exit 1 ;;
    esac
fi

echo "Restoring $file into '$DB_DATABASE' ..."
gunzip -c "$file" | db_exec mariadb

echo "Done."
