#!/usr/bin/env bash
# Proves that the newest production snapshot is usable without touching the live
# database. The success marker is only published after decompression, SQLite
# integrity, foreign-key and application-schema checks all pass.
set -Eeuo pipefail

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
umask 077

backup_dir="${GUESTBOOK_BACKUP_DIR:-/srv/backups/php-guestbook}"
status_file="${GUESTBOOK_RESTORE_STATUS_FILE:-${backup_dir}/restore-drill.last-success}"
tmp_root="${GUESTBOOK_RESTORE_TMP_DIR:-${TMPDIR:-/tmp}}"
max_backup_age="${GUESTBOOK_RESTORE_MAX_BACKUP_AGE_SECONDS:-108000}"
expected_migrations="${GUESTBOOK_EXPECTED_MIGRATIONS:-5}"

fail() {
    printf 'guestbook-restore-drill: FAIL component=%s reason=%s\n' "$1" "$2" >&2
    exit 1
}

require_uint() {
    local name="$1" value="$2" minimum="$3" maximum="$4"
    [[ "${value}" =~ ^[0-9]+$ ]] || fail configuration "${name}-invalid"
    (( value >= minimum && value <= maximum )) \
        || fail configuration "${name}-out-of-range"
}

for configured_path in "${backup_dir}" "${status_file}" "${tmp_root}"; do
    [[ "${configured_path}" == /* && "${configured_path}" != *$'\n'* ]] \
        || fail configuration path-invalid
done
[[ "$(dirname -- "${status_file}")" == "${backup_dir}" ]] \
    || fail configuration status-outside-backup-directory
require_uint max-backup-age "${max_backup_age}" 60 604800
require_uint expected-migrations "${expected_migrations}" 1 1000

[[ -d "${backup_dir}" && ! -L "${backup_dir}" ]] \
    || fail backup directory-invalid
[[ -d "${tmp_root}" && ! -L "${tmp_root}" ]] \
    || fail scratch directory-invalid

# Use the same lock as backup.sh. A shared lock allows concurrent drills but
# prevents a backup publish/retention pass from racing selection or validation.
exec 9>"${backup_dir}/.backup.lock"
flock -s -w 30 9 || fail backup lock-timeout

latest_backup=''
latest_mtime=0
while IFS= read -r -d '' candidate; do
    candidate_mtime="$(stat -c '%Y' -- "${candidate}")" \
        || fail backup metadata-unavailable
    if (( candidate_mtime > latest_mtime )); then
        latest_backup="${candidate}"
        latest_mtime="${candidate_mtime}"
    fi
done < <(find "${backup_dir}" -maxdepth 1 -type f -name 'database-*.sqlite.gz' -print0)

[[ -n "${latest_backup}" ]] || fail backup missing
[[ "$(stat -c '%a' -- "${latest_backup}")" == 600 ]] \
    || fail backup permissions-invalid

now="$(date +%s)"
backup_age=$(( now - latest_mtime ))
(( backup_age >= 0 && backup_age <= max_backup_age )) \
    || fail backup stale
gzip -t -- "${latest_backup}" || fail backup gzip-invalid

work_dir="$(mktemp -d "${tmp_root%/}/guestbook-restore.XXXXXX")" \
    || fail scratch create-failed
restored_db="${work_dir}/database.sqlite"
status_tmp=''
cleanup() {
    rm -f -- "${status_tmp}"
    if [[ -n "${work_dir}" && "${work_dir}" == "${tmp_root%/}/guestbook-restore."* ]]; then
        rm -rf -- "${work_dir}"
    fi
}
trap cleanup EXIT

gzip -dc -- "${latest_backup}" >"${restored_db}" \
    || fail restore decompression-failed
chmod 0600 "${restored_db}"

integrity="$(sqlite3 -batch -readonly "${restored_db}" 'PRAGMA integrity_check;')" \
    || fail restore integrity-query-failed
[[ "${integrity}" == ok ]] || fail restore integrity-failed

foreign_key_issues="$(sqlite3 -batch -readonly "${restored_db}" \
    'PRAGMA foreign_keys=ON; PRAGMA foreign_key_check;')" \
    || fail restore foreign-key-query-failed
[[ -z "${foreign_key_issues}" ]] || fail restore foreign-key-failed

migration_count="$(sqlite3 -batch -readonly "${restored_db}" \
    'SELECT COUNT(*) FROM schema_migrations;')" \
    || fail restore migration-query-failed
[[ "${migration_count}" =~ ^[0-9]+$ ]] \
    || fail restore migration-count-invalid
(( migration_count >= expected_migrations )) \
    || fail restore migrations-missing

# Compile representative production reads against every security-sensitive table.
# LIMIT 0 proves names/types are present without putting visitor data in stdout.
sqlite3 -batch -readonly "${restored_db}" >/dev/null <<'SQL' \
    || fail restore schema-contract-invalid
SELECT version, applied_at FROM schema_migrations LIMIT 0;
SELECT id, author, content, ip_hash, created_at, status, deleted_at,
       moderation_reason, moderated_at FROM messages LIMIT 0;
SELECT scope, subject_hash, window_started_at, attempts, penalty_level,
       blocked_until, updated_at FROM rate_limits LIMIT 0;
SELECT id, event_type, outcome, subject_hash, related_id, created_at
  FROM security_events LIMIT 0;
SELECT id, event_type, outcome, actor_hash, related_id, previous_state,
       new_state, reason_code, created_at FROM admin_audit_events LIMIT 0;
SELECT id, message_id, category, status, created_at, reviewed_at
  FROM abuse_reports LIMIT 0;
SELECT rowid, author, content FROM messages_fts LIMIT 0;
SQL

fts_objects="$(sqlite3 -batch -readonly "${restored_db}" \
    "SELECT COUNT(*) FROM sqlite_master
      WHERE (type = 'table' AND name = 'messages_fts')
         OR (type = 'trigger' AND name IN (
             'messages_fts_after_insert',
             'messages_fts_after_delete',
             'messages_fts_after_update'
         ));")" || fail restore search-schema-query-failed
[[ "${fts_objects}" == 4 ]] || fail restore search-schema-missing

# FTS5's rank=1 integrity command cross-checks the external content table against
# the index. The restored database is disposable; the live database remains hidden.
sqlite3 -batch "${restored_db}" \
    "INSERT INTO messages_fts(messages_fts, rank) VALUES('integrity-check', 1);" \
    >/dev/null || fail restore search-index-invalid

status_tmp="$(mktemp "${backup_dir}/.restore-drill.partial.XXXXXX")" \
    || fail status create-failed
printf 'success_epoch=%s backup_mtime=%s migrations=%s\n' \
    "${now}" "${latest_mtime}" "${migration_count}" >"${status_tmp}"
chmod 0600 "${status_tmp}"
mv -f -- "${status_tmp}" "${status_file}"
status_tmp=''

printf 'guestbook-restore-drill: ok backup_age_seconds=%s migrations=%s\n' \
    "${backup_age}" "${migration_count}"
