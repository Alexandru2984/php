#!/usr/bin/env bash
# Consistent online backup of the SQLite database. Environment overrides make the
# same audited script usable by isolated restore tests and production systemd units.
set -Eeuo pipefail

umask 077
db_path="${GUESTBOOK_DB_PATH:-/srv/canary/php.micutu.com/php_micutu_app_storage/database.sqlite}"
backup_dir="${GUESTBOOK_BACKUP_DIR:-/srv/backups/php-guestbook}"
keep_days="${GUESTBOOK_BACKUP_KEEP_DAYS:-14}"

if [[ "${db_path}" != /* || "${backup_dir}" != /* || ! "${keep_days}" =~ ^[1-9][0-9]{0,3}$ ]]; then
    echo 'Backup configuration is invalid.' >&2
    exit 78
fi
if [[ "${db_path}" == *"'"* || "${db_path}" == *$'\n'* || "${backup_dir}" == *$'\n'* ]]; then
    echo 'Backup paths contain unsupported characters.' >&2
    exit 78
fi
if [[ ! -f "${db_path}" ]]; then
    echo 'Guestbook database does not exist.' >&2
    exit 66
fi

install -d -m 0700 "${backup_dir}"
exec 9>"${backup_dir}/.backup.lock"
if ! flock -n 9; then
    echo 'Another guestbook backup is already running.' >&2
    exit 75
fi

stamp="$(date -u +'%Y%m%dT%H%M%SZ')"
tmp="$(mktemp "${backup_dir}/.database-${stamp}.XXXXXX.sqlite")"
cleanup() {
    rm -f -- "${tmp}" "${tmp}.gz"
}
trap cleanup EXIT

sqlite3 "${db_path}" ".backup '${tmp}'"
if [[ "$(sqlite3 "${tmp}" 'PRAGMA integrity_check;')" != ok ]]; then
    echo 'Backup integrity check failed.' >&2
    exit 1
fi

gzip -9 "${tmp}"
final="${backup_dir}/database-${stamp}.sqlite.gz"
mv -- "${tmp}.gz" "${final}"
gzip -t "${final}"
chmod 0600 "${final}"
sha256sum "${final}"

find "${backup_dir}" -maxdepth 1 -type f -name 'database-*.sqlite.gz' \
    -mtime "+${keep_days}" -delete

trap - EXIT
