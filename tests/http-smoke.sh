#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"
port="${TEST_PORT:-18765}"
server_pid=""

cleanup() {
    if [[ -n "${server_pid}" ]]; then
        kill "${server_pid}" 2>/dev/null || true
        wait "${server_pid}" 2>/dev/null || true
    fi
    if [[ -n "${tmp_dir}" && "${tmp_dir}" == /tmp/* ]]; then
        rm -rf -- "${tmp_dir}"
    fi
}
trap cleanup EXIT

set +e
APP_ENV=production APP_SECRET=invalid php -r \
    "define('GUESTBOOK_APP', true); require '${repo_dir}/config.php';" \
    >"${tmp_dir}/invalid-config.log" 2>&1
config_exit=$?
set -e
[[ "${config_exit}" == 78 ]]

set +e
APP_ENV=testing \
APP_SECRET=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
ANALYTICS_WEBSITE_ID=not-a-uuid \
php -r "define('GUESTBOOK_APP', true); require '${repo_dir}/config.php';" \
    >"${tmp_dir}/invalid-analytics.log" 2>&1
analytics_config_exit=$?
set -e
[[ "${analytics_config_exit}" == 78 ]]

export APP_ENV=testing
export APP_SECRET=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
ADMIN_PASSWORD_HASH="$(php -r 'echo password_hash("smoke-test-password", PASSWORD_BCRYPT, ["cost" => 4]);')"
export ADMIN_PASSWORD_HASH
export APP_ORIGIN="http://127.0.0.1:${port}"
export APP_STORAGE_DIR="${tmp_dir}/storage"
export ANALYTICS_WEBSITE_ID=c4e3a772-26b3-47e7-afec-b82097684551
mkdir -p "${APP_STORAGE_DIR}"
cli_session_dir="${tmp_dir}/cli-sessions"
mkdir -p "${cli_session_dir}"
web_session_dir="${tmp_dir}/web-sessions"
mkdir -p "${web_session_dir}"

php -d "session.save_path=${cli_session_dir}" \
    "${repo_dir}/scripts/migrate.php" >"${tmp_dir}/migration.log"
if find "${cli_session_dir}" -type f -print -quit | grep --quiet .; then
    echo 'CLI migration unexpectedly created a PHP session.' >&2
    exit 1
fi
php -d "session.save_path=${web_session_dir}" \
    -S "127.0.0.1:${port}" -t "${repo_dir}" "${repo_dir}/tests/router.php" \
    >"${tmp_dir}/server.log" 2>&1 &
server_pid=$!

for _ in {1..40}; do
    if curl --silent --fail --output /dev/null "${APP_ORIGIN}/api/v1/messages"; then
        break
    fi
    sleep 0.1
done

api_headers="${tmp_dir}/api-headers.txt"
api_body="${tmp_dir}/api-body.json"
api_status="$(curl --silent --show-error \
    --dump-header "${api_headers}" \
    --output "${api_body}" \
    --write-out '%{http_code}' \
    --header 'Origin: https://another.example' \
    "${APP_ORIGIN}/api/v1/messages")"
[[ "${api_status}" == 200 ]]
grep --ignore-case --quiet '^Content-Type: application/json; charset=utf-8' "${api_headers}"
grep --ignore-case --quiet '^Cache-Control: public, max-age=30, stale-while-revalidate=60' "${api_headers}"
grep --ignore-case --quiet '^Vary: Accept-Encoding' "${api_headers}"
if grep --ignore-case --quiet '^Set-Cookie:' "${api_headers}"; then
    echo 'Stateless API unexpectedly emitted a cookie.' >&2
    exit 1
fi
if grep --ignore-case --quiet '^Access-Control-Allow-Origin:' "${api_headers}"; then
    echo 'Same-origin API unexpectedly emitted a CORS grant.' >&2
    exit 1
fi
api_etag="$(sed -n 's/^ETag: \(.*\)\r$/\1/ip' "${api_headers}")"
[[ "${api_etag}" =~ ^\"[0-9a-f]{64}\"$ ]]
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$v=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);$ok=($v["api_version"]??null)==="1.0"&&($v["data"]??null)===[]&&($v["pagination"]["has_more"]??null)===false;exit($ok?0:1);' <"${api_body}"

conditional_headers="${tmp_dir}/api-conditional-headers.txt"
conditional_body="${tmp_dir}/api-conditional-body.txt"
conditional_status="$(curl --silent --show-error \
    --dump-header "${conditional_headers}" \
    --output "${conditional_body}" \
    --write-out '%{http_code}' \
    --header "If-None-Match: ${api_etag}" \
    "${APP_ORIGIN}/api/v1/messages")"
[[ "${conditional_status}" == 304 ]]
[[ ! -s "${conditional_body}" ]]
if grep --ignore-case --quiet '^Set-Cookie:' "${conditional_headers}"; then
    echo 'Conditional API request unexpectedly emitted a cookie.' >&2
    exit 1
fi

[[ "$(curl --silent --show-error --head --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/api/v1/messages")" == 200 ]]
[[ "$(curl --silent --show-error --output "${tmp_dir}/api-error.json" --write-out '%{http_code}' "${APP_ORIGIN}/api/v1/messages?limit=0")" == 400 ]]
grep --quiet '"code":"invalid_limit"' "${tmp_dir}/api-error.json"
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/api/v1/messages?cursor=invalid")" == 400 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/api/v1/messages?unknown=1")" == 400 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --data 'action=post' "${APP_ORIGIN}/api/v1/messages")" == 405 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/not-a-route")" == 404 ]]
if find "${web_session_dir}" -type f -print -quit | grep --quiet .; then
    echo 'Sessionless API unexpectedly created a PHP session.' >&2
    exit 1
fi
openapi_body="$(curl --silent --show-error --fail "${APP_ORIGIN}/openapi.json")"
grep --quiet '"/api/v1/messages"' <<<"${openapi_body}"

cookie_jar="${tmp_dir}/cookies.txt"
html="$(curl --silent --show-error --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/")"
if ! find "${web_session_dir}" -type f -print -quit | grep --quiet .; then
    echo 'Interactive HTML request did not create its expected PHP session.' >&2
    exit 1
fi
csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${html}" | head -n 1)"
[[ "${csrf}" =~ ^[0-9a-f]{64}$ ]]
grep --quiet 'data-website-id="c4e3a772-26b3-47e7-afec-b82097684551"' <<<"${html}"
grep --quiet 'data-domains="php.micutu.com"' <<<"${html}"
grep --quiet 'data-do-not-track="true"' <<<"${html}"
grep --quiet 'data-exclude-search="true"' <<<"${html}"
grep --quiet 'data-exclude-hash="true"' <<<"${html}"
grep --quiet 'data-performance="true"' <<<"${html}"

request_code() {
    curl --silent --show-error --output /dev/null --write-out '%{http_code}' "$@"
}

[[ "$(request_code -X PUT "${APP_ORIGIN}/")" == 405 ]]
[[ "$(request_code "${APP_ORIGIN}/index.php/extra")" == 404 ]]
[[ "$(request_code "${APP_ORIGIN}/?q%5B%5D=nested")" == 400 ]]
[[ "$(request_code "${APP_ORIGIN}/?page%5B%5D=2")" == 400 ]]

cross_origin_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header 'Origin: https://attacker.example' \
    --data-urlencode "csrf=${csrf}" \
    --data-urlencode 'action=post' \
    --data-urlencode 'author=Alice' \
    --data-urlencode 'message=blocked' \
    "${APP_ORIGIN}/")"
[[ "${cross_origin_code}" == 303 ]]

invalid_post_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${csrf}" \
    --data-urlencode 'action=post' \
    --data-urlencode 'website=' \
    --data-urlencode 'author=Remember me' \
    --data-urlencode 'message=   ' \
    "${APP_ORIGIN}/")"
[[ "${invalid_post_code}" == 303 ]]
validation_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
grep --quiet 'value="Remember me"' <<<"${validation_html}"

post_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${csrf}" \
    --data-urlencode 'action=post' \
    --data-urlencode 'website=' \
    --data-urlencode 'author=Alice' \
    --data-urlencode 'message=Hello from the isolated smoke test' \
    "${APP_ORIGIN}/")"
[[ "${post_code}" == 303 ]]

[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" 'SELECT COUNT(*) FROM messages;')" == 1 ]]
message_id="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT id FROM messages WHERE status = 'published' LIMIT 1;")"
permalink_html="$(curl --silent --show-error --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/?message=${message_id}")"
grep --quiet 'Hello from the isolated smoke test' <<<"${permalink_html}"
grep --quiet 'No free-text report or reporter identifier is stored.' <<<"${permalink_html}"
[[ "$(request_code "${APP_ORIGIN}/?message%5B%5D=${message_id}")" == 400 ]]

search_html="$(curl --silent --show-error --get \
    --data-urlencode 'q=Alice isolated smoke' "${APP_ORIGIN}/")"
grep --quiet 'Hello from the isolated smoke test' <<<"${search_html}"
special_search_code="$(request_code --get \
    --data-urlencode 'q=" OR author:* NEAR(' "${APP_ORIGIN}/")"
[[ "${special_search_code}" == 200 ]]

populated_api_headers="${tmp_dir}/api-populated-headers.txt"
populated_api_body="$(curl --silent --show-error --dump-header "${populated_api_headers}" "${APP_ORIGIN}/api/v1/messages?limit=1")"
grep --quiet 'Hello from the isolated smoke test' <<<"${populated_api_body}"
populated_etag="$(sed -n 's/^ETag: \(.*\)\r$/\1/ip' "${populated_api_headers}")"
[[ "${populated_etag}" =~ ^\"[0-9a-f]{64}\"$ ]]
[[ "${populated_etag}" != "${api_etag}" ]]
if grep --ignore-case --quiet '^Set-Cookie:' "${populated_api_headers}"; then
    echo 'Populated API request unexpectedly emitted a cookie.' >&2
    exit 1
fi

report_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${permalink_html}" | head -n 1)"
[[ "${report_csrf}" =~ ^[0-9a-f]{64}$ ]]
invalid_report_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${report_csrf}" \
    --data-urlencode 'action=report' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'category=unknown' \
    "${APP_ORIGIN}/")"
[[ "${invalid_report_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" 'SELECT COUNT(*) FROM abuse_reports;')" == 0 ]]

report_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${report_csrf}" \
    --data-urlencode 'action=report' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'category=privacy' \
    "${APP_ORIGIN}/")"
[[ "${report_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT message_id || ':' || category || ':' || status FROM abuse_reports;")" == "${message_id}:privacy:open" ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT attempts FROM rate_limits WHERE scope = 'abuse-report';")" == 1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT group_concat(name, ',') FROM pragma_table_info('abuse_reports');")" == 'id,message_id,category,status,created_at,reviewed_at' ]]

rss="$(curl --silent --show-error "${APP_ORIGIN}/?format=rss")"
grep --quiet '<rss version="2.0">' <<<"${rss}"
grep --quiet 'Hello from the isolated smoke test' <<<"${rss}"
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$xml = simplexml_load_string(stream_get_contents(STDIN)); exit($xml === false ? 1 : 0);' <<<"${rss}"

legacy_headers="$(curl --silent --show-error --head "${APP_ORIGIN}/?format=json")"
grep --ignore-case --quiet '^Deprecation: true' <<<"${legacy_headers}"
grep --ignore-case --quiet '^Link: </api/v1/messages>; rel="successor-version"' <<<"${legacy_headers}"

login_html="$(curl --silent --show-error --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/")"
login_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${login_html}" | head -n 1)"
[[ "${login_csrf}" =~ ^[0-9a-f]{64}$ ]]

wrong_login_code="$(request_code \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${login_csrf}" \
    --data-urlencode 'action=login' \
    --data-urlencode 'password=wrong' \
    "${APP_ORIGIN}/")"
[[ "${wrong_login_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT attempts FROM rate_limits WHERE scope = 'admin-login';")" == 1 ]]

login_html="$(curl --silent --show-error --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/")"
login_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${login_html}" | head -n 1)"
good_login_code="$(request_code \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${login_csrf}" \
    --data-urlencode 'action=login' \
    --data-urlencode 'password=smoke-test-password' \
    "${APP_ORIGIN}/")"
[[ "${good_login_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT COUNT(*) FROM rate_limits WHERE scope = 'admin-login';")" == 0 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT COUNT(*) FROM security_events WHERE event_type = 'admin.login';")" == 2 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT COUNT(*) FROM admin_audit_events WHERE event_type = 'admin.login';")" == 1 ]]

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
grep --quiet 'Sign out' <<<"${admin_html}"
grep --quiet 'Abuse reports · 1 open' <<<"${admin_html}"
grep --quiet 'Append-only admin audit' <<<"${admin_html}"

admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
hide_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=moderate' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'status=hidden' \
    --data-urlencode 'reason=privacy' \
    "${APP_ORIGIN}/")"
[[ "${hide_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status || ':' || moderation_reason || ':' || (ip_hash IS NULL) FROM messages WHERE id = ${message_id};")" == hidden:privacy:1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT previous_state || ':' || new_state || ':' || reason_code FROM admin_audit_events WHERE event_type = 'message.hide';")" == published:hidden:privacy ]]
hidden_search_html="$(curl --silent --show-error --get \
    --data-urlencode 'q=isolated smoke' "${APP_ORIGIN}/")"
if grep --quiet 'Hello from the isolated smoke test' <<<"${hidden_search_html}"; then
    echo 'FTS search exposed a moderated message.' >&2
    exit 1
fi

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
grep --quiet 'Moderated messages · 1 recent' <<<"${admin_html}"
report_id="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT id FROM abuse_reports WHERE status = 'open';")"
review_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=review_report' \
    --data-urlencode "id=${report_id}" \
    --data-urlencode 'decision=resolved' \
    "${APP_ORIGIN}/")"
[[ "${review_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status FROM abuse_reports WHERE id = ${report_id};")" == resolved ]]

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
restore_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=moderate' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'status=published' \
    --data-urlencode 'reason=restored' \
    "${APP_ORIGIN}/")"
[[ "${restore_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status || ':' || moderation_reason FROM messages WHERE id = ${message_id};")" == published:restored ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT COUNT(*) FROM admin_audit_events;")" == 4 ]]

echo 'HTTP smoke tests passed.'
