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
restore_status_file="${GUESTBOOK_RESTORE_STATUS_FILE:-${backup_dir}/restore-drill.last-success}"
offsite_status_file="${GUESTBOOK_OFFSITE_STATUS_FILE:-/var/lib/php-guestbook-offsite/last-success}"
runtime_user="${GUESTBOOK_RUNTIME_USER:-php-guestbook}"
runtime_group="${GUESTBOOK_RUNTIME_GROUP:-php-guestbook}"
config_owner="${GUESTBOOK_CONFIG_OWNER:-root}"
config_group="${GUESTBOOK_CONFIG_GROUP:-php-guestbook}"
max_backup_age="${GUESTBOOK_MAX_BACKUP_AGE_SECONDS:-108000}"
max_restore_drill_age="${GUESTBOOK_MAX_RESTORE_DRILL_AGE_SECONDS:-691200}"
max_offsite_age="${GUESTBOOK_MAX_OFFSITE_AGE_SECONDS:-108000}"
max_disk_used="${GUESTBOOK_MAX_DISK_USED_PERCENT:-90}"
expected_migrations="${GUESTBOOK_EXPECTED_MIGRATIONS:-7}"
login_window="${GUESTBOOK_LOGIN_FAILURE_WINDOW_SECONDS:-900}"
login_threshold="${GUESTBOOK_LOGIN_FAILURE_THRESHOLD:-5}"
open_report_threshold="${GUESTBOOK_OPEN_REPORT_THRESHOLD:-10}"
pending_message_threshold="${GUESTBOOK_PENDING_MESSAGE_THRESHOLD:-10}"
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
require_uint max-restore-drill-age "${max_restore_drill_age}" 3600 2678400
require_uint max-offsite-age "${max_offsite_age}" 3600 604800
require_uint max-disk-used "${max_disk_used}" 1 99
require_uint expected-migrations "${expected_migrations}" 1 1000
require_uint login-window "${login_window}" 60 86400
require_uint login-threshold "${login_threshold}" 1 1000
require_uint open-report-threshold "${open_report_threshold}" 1 100000
require_uint pending-message-threshold "${pending_message_threshold}" 1 100000
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
require_absolute_path restore-status-file "${restore_status_file}"
require_absolute_path offsite-status-file "${offsite_status_file}"
[[ "$(dirname -- "${restore_status_file}")" == "${backup_dir}" ]] \
    || fail configuration restore-status-outside-backup-directory

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
[[ "${html}" == *'property="og:image"'*'/assets/social-card.png?v='* ]] \
    || fail seo social-image-metadata-missing
[[ "${html}" == *'property="og:image:width" content="1200"'* ]] \
    || fail seo social-image-width-missing
[[ "${html}" == *'property="og:image:height" content="630"'* ]] \
    || fail seo social-image-height-missing
[[ "${html}" == *'name="twitter:card" content="summary_large_image"'* ]] \
    || fail seo social-card-type-invalid
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
if ! curl "${curl_args[@]}" "${base_url}/assets/social-card.png" | php -r '
    $value = stream_get_contents(STDIN);
    $image = getimagesizefromstring($value);
    $valid = is_array($image)
        && ($image[0] ?? null) === 1200
        && ($image[1] ?? null) === 630
        && ($image["mime"] ?? null) === "image/png"
        && hash("sha256", $value) === "ec387ed5b4788647dd9ca9bdfef8f2f38cfe36c4393735f94eaa13fcb23de3e9";
    exit($valid ? 0 : 1);
'; then
    fail seo social-image-invalid
fi

