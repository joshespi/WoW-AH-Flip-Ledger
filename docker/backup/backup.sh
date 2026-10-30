#!/bin/sh
# Daily database dump, run by the `backup` compose service (mariadb image, so the client is here).
#   - dumps with the app's own DB user (no root), consistent via --single-transaction
#   - checks the dump finished before keeping it, then prunes dumps older than BACKUP_KEEP_DAYS
#   - writes the epoch seconds of the last good dump to /backups/LAST_OK, which the app's
#     health:check reads to warn the admin when backups stop
# BACKUP_ONCE=1 takes one dump and exits (for manual runs and tests).
# Dumps hold every user's data: files are mode 600, and the directory is not served by anything.
set -u
umask 077

OUT=/backups
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
HOUR="${BACKUP_HOUR_UTC:-3}"

mkdir -p "$OUT"

dump() {
    ts=$(date -u +%Y%m%d_%H%M%S)
    file="$OUT/${DB_DATABASE}-${ts}.sql"

    # Password via the environment, not argv, so it never shows in a process listing.
    if MYSQL_PWD="$DB_PASSWORD" mariadb-dump -h "${DB_HOST:-db}" -u "$DB_USERNAME" \
            --single-transaction --quick --default-character-set=utf8mb4 "$DB_DATABASE" > "$file" \
        && tail -c 300 "$file" | grep -q 'Dump completed' \
        && gzip "$file" && gzip -t "$file.gz"; then
        date -u +%s > "$OUT/LAST_OK"
        chmod 644 "$OUT/LAST_OK"
        echo "backup: ok $file.gz ($(du -h "$file.gz" | cut -f1))"
        find "$OUT" -name "${DB_DATABASE}-*.sql.gz" -mtime +"$KEEP_DAYS" -print -delete | sed 's/^/backup: pruned /'
        return 0
    fi

    rm -f "$file" "$file.gz"
    echo "backup: FAILED at $ts" >&2
    return 1
}

if [ "${BACKUP_ONCE:-}" = 1 ]; then
    dump
    exit $?
fi

# A failed dump is retried this often until one succeeds, instead of waiting for the next day.
RETRY_SECONDS=3600
retry=

# Catch up at start if there is no recent good dump (first run, or the stack was down overnight).
last=$(cat "$OUT/LAST_OK" 2>/dev/null || echo 0)
if [ $(( $(date -u +%s) - last )) -gt 86400 ]; then
    dump || retry=1
fi

while true; do
    now=$(date -u +%s)
    if [ -n "$retry" ]; then
        next=$((now + RETRY_SECONDS))
    else
        next=$(date -u -d "today ${HOUR}:10" +%s)
        [ "$next" -le "$now" ] && next=$((next + 86400))
    fi
    echo "backup: next dump in $(( (next - now) / 60 )) min"
    sleep $((next - now))
    if dump; then retry=; else retry=1; fi
done
