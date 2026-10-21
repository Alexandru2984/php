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

export APP_ENV=testing
export APP_SECRET=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
ADMIN_PASSWORD_HASH="$(php -r 'echo password_hash("smoke-test-password", PASSWORD_BCRYPT, ["cost" => 4]);')"
export ADMIN_PASSWORD_HASH
export APP_ORIGIN="http://127.0.0.1:${port}"
export APP_STORAGE_DIR="${tmp_dir}/storage"
mkdir -p "${APP_STORAGE_DIR}"

php "${repo_dir}/scripts/migrate.php" >"${tmp_dir}/migration.log"
php -S "127.0.0.1:${port}" -t "${repo_dir}" >"${tmp_dir}/server.log" 2>&1 &
server_pid=$!

for _ in {1..40}; do
    if curl --silent --fail --output /dev/null "${APP_ORIGIN}/"; then
        break
    fi
    sleep 0.1
done

cookie_jar="${tmp_dir}/cookies.txt"
html="$(curl --silent --show-error --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/")"
csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${html}" | head -n 1)"
[[ "${csrf}" =~ ^[0-9a-f]{64}$ ]]

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

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
grep --quiet 'Sign out' <<<"${admin_html}"

admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
message_id="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT id FROM messages WHERE status = 'published' LIMIT 1;")"
delete_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=delete' \
    --data-urlencode "id=${message_id}" \
    "${APP_ORIGIN}/")"
[[ "${delete_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status || ':' || (ip_hash IS NULL) FROM messages WHERE id = ${message_id};")" == deleted:1 ]]

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
grep --quiet 'Recently deleted messages' <<<"${admin_html}"
restore_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=restore' \
    --data-urlencode "id=${message_id}" \
    "${APP_ORIGIN}/")"
[[ "${restore_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status FROM messages WHERE id = ${message_id};")" == published ]]

echo 'HTTP smoke tests passed.'
