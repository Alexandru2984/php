#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"
app_dir="${tmp_dir}/app"
port="${BROWSER_TEST_PORT:-18766}"
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
trap cleanup EXIT INT TERM

export APP_ENV=testing
export APP_SECRET=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export APP_ORIGIN="http://127.0.0.1:${port}"
export APP_STORAGE_DIR="${tmp_dir}/storage"
export ANALYTICS_WEBSITE_ID=""

mkdir -p "${app_dir}/tests" "${APP_STORAGE_DIR}" "${tmp_dir}/sessions"
for path in index.php config.php lib.php api.php openapi.json assets migrations scripts .well-known; do
    cp -a -- "${repo_dir}/${path}" "${app_dir}/"
done
cp -a -- "${repo_dir}/tests/router.php" "${app_dir}/tests/router.php"
chmod -R a-w -- "${app_dir}"

php "${app_dir}/scripts/migrate.php" >&2

php -d "session.save_path=${tmp_dir}/sessions" \
    -S "127.0.0.1:${port}" -t "${app_dir}" "${app_dir}/tests/router.php" &
server_pid=$!
wait "${server_pid}"