if [[ -n "${analytics_id}" ]]; then
    [[ "${analytics_id}" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] \
        || fail configuration analytics-id-invalid
    [[ "${html}" == *"data-website-id=\"${analytics_id}\""* ]] \
        || fail analytics website-id-missing
    [[ "${html}" == *'src="/assets/analytics.js?v='* ]] \
        || fail analytics local-tracker-missing
    [[ "${html}" == *'data-host-url="https://analytics.micutu.com"'* ]] \
        || fail analytics host-url-missing
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
    tracker_digest="$(curl "${curl_args[@]}" "${base_url}/assets/analytics.js" \
        | sha256sum | cut -d' ' -f1)" || fail analytics local-tracker-unavailable
    [[ "${tracker_digest}" == \
        be444c289ac019af8486b50fe2bbf2fdb2890812fb945dc78940b6781a68ac52 ]] \
        || fail analytics local-tracker-digest-invalid
    unset tracker_digest
fi
unset html

api_url="${base_url}/api/v1/messages?limit=1"
api_document="$(curl "${curl_args[@]}" "${api_url}")" || fail api request-failed
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
if ! php -r '
    $value = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $pagination = $value["pagination"] ?? null;
    $valid = is_array($value)
        && ($value["api_version"] ?? null) === "1.0"
        && isset($value["data"])
        && is_array($value["data"])
        && count($value["data"]) <= 1
        && is_array($pagination)
        && ($pagination["limit"] ?? null) === 1
        && isset($pagination["has_more"])
        && is_bool($pagination["has_more"])
        && array_key_exists("next_cursor", $pagination)
        && ($pagination["next_cursor"] === null || is_string($pagination["next_cursor"]));
    foreach (($value["data"] ?? []) as $message) {
        $valid = $valid
            && is_array($message)
            && array_key_exists("featured", $message)
            && is_bool($message["featured"]);
    }
    exit($valid ? 0 : 1);
' <<<"${api_document}"; then
    fail api schema-invalid
fi

api_headers="$(curl "${curl_args[@]}" --head "${api_url}")" \
    || fail api headers-unavailable
api_headers_lower="${api_headers,,}"
[[ "${api_headers_lower}" == *'cache-control: public, max-age=30, stale-while-revalidate=60'* ]] \
    || fail api cache-policy-invalid
[[ "${api_headers_lower}" == *'etag: "'* ]] || fail api etag-missing
[[ "${api_headers_lower}" != *$'\nset-cookie:'* ]] || fail api session-cookie-present
unset api_headers api_headers_lower

# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
if ! curl "${curl_args[@]}" "${base_url}/feed.xml" | php -r '
    $value = stream_get_contents(STDIN);
    exit(str_starts_with(ltrim($value), "<?xml")
        && str_contains($value, "<rss version=\"2.0\">") ? 0 : 1);
'; then
    fail rss schema-invalid
fi

# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
if ! curl "${curl_args[@]}" "${base_url}/feed.json" | php -r '
    $value = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $valid = is_array($value)
        && ($value["version"] ?? null) === "https://jsonfeed.org/version/1.1"
        && ($value["feed_url"] ?? null) === $argv[1]
        && isset($value["items"])
        && is_array($value["items"])
        && count($value["items"]) <= 20;
    foreach (($value["items"] ?? []) as $item) {
        $valid = $valid
            && is_array($item)
            && is_string($item["id"] ?? null)
            && is_string($item["url"] ?? null)
            && is_string($item["content_text"] ?? null)
            && is_string($item["date_published"] ?? null)
            && is_array($item["authors"] ?? null);
    }
    exit($valid ? 0 : 1);
' "${base_url}/feed.json"; then
    fail json-feed schema-invalid
fi

# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
if ! curl "${curl_args[@]}" "${base_url}/sitemap.xml" | php -r '
    $value = simplexml_load_string(stream_get_contents(STDIN));
    exit($value !== false && $value->getName() === "urlset" ? 0 : 1);
'; then
    fail seo sitemap-invalid
fi

robots="$(curl "${curl_args[@]}" "${base_url}/robots.txt")" \
    || fail seo robots-unavailable
[[ "${robots}" == *'Sitemap: https://php.micutu.com/sitemap.xml'* ]] \
    || fail seo sitemap-discovery-missing
unset robots

# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
first_message_id="$(php -r '
    $value = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $id = $value["data"][0]["id"] ?? null;
    if ($id !== null && (!is_int($id) || $id < 1)) { exit(1); }
    if ($id !== null) { echo $id; }
' <<<"${api_document}")" || fail api message-id-invalid
if [[ -n "${first_message_id}" ]]; then
    message_html="$(curl "${curl_args[@]}" "${base_url}/messages/${first_message_id}")" \
        || fail seo permalink-unavailable
    [[ "${message_html}" == *'itemtype="https://schema.org/DiscussionForumPosting"'* ]] \
        || fail seo posting-schema-missing
    [[ "${message_html}" == *"/messages/${first_message_id}\""* ]] \
        || fail seo canonical-permalink-missing
    unset message_html
fi
unset api_document first_message_id

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

pending_messages="$(sqlite3 -batch "${db_path}" \
    "SELECT COUNT(*) FROM messages WHERE status = 'pending';")" \
    || fail moderation pending-query-failed
[[ "${pending_messages}" =~ ^[0-9]+$ ]] || fail moderation pending-count-invalid
(( pending_messages < pending_message_threshold )) || fail moderation pending-message-threshold

featured_invariant="$(sqlite3 -batch "${db_path}" \
    "SELECT CASE
         WHEN (SELECT COUNT(*) FROM messages WHERE featured_at IS NOT NULL) <= 1
          AND (SELECT COUNT(*) FROM messages
               WHERE featured_at IS NOT NULL AND status != 'published') = 0
         THEN 1 ELSE 0 END;")" \
    || fail moderation featured-query-failed
[[ "${featured_invariant}" == 1 ]] || fail moderation featured-invariant-invalid

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

[[ -f "${restore_status_file}" && ! -L "${restore_status_file}" ]] \
    || fail restore-drill status-missing
expect_metadata restore-drill-status "${restore_status_file}" \
    "${runtime_user}:${runtime_group}:600"
restore_status="$(<"${restore_status_file}")" \
    || fail restore-drill status-unreadable
[[ "${restore_status}" =~ ^success_epoch=([0-9]+)[[:space:]]backup_mtime=([0-9]+)[[:space:]]migrations=([0-9]+)$ ]] \
    || fail restore-drill status-invalid
restore_success_epoch="${BASH_REMATCH[1]}"
restore_backup_mtime="${BASH_REMATCH[2]}"
restore_migrations="${BASH_REMATCH[3]}"
(( restore_success_epoch <= now && now - restore_success_epoch <= max_restore_drill_age )) \
    || fail restore-drill stale
(( restore_backup_mtime <= restore_success_epoch )) \
    || fail restore-drill backup-time-invalid
(( restore_migrations >= expected_migrations )) \
    || fail restore-drill migrations-missing

[[ -f "${offsite_status_file}" && ! -L "${offsite_status_file}" ]] \
    || fail offsite status-missing
expect_metadata offsite-status "${offsite_status_file}" \
    "${runtime_user}:${runtime_group}:600"
offsite_status="$(<"${offsite_status_file}")" \
    || fail offsite status-unreadable
[[ "${offsite_status}" =~ ^success_epoch=([0-9]+)[[:space:]]source_mtime=([0-9]+)[[:space:]]artifacts=([0-9]+)[[:space:]]verified=([0-9]+)[[:space:]]configured=([0-9]+)[[:space:]]failed=([0-9]+)$ ]] \
    || fail offsite status-invalid
offsite_success_epoch="${BASH_REMATCH[1]}"
offsite_source_mtime="${BASH_REMATCH[2]}"
offsite_artifacts="${BASH_REMATCH[3]}"
offsite_verified="${BASH_REMATCH[4]}"
offsite_configured="${BASH_REMATCH[5]}"
offsite_failed="${BASH_REMATCH[6]}"
(( offsite_success_epoch <= now && now - offsite_success_epoch <= max_offsite_age )) \
    || fail offsite stale
(( offsite_source_mtime <= offsite_success_epoch )) \
    || fail offsite source-time-invalid
(( offsite_artifacts >= 1 )) || fail offsite artifacts-missing
(( offsite_configured >= 2 )) || fail offsite destination-redundancy-missing
(( offsite_verified + offsite_failed == offsite_configured )) \
    || fail offsite destination-count-invalid
(( offsite_verified >= 1 )) || fail offsite no-destination-verified
(( offsite_failed == 0 )) || fail offsite redundancy-degraded

disk_used="$(df -P -- "${storage_dir}" | awk 'NR == 2 {gsub(/%/, "", $5); print $5}')"
[[ "${disk_used}" =~ ^[0-9]+$ ]] || fail disk usage-invalid
(( disk_used < max_disk_used )) || fail disk threshold-exceeded

echo 'guestbook-health: ok web api rss seo analytics database security moderation backup restore-drill offsite permissions disk'
