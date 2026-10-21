#!/usr/bin/env bash
set -Eeuo pipefail

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
umask 077

health_url="${GUESTBOOK_HEALTH_URL:-https://php.micutu.com}"
analytics_url="${GUESTBOOK_ANALYTICS_URL:-https://analytics.micutu.com/script.js}"
analytics_id="${GUESTBOOK_ANALYTICS_WEBSITE_ID:-}"
site_root="${GUESTBOOK_SITE_ROOT:-/srv/canary/php.micutu.com}"
release_link="${GUESTBOOK_RELEASE_LINK:-${site_root}/current}"
releases_dir="${GUESTBOOK_RELEASES_DIR:-${site_root}/releases}"
storage_dir="${GUESTBOOK_STORAGE_DIR:-${site_root}/php_micutu_app_storage}"
db_path="${GUESTBOOK_DB_PATH:-${storage_dir}/database.sqlite}"
session_dir="${GUESTBOOK_SESSION_DIR:-${site_root}/sessions}"
config_file="${GUESTBOOK_CONFIG_FILE:-${site_root}/shared/.env}"
backup_dir="${GUESTBOOK_BACKUP_DIR:-/srv/backups/php-guestbook}"
runtime_user="${GUESTBOOK_RUNTIME_USER:-php-guestbook}"
runtime_group="${GUESTBOOK_RUNTIME_GROUP:-php-guestbook}"
config_owner="${GUESTBOOK_CONFIG_OWNER:-root}"
config_group="${GUESTBOOK_CONFIG_GROUP:-php-guestbook}"
max_backup_age="${GUESTBOOK_MAX_BACKUP_AGE_SECONDS:-108000}"
max_disk_used="${GUESTBOOK_MAX_DISK_USED_PERCENT:-90}"
expected_migrations="${GUESTBOOK_EXPECTED_MIGRATIONS:-4}"
login_window="${GUESTBOOK_LOGIN_FAILURE_WINDOW_SECONDS:-900}"
login_threshold="${GUESTBOOK_LOGIN_FAILURE_THRESHOLD:-5}"
open_report_threshold="${GUESTBOOK_OPEN_REPORT_THRESHOLD:-10}"
allow_http="${GUESTBOOK_HEALTH_ALLOW_HTTP:-0}"

fail() {
    printf 'guestbook-health: FAIL component=%s reason=%s\n' "$1" "$2" >&2
    exit 1
}

require_uint() {
    local name="$1" value="$2" minimum="$3" maximum="$4"
    [[ "${value}" =~ ^[0-9]+$ ]] || fail configuration "${name}-invalid"
    (( value >= minimum && value <= maximum )) || fail configuration "${name}-out-of-range"
}

