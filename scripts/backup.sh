#!/usr/bin/env bash
# Consistent, online backup of the guestbook SQLite database.
# Uses `.backup` (safe with WAL / concurrent writes), gzips, and keeps 14 days.
set -euo pipefail

DB="/home/micu/php_micutu_app_storage/database.sqlite"
DEST="/home/micu/backups/guestbook"
KEEP_DAYS=14

mkdir -p "$DEST"
stamp="$(date +%Y%m%dT%H%M%S)"
tmp="$DEST/database-$stamp.sqlite"

# Atomic, consistent snapshot even while the app is writing.
sqlite3 "$DB" ".backup '$tmp'"

# Integrity check before we keep it.
if [ "$(sqlite3 "$tmp" 'PRAGMA integrity_check;')" != "ok" ]; then
    echo "$(date -Is) ERROR: integrity check failed for $tmp" >&2
    rm -f "$tmp"
    exit 1
fi

gzip -f "$tmp"
echo "$(date -Is) OK: ${tmp}.gz ($(du -h "${tmp}.gz" | cut -f1))"

# Rotate: drop backups older than KEEP_DAYS.
find "$DEST" -name 'database-*.sqlite.gz' -type f -mtime "+$KEEP_DAYS" -delete
