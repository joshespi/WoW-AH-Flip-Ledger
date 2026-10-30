# Shared setup for db-backup.sh / db-restore.sh. Source, don't execute.
cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    echo "No .env found in $(pwd). Run scripts/setup.sh first." >&2
    exit 1
fi

# Only DB_DATABASE is needed; don't source .env, its values may hold shell metacharacters.
DB_DATABASE=$(grep -m1 '^DB_DATABASE=' .env | cut -d= -f2-)

if [ -z "$DB_DATABASE" ]; then
    echo "DB_DATABASE is not set in .env" >&2
    exit 1
fi
