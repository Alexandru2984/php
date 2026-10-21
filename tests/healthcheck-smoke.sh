#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"
port="${HEALTH_TEST_PORT:-18766}"
server_pid=''

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

runtime_user="$(id -un)"
runtime_group="$(id -gn)"
analytics_id='c4e3a772-26b3-47e7-afec-b82097684551'
site_root="${tmp_dir}/site"
storage_dir="${site_root}/storage"
session_dir="${site_root}/sessions"
backup_dir="${tmp_dir}/backups"
releases_dir="${site_root}/releases"
release_dir="${releases_dir}/test-release"
config_file="${site_root}/shared.env"

mkdir -p "${storage_dir}" "${session_dir}" "${backup_dir}" "${release_dir}"
chmod 0700 "${storage_dir}" "${session_dir}" "${backup_dir}"
printf '<?php // immutable health fixture\n' >"${release_dir}/index.php"
chmod 0444 "${release_dir}/index.php"
ln -s releases/test-release "${site_root}/current"
printf 'ANALYTICS_WEBSITE_ID=%s\n' "${analytics_id}" >"${config_file}"
chmod 0640 "${config_file}"

export APP_ENV=testing
export APP_SECRET=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
ADMIN_PASSWORD_HASH="$(php -r 'echo password_hash("health-test-password", PASSWORD_BCRYPT, ["cost" => 4]);')"
export ADMIN_PASSWORD_HASH
export APP_ORIGIN="http://127.0.0.1:${port}"
export APP_STORAGE_DIR="${storage_dir}"
export ANALYTICS_WEBSITE_ID="${analytics_id}"

php "${repo_dir}/scripts/migrate.php" >/dev/null
chmod 0600 "${storage_dir}/database.sqlite"
snapshot="${tmp_dir}/database.sqlite"
sqlite3 "${storage_dir}/database.sqlite" ".backup '${snapshot}'"
gzip -9 "${snapshot}"
mv "${snapshot}.gz" "${backup_dir}/database-20260101T000000Z.sqlite.gz"
chmod 0600 "${backup_dir}/database-20260101T000000Z.sqlite.gz"

php -S "127.0.0.1:${port}" -t "${repo_dir}" >"${tmp_dir}/server.log" 2>&1 &
server_pid=$!
for _ in {1..40}; do
    if curl --silent --fail --output /dev/null "${APP_ORIGIN}/"; then
        break
    fi
    sleep 0.1
done

health_env=(
    "GUESTBOOK_HEALTH_URL=${APP_ORIGIN}"
    "GUESTBOOK_ANALYTICS_URL=${APP_ORIGIN}/assets/app.js"
    "GUESTBOOK_ANALYTICS_WEBSITE_ID=${analytics_id}"
    "GUESTBOOK_SITE_ROOT=${site_root}"
    "GUESTBOOK_RELEASE_LINK=${site_root}/current"
    "GUESTBOOK_RELEASES_DIR=${releases_dir}"
    "GUESTBOOK_STORAGE_DIR=${storage_dir}"
    "GUESTBOOK_DB_PATH=${storage_dir}/database.sqlite"
    "GUESTBOOK_SESSION_DIR=${session_dir}"
    "GUESTBOOK_CONFIG_FILE=${config_file}"
    "GUESTBOOK_BACKUP_DIR=${backup_dir}"
    "GUESTBOOK_RUNTIME_USER=${runtime_user}"
    "GUESTBOOK_RUNTIME_GROUP=${runtime_group}"
    "GUESTBOOK_CONFIG_OWNER=${runtime_user}"
    "GUESTBOOK_CONFIG_GROUP=${runtime_group}"
    'GUESTBOOK_MAX_BACKUP_AGE_SECONDS=108000'
    'GUESTBOOK_MAX_DISK_USED_PERCENT=99'
    'GUESTBOOK_EXPECTED_MIGRATIONS=3'
    'GUESTBOOK_HEALTH_ALLOW_HTTP=1'
)

healthy_output="$(env "${health_env[@]}" bash "${repo_dir}/scripts/healthcheck.sh")"
[[ "${healthy_output}" == guestbook-health:\ ok* ]]

sqlite3 "${storage_dir}/database.sqlite" \
    "INSERT INTO security_events (event_type, outcome, created_at)
     WITH RECURSIVE n(x) AS (VALUES(1) UNION ALL SELECT x + 1 FROM n WHERE x < 5)
     SELECT 'admin.login', 'failure', CAST(strftime('%s', 'now') AS INTEGER) FROM n;"
set +e
security_output="$(env "${health_env[@]}" bash "${repo_dir}/scripts/healthcheck.sh" 2>&1)"
security_exit=$?
set -e
[[ "${security_exit}" -ne 0 ]]
[[ "${security_output}" == *'component=security reason=admin-login-threshold'* ]]
sqlite3 "${storage_dir}/database.sqlite" "DELETE FROM security_events;"

touch -d '3 days ago' "${backup_dir}/database-20260101T000000Z.sqlite.gz"
set +e
stale_output="$(env "${health_env[@]}" bash "${repo_dir}/scripts/healthcheck.sh" 2>&1)"
stale_exit=$?
set -e
[[ "${stale_exit}" -ne 0 ]]
[[ "${stale_output}" == *'component=backup reason=stale'* ]]
touch "${backup_dir}/database-20260101T000000Z.sqlite.gz"

chmod 0644 "${release_dir}/index.php"
set +e
permission_output="$(env "${health_env[@]}" bash "${repo_dir}/scripts/healthcheck.sh" 2>&1)"
permission_exit=$?
set -e
[[ "${permission_exit}" -ne 0 ]]
[[ "${permission_output}" == *'component=release reason=writable-by-runtime'* ]]

echo 'Healthcheck smoke tests passed.'
