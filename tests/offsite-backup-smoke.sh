#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"
cleanup() {
    if [[ -n "${tmp_dir}" && "${tmp_dir}" == /tmp/* ]]; then
        find "${tmp_dir}" -depth -delete
    fi
}
trap cleanup EXIT

source_dir="${tmp_dir}/backups"
artifact_dir="${source_dir}/offsite"
status_dir="${tmp_dir}/status"
status_file="${status_dir}/last-success"
recipients_file="${tmp_dir}/recipients"
identity_file="${tmp_dir}/identity"
rclone_config="${tmp_dir}/rclone.conf"
mkdir -p "${source_dir}" "${status_dir}"
chmod 0700 "${source_dir}" "${status_dir}"

printf 'recoverable guestbook fixture\n' >"${tmp_dir}/database.sqlite"
gzip -c "${tmp_dir}/database.sqlite" >"${source_dir}/database-20261022T000000Z.sqlite.gz"
chmod 0600 "${source_dir}/database-20261022T000000Z.sqlite.gz"
age-keygen -o "${identity_file}" >/dev/null 2>&1
age-keygen -y "${identity_file}" >"${recipients_file}"
chmod 0644 "${recipients_file}"
recipients_sha256="$(sha256sum "${recipients_file}" | awk '{print $1}')"
printf '[fixture]\ntype = local\n' >"${rclone_config}"
chmod 0600 "${rclone_config}"

offsite_env=(
    "OFFSITE_SOURCE_DIR=${source_dir}"
    "OFFSITE_ARTIFACT_DIR=${artifact_dir}"
    "OFFSITE_RECIPIENTS_FILE=${recipients_file}"
    "OFFSITE_RECIPIENTS_SHA256=${recipients_sha256}"
    'OFFSITE_REMOTES=first:guestbook second:guestbook'
    'OFFSITE_RCLONE=/bin/true'
    "OFFSITE_RCLONE_CONFIG=${rclone_config}"
    "OFFSITE_STATUS_FILE=${status_file}"
    'OFFSITE_MAX_SOURCE_AGE_SECONDS=108000'
    'OFFSITE_LOCAL_KEEP_DAYS=30'
)

output="$(env "${offsite_env[@]}" bash "${repo_dir}/scripts/offsite-backup.sh")"
[[ "${output}" == guestbook-offsite:\ ok* ]]
artifact="${artifact_dir}/database-20261022T000000Z.sqlite.gz.age"
[[ -f "${artifact}" && "$(stat -c '%a' "${artifact}")" == 600 ]]
[[ "$(head -n 1 "${artifact}")" == age-encryption.org/v1 ]]
age -d -i "${identity_file}" -o "${tmp_dir}/restored.sqlite.gz" "${artifact}"
gzip -t "${tmp_dir}/restored.sqlite.gz"
[[ "$(gzip -dc "${tmp_dir}/restored.sqlite.gz")" == 'recoverable guestbook fixture' ]]
grep --quiet '^success_epoch=[0-9]\+ source_mtime=[0-9]\+ artifacts=1 destinations=2$' \
    "${status_file}"
[[ "$(stat -c '%a' "${status_file}")" == 600 ]]

bad_remote_env=("${offsite_env[@]}")
bad_remote_env[4]='OFFSITE_REMOTES=-bad:guestbook second:guestbook'
set +e
bad_remote_output="$(env "${bad_remote_env[@]}" bash "${repo_dir}/scripts/offsite-backup.sh" 2>&1)"
bad_remote_exit=$?
set -e
[[ "${bad_remote_exit}" -ne 0 ]]
[[ "${bad_remote_output}" == *'component=configuration reason=destination-invalid'* ]]

bad_digest_env=("${offsite_env[@]}")
bad_digest_env[3]='OFFSITE_RECIPIENTS_SHA256=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
set +e
bad_digest_output="$(env "${bad_digest_env[@]}" bash "${repo_dir}/scripts/offsite-backup.sh" 2>&1)"
bad_digest_exit=$?
set -e
[[ "${bad_digest_exit}" -ne 0 ]]
[[ "${bad_digest_output}" == *'component=encryption reason=recipients-drift'* ]]

echo 'Encrypted off-host backup smoke tests passed.'
