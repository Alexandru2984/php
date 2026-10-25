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

storage_dir="${tmp_dir}/storage"
backup_dir="${tmp_dir}/backups"
scratch_dir="${tmp_dir}/scratch"
status_file="${backup_dir}/restore-drill.last-success"
mkdir -p "${storage_dir}" "${backup_dir}" "${scratch_dir}"
chmod 0700 "${storage_dir}" "${backup_dir}" "${scratch_dir}"

export APP_ENV=testing
export APP_SECRET=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
ADMIN_PASSWORD_HASH="$(php -r 'echo password_hash("restore-drill-test", PASSWORD_BCRYPT, ["cost" => 4]);')"
export ADMIN_PASSWORD_HASH
export APP_ORIGIN=https://example.test
export APP_STORAGE_DIR="${storage_dir}"
php "${repo_dir}/scripts/migrate.php" >/dev/null
sqlite3 "${storage_dir}/database.sqlite" \
    "INSERT INTO messages (author, content) VALUES ('Restore fixture', 'indexed recovery content');"

GUESTBOOK_DB_PATH="${storage_dir}/database.sqlite" \
GUESTBOOK_BACKUP_DIR="${backup_dir}" \
    bash "${repo_dir}/scripts/backup.sh" >/dev/null

drill_env=(
    "GUESTBOOK_BACKUP_DIR=${backup_dir}"
    "GUESTBOOK_RESTORE_STATUS_FILE=${status_file}"
    "GUESTBOOK_RESTORE_TMP_DIR=${scratch_dir}"
    'GUESTBOOK_RESTORE_MAX_BACKUP_AGE_SECONDS=108000'
    'GUESTBOOK_EXPECTED_MIGRATIONS=8'
)

output="$(env "${drill_env[@]}" bash "${repo_dir}/scripts/restore-drill.sh")"
[[ "${output}" == guestbook-restore-drill:\ ok* ]]
[[ -f "${status_file}" && "$(stat -c '%a' "${status_file}")" == 600 ]]
grep --quiet '^success_epoch=[0-9]\+ backup_mtime=[0-9]\+ migrations=8$' "${status_file}"
[[ -z "$(find "${scratch_dir}" -mindepth 1 -print -quit)" ]]

backup_file="$(find "${backup_dir}" -maxdepth 1 -type f -name 'database-*.sqlite.gz' -print -quit)"
cp "${backup_file}" "${tmp_dir}/valid.sqlite.gz"
gzip -dc "${tmp_dir}/valid.sqlite.gz" >"${tmp_dir}/broken-owner.sqlite"
sqlite3 "${tmp_dir}/broken-owner.sqlite" \
    "INSERT INTO messages
        (author, content, status, moderation_reason, owner_withdrawn_at)
     VALUES ('Invalid owner fixture', 'must fail restore', 'deleted', 'policy', CURRENT_TIMESTAMP);"
gzip -c "${tmp_dir}/broken-owner.sqlite" >"${backup_file}"
chmod 0600 "${backup_file}"
set +e
owner_output="$(env "${drill_env[@]}" bash "${repo_dir}/scripts/restore-drill.sh" 2>&1)"
owner_exit=$?
set -e
[[ "${owner_exit}" -ne 0 ]]
[[ "${owner_output}" == *'component=restore reason=ownership-invariant-invalid'* ]]

gzip -dc "${tmp_dir}/valid.sqlite.gz" >"${tmp_dir}/broken-index.sqlite"
sqlite3 "${tmp_dir}/broken-index.sqlite" \
    "INSERT INTO messages_fts(messages_fts, rowid, author, content)
     SELECT 'delete', id, author, content FROM messages LIMIT 1;"
gzip -c "${tmp_dir}/broken-index.sqlite" >"${backup_file}"
chmod 0600 "${backup_file}"
set +e
index_output="$(env "${drill_env[@]}" bash "${repo_dir}/scripts/restore-drill.sh" 2>&1)"
index_exit=$?
set -e
[[ "${index_exit}" -ne 0 ]]
[[ "${index_output}" == *'component=restore reason=search-index-invalid'* ]]

printf 'not-a-gzip-stream\n' >"${backup_file}"
chmod 0600 "${backup_file}"
set +e
invalid_output="$(env "${drill_env[@]}" bash "${repo_dir}/scripts/restore-drill.sh" 2>&1)"
invalid_exit=$?
set -e
[[ "${invalid_exit}" -ne 0 ]]
[[ "${invalid_output}" == *'component=backup reason=gzip-invalid'* ]]

mv "${tmp_dir}/valid.sqlite.gz" "${backup_file}"
chmod 0600 "${backup_file}"
touch -d '3 days ago' "${backup_file}"
set +e
stale_output="$(env "${drill_env[@]}" bash "${repo_dir}/scripts/restore-drill.sh" 2>&1)"
stale_exit=$?
set -e
[[ "${stale_exit}" -ne 0 ]]
[[ "${stale_output}" == *'component=backup reason=stale'* ]]

echo 'Restore drill smoke tests passed.'