require_absolute_path() {
    local name="$1" value="$2"
    [[ "${value}" == /* && "${value}" != *$'\n'* ]] || fail configuration "${name}-invalid"
}

expect_metadata() {
    local component="$1" path="$2" expected="$3" actual
    actual="$(stat -Lc '%U:%G:%a' -- "${path}" 2>/dev/null)" \
        || fail "${component}" metadata-unavailable
    [[ "${actual}" == "${expected}" ]] || fail "${component}" metadata-mismatch
}

require_uint max-backup-age "${max_backup_age}" 60 604800
require_uint max-disk-used "${max_disk_used}" 1 99
require_uint expected-migrations "${expected_migrations}" 1 1000
require_uint login-window "${login_window}" 60 86400
require_uint login-threshold "${login_threshold}" 1 1000
require_uint open-report-threshold "${open_report_threshold}" 1 100000
[[ "${allow_http}" == 0 || "${allow_http}" == 1 ]] \
    || fail configuration allow-http-invalid

for path_spec in \
    "site-root:${site_root}" \
    "release-link:${release_link}" \
    "releases-dir:${releases_dir}" \
    "storage-dir:${storage_dir}" \
    "database:${db_path}" \
    "session-dir:${session_dir}" \
    "config-file:${config_file}" \
    "backup-dir:${backup_dir}"; do
    require_absolute_path "${path_spec%%:*}" "${path_spec#*:}"
done

if [[ "${allow_http}" == 0 ]]; then
    [[ "${health_url}" == https://* && "${analytics_url}" == https://* ]] \
        || fail configuration https-required
    curl_protocols='=https'
else
    [[ "${health_url}" == http://* || "${health_url}" == https://* ]] \
        || fail configuration health-url-invalid
    [[ "${analytics_url}" == http://* || "${analytics_url}" == https://* ]] \
        || fail configuration analytics-url-invalid
    curl_protocols='=http,https'
fi

getent passwd "${runtime_user}" >/dev/null || fail configuration runtime-user-missing
getent group "${runtime_group}" >/dev/null || fail configuration runtime-group-missing
getent passwd "${config_owner}" >/dev/null || fail configuration config-owner-missing
getent group "${config_group}" >/dev/null || fail configuration config-group-missing

base_url="${health_url%/}"
curl_args=(
    --silent
    --show-error
    --fail
    --connect-timeout 5
    --max-time 15
    --proto "${curl_protocols}"
)

html="$(curl "${curl_args[@]}" "${base_url}/")" || fail web request-failed
[[ "${html}" == *'class="skip-link"'* ]] || fail web marker-missing

if [[ -n "${analytics_id}" ]]; then
    [[ "${analytics_id}" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] \
        || fail configuration analytics-id-invalid
    [[ "${html}" == *"data-website-id=\"${analytics_id}\""* ]] \
        || fail analytics website-id-missing
    for attribute in \
        'data-domains="php.micutu.com"' \
        'data-do-not-track="true"' \
        'data-exclude-search="true"' \
        'data-exclude-hash="true"' \
        'data-performance="true"'; do
        [[ "${html}" == *"${attribute}"* ]] || fail analytics privacy-attribute-missing
    done
    curl "${curl_args[@]}" --output /dev/null "${analytics_url}" \
        || fail analytics tracker-unavailable
fi
unset html

# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
if ! curl "${curl_args[@]}" "${base_url}/?format=json" | php -r '
    $value = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $valid = is_array($value)
        && isset($value["total"], $value["page"], $value["pages"], $value["messages"])
        && is_int($value["total"])
        && is_int($value["page"])
        && is_int($value["pages"])
        && is_array($value["messages"]);
    exit($valid ? 0 : 1);
'; then
    fail api schema-invalid
fi

# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
if ! curl "${curl_args[@]}" "${base_url}/?format=rss" | php -r '
    $value = stream_get_contents(STDIN);
    exit(str_starts_with(ltrim($value), "<?xml")
        && str_contains($value, "<rss version=\"2.0\">") ? 0 : 1);
'; then
    fail rss schema-invalid
fi

[[ -L "${release_link}" ]] || fail release current-not-symlink
resolved_release="$(readlink -f -- "${release_link}")" || fail release target-unavailable
resolved_releases="$(readlink -f -- "${releases_dir}")" || fail release directory-unavailable
[[ "${resolved_release}" == "${resolved_releases}/"* ]] || fail release target-outside-releases
[[ -f "${resolved_release}/index.php" ]] || fail release front-controller-missing
[[ ! -w "${resolved_release}/index.php" ]] || fail release writable-by-runtime

expect_metadata storage "${storage_dir}" "${runtime_user}:${runtime_group}:700"
expect_metadata database "${db_path}" "${runtime_user}:${runtime_group}:600"
expect_metadata sessions "${session_dir}" "${runtime_user}:${runtime_group}:700"
expect_metadata configuration "${config_file}" "${config_owner}:${config_group}:640"
expect_metadata backups "${backup_dir}" "${runtime_user}:${runtime_group}:700"

integrity="$(sqlite3 -batch "${db_path}" 'PRAGMA quick_check;')" \
    || fail database query-failed
[[ "${integrity}" == ok ]] || fail database integrity-failed

migration_count="$(sqlite3 -batch "${db_path}" 'SELECT COUNT(*) FROM schema_migrations;')" \
    || fail database migration-query-failed
[[ "${migration_count}" =~ ^[0-9]+$ ]] || fail database migration-count-invalid
(( migration_count >= expected_migrations )) || fail database migrations-missing

login_failures="$(sqlite3 -batch "${db_path}" \
    "SELECT COUNT(*) FROM security_events
     WHERE event_type = 'admin.login'
       AND outcome IN ('failure', 'blocked')
       AND created_at >= CAST(strftime('%s', 'now') AS INTEGER) - ${login_window};")" \
    || fail security login-query-failed
[[ "${login_failures}" =~ ^[0-9]+$ ]] || fail security login-count-invalid
(( login_failures < login_threshold )) || fail security admin-login-threshold

open_reports="$(sqlite3 -batch "${db_path}" \
    "SELECT COUNT(*) FROM abuse_reports WHERE status = 'open';")" \
    || fail moderation report-query-failed
[[ "${open_reports}" =~ ^[0-9]+$ ]] || fail moderation report-count-invalid
(( open_reports < open_report_threshold )) || fail moderation open-report-threshold

latest_backup=''
latest_mtime=0
while IFS= read -r -d '' candidate; do
    candidate_mtime="$(stat -c '%Y' -- "${candidate}")" || fail backup metadata-unavailable
    if (( candidate_mtime > latest_mtime )); then
        latest_mtime="${candidate_mtime}"
        latest_backup="${candidate}"
    fi
done < <(find "${backup_dir}" -maxdepth 1 -type f -name 'database-*.sqlite.gz' -print0)

[[ -n "${latest_backup}" ]] || fail backup missing
expect_metadata backup-artifact "${latest_backup}" "${runtime_user}:${runtime_group}:600"
gzip -t -- "${latest_backup}" || fail backup gzip-invalid
now="$(date +%s)"
backup_age=$(( now - latest_mtime ))
(( backup_age >= 0 && backup_age <= max_backup_age )) || fail backup stale

disk_used="$(df -P -- "${storage_dir}" | awk 'NR == 2 {gsub(/%/, "", $5); print $5}')"
[[ "${disk_used}" =~ ^[0-9]+$ ]] || fail disk usage-invalid
(( disk_used < max_disk_used )) || fail disk threshold-exceeded

echo 'guestbook-health: ok web api rss analytics database security moderation backup permissions disk'
