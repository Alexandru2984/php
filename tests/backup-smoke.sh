#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"
cleanup() {
    if [[ -n "${tmp_dir}" && "${tmp_dir}" == /tmp/* ]]; then
        rm -rf -- "${tmp_dir}"
    fi
}
trap cleanup EXIT

db_path="${tmp_dir}/source.sqlite"
backup_dir="${tmp_dir}/backups"
restored_path="${tmp_dir}/restored.sqlite"

sqlite3 "${db_path}" \
    'CREATE TABLE messages (id INTEGER PRIMARY KEY, content TEXT NOT NULL); INSERT INTO messages(content) VALUES ("restore-me");'

GUESTBOOK_DB_PATH="${db_path}" \
GUESTBOOK_BACKUP_DIR="${backup_dir}" \
GUESTBOOK_BACKUP_KEEP_DAYS=14 \
    bash "${repo_dir}/scripts/backup.sh" >"${tmp_dir}/backup.log"

backup_file="$(find "${backup_dir}" -maxdepth 1 -type f -name 'database-*.sqlite.gz' -print -quit)"
[[ -n "${backup_file}" ]]
[[ "$(stat -c '%a' "${backup_file}")" == 600 ]]
gzip -t "${backup_file}"
gzip -dc "${backup_file}" >"${restored_path}"
[[ "$(sqlite3 "${restored_path}" 'PRAGMA integrity_check;')" == ok ]]
[[ "$(sqlite3 "${restored_path}" 'SELECT content FROM messages;')" == restore-me ]]

echo 'Backup and restore smoke test passed.'
