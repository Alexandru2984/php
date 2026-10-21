#!/usr/bin/env bash
# Immediate shared-pool mitigation. This does not replace the dedicated FPM pool.
set -Eeuo pipefail

app_parent=/srv/canary/php.micutu.com
app_root=/srv/canary/php.micutu.com/php_micutu_app
storage=/srv/canary/php.micutu.com/php_micutu_app_storage

[[ "$(realpath "${app_parent}")" == "${app_parent}" ]]
[[ "$(realpath "${app_root}")" == "${app_root}" ]]
[[ "$(realpath "${storage}")" == "${storage}" ]]
[[ -f "${app_root}/index.php" && -f "${app_root}/.env" && -f "${storage}/database.sqlite" ]]

# The shared www-data group may traverse/read the app, but it must not create or
# replace code. Storage intentionally remains the only group-writable app path.
chmod 0755 "${app_parent}" "${app_root}"
chmod 0640 "${app_root}/.env"
chmod 0770 "${storage}"
chmod 0660 "${storage}/database.sqlite"

[[ "$(stat -c '%a' "${app_parent}")" == 755 ]]
[[ "$(stat -c '%a' "${app_root}")" == 755 ]]
[[ "$(stat -c '%a' "${app_root}/.env")" == 640 ]]
[[ "$(stat -c '%a' "${storage}")" == 770 ]]
[[ "$(stat -c '%a' "${storage}/database.sqlite")" == 660 ]]

echo 'Current shared-pool permissions hardened.'
