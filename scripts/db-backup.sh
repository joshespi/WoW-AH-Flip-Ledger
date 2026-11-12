#!/bin/sh
# Dumps the `db` container's database to backups/, gzipped and timestamped.
# Usage: scripts/db-backup.sh
set -eu
umask 077

. "$(dirname "$0")/lib.sh"

mkdir -p backups
out="backups/${DB_DATABASE}-$(date +%Y%m%d_%H%M%S).sql.gz"

echo "Backing up '$DB_DATABASE' to $out ..."
db_exec mariadb-dump --single-transaction --routines | gzip > "$out"

echo "Done: $out ($(du -h "$out" | cut -f1))"
