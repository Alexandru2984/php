#!/usr/bin/env bash
# Encrypts verified local SQLite snapshots to an operator-held recovery recipient,
# then copies and checks ciphertext at every configured off-host destination.
set -Eeuo pipefail

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
umask 077

source_dir="${OFFSITE_SOURCE_DIR:-/srv/backups/php-guestbook}"
artifact_dir="${OFFSITE_ARTIFACT_DIR:-${source_dir}/offsite}"
recipients_file="${OFFSITE_RECIPIENTS_FILE:-/etc/php-guestbook/backup-recipients}"
recipients_sha256="${OFFSITE_RECIPIENTS_SHA256:-}"
remotes_raw="${OFFSITE_REMOTES:-${OFFSITE_REMOTE:-}}"
rclone_bin="${OFFSITE_RCLONE:-/usr/bin/rclone}"
rclone_config="${OFFSITE_RCLONE_CONFIG:-}"
age_bin="${OFFSITE_AGE:-/usr/bin/age}"
status_file="${OFFSITE_STATUS_FILE:-/var/lib/php-guestbook-offsite/last-success}"
transfers="${OFFSITE_TRANSFERS:-2}"
max_source_age="${OFFSITE_MAX_SOURCE_AGE_SECONDS:-108000}"
local_keep_days="${OFFSITE_LOCAL_KEEP_DAYS:-30}"

fail() {
    printf 'guestbook-offsite: FAIL component=%s reason=%s\n' "$1" "$2" >&2
    exit 1
}

require_uint() {
    local name="$1" value="$2" minimum="$3" maximum="$4"
    [[ "${value}" =~ ^[0-9]+$ ]] || fail configuration "${name}-invalid"
    (( value >= minimum && value <= maximum )) \
        || fail configuration "${name}-out-of-range"
}

for configured_path in \
    "${source_dir}" "${artifact_dir}" "${recipients_file}" \
    "${rclone_config}" "${status_file}"; do
    [[ "${configured_path}" == /* && "${configured_path}" != *$'\n'* ]] \
        || fail configuration path-invalid
done
[[ "${artifact_dir}" == "${source_dir}/offsite" ]] \
    || fail configuration artifact-directory-invalid
[[ "${recipients_sha256}" =~ ^[0-9a-f]{64}$ ]] \
    || fail configuration recipients-digest-invalid
require_uint transfers "${transfers}" 1 32
require_uint max-source-age "${max_source_age}" 60 604800
require_uint local-keep-days "${local_keep_days}" 7 365

[[ -x "${age_bin}" ]] || fail dependency age-missing
[[ -x "${rclone_bin}" ]] || fail dependency rclone-missing
[[ -d "${source_dir}" && ! -L "${source_dir}" ]] \
    || fail source directory-invalid
[[ -f "${recipients_file}" && ! -L "${recipients_file}" ]] \
    || fail encryption recipients-invalid
[[ "$(sha256sum "${recipients_file}" | awk '{print $1}')" == "${recipients_sha256}" ]] \
    || fail encryption recipients-drift

recipients_mode="$(stat -c '%a' -- "${recipients_file}")"
case "${recipients_mode}" in
    600|640|644) ;;
    *) fail encryption recipients-permissions-invalid ;;
esac

[[ -f "${rclone_config}" && ! -L "${rclone_config}" ]] \
    || fail transport config-invalid
config_mode="$(stat -c '%a' -- "${rclone_config}")"
case "${config_mode}" in
    400|600|640) ;;
    *) fail transport config-permissions-invalid ;;
esac

[[ -n "${remotes_raw}" ]] || fail configuration remotes-missing
mapfile -t remotes < <(printf '%s' "${remotes_raw}" | tr -s ' \t' '\n' | grep -v '^$')
(( ${#remotes[@]} >= 2 )) || fail configuration destination-redundancy-missing
for remote in "${remotes[@]}"; do
    [[ "${remote}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*:[A-Za-z0-9_./-]+$ ]] \
        || fail configuration destination-invalid
done

status_dir="$(dirname -- "${status_file}")"
[[ -d "${status_dir}" && ! -L "${status_dir}" ]] \
    || fail status directory-invalid

install -d -m 0700 "${artifact_dir}"
exec 8>"${source_dir}/.offsite.lock"
flock -n 8 || fail source lock-busy
exec 9>"${source_dir}/.backup.lock"
flock -s -w 30 9 || fail source backup-lock-timeout

snapshots=()
latest_source_mtime=0
while IFS= read -r -d '' snapshot; do
    snapshot_mode="$(stat -c '%a' -- "${snapshot}")" \
        || fail source metadata-unavailable
    [[ "${snapshot_mode}" == 600 ]] || fail source permissions-invalid
    snapshot_mtime="$(stat -c '%Y' -- "${snapshot}")" \
        || fail source metadata-unavailable
    snapshots+=("${snapshot}")
    if (( snapshot_mtime > latest_source_mtime )); then
        latest_source_mtime="${snapshot_mtime}"
    fi
done < <(find "${source_dir}" -maxdepth 1 -type f -name 'database-*.sqlite.gz' -print0)
(( ${#snapshots[@]} > 0 )) || fail source snapshots-missing

now="$(date +%s)"
source_age=$(( now - latest_source_mtime ))
(( source_age >= 0 && source_age <= max_source_age )) \
    || fail source stale

created=0
artifact_tmp=''
status_tmp=''
cleanup() {
    if [[ -n "${artifact_tmp}" && -e "${artifact_tmp}" ]]; then
        unlink "${artifact_tmp}" 2>/dev/null || true
    fi
    if [[ -n "${status_tmp}" && -e "${status_tmp}" ]]; then
        unlink "${status_tmp}" 2>/dev/null || true
    fi
}
trap cleanup EXIT
for snapshot in "${snapshots[@]}"; do
    gzip -t -- "${snapshot}" || fail source gzip-invalid
    artifact="${artifact_dir}/$(basename -- "${snapshot}").age"
    if [[ -e "${artifact}" ]]; then
        [[ -f "${artifact}" && ! -L "${artifact}" \
            && "$(stat -c '%a' -- "${artifact}")" == 600 ]] \
            || fail encryption artifact-invalid
        IFS= read -r age_header <"${artifact}" || fail encryption artifact-unreadable
        [[ "${age_header}" == age-encryption.org/v1 ]] \
            || fail encryption artifact-header-invalid
        continue
    fi

    artifact_tmp="$(mktemp "${artifact_dir}/.artifact.partial.XXXXXX")" \
        || fail encryption temp-create-failed
    if ! "${age_bin}" -R "${recipients_file}" -o "${artifact_tmp}" "${snapshot}"; then
        unlink "${artifact_tmp}" 2>/dev/null || true
        fail encryption failed
    fi
    chmod 0600 "${artifact_tmp}"
    IFS= read -r age_header <"${artifact_tmp}" \
        || fail encryption artifact-unreadable
    [[ "${age_header}" == age-encryption.org/v1 ]] \
        || fail encryption artifact-header-invalid
    mv -- "${artifact_tmp}" "${artifact}"
    artifact_tmp=''
    created=$(( created + 1 ))
done

mapfile -d '' -t artifacts < <(
    find "${artifact_dir}" -maxdepth 1 -type f -name 'database-*.sqlite.gz.age' -print0
)
(( ${#artifacts[@]} > 0 )) || fail encryption artifacts-missing

rclone_args=(
    --config "${rclone_config}"
    --include 'database-*.sqlite.gz.age'
    --transfers "${transfers}"
    --checkers 4
    --retries 3
    --low-level-retries 10
    --timeout 5m
    --contimeout 30s
    --stats 0
    --log-level NOTICE
)

verified_destinations=0
failed_destinations=0
for remote in "${remotes[@]}"; do
    if ! "${rclone_bin}" copy "${artifact_dir}" "${remote}" \
        "${rclone_args[@]}" --immutable --checksum; then
        failed_destinations=$(( failed_destinations + 1 ))
        continue
    fi
    if ! "${rclone_bin}" check "${artifact_dir}" "${remote}" \
        "${rclone_args[@]}" --one-way --checksum; then
        failed_destinations=$(( failed_destinations + 1 ))
        continue
    fi
    verified_destinations=$(( verified_destinations + 1 ))
done
(( verified_destinations > 0 )) || fail transport no-destination-verified

status_tmp="$(mktemp "${status_dir}/.last-success.partial.XXXXXX")" \
    || fail status create-failed
printf 'success_epoch=%s source_mtime=%s artifacts=%s verified=%s configured=%s failed=%s\n' \
    "${now}" "${latest_source_mtime}" "${#artifacts[@]}" \
    "${verified_destinations}" "${#remotes[@]}" "${failed_destinations}" \
    >"${status_tmp}"
chmod 0600 "${status_tmp}"
mv -f -- "${status_tmp}" "${status_file}"
status_tmp=''

# Local ciphertext is only a retry cache. Remote history is never deleted or
# mirrored by this script; provider retention is an independently reviewed control.
find "${artifact_dir}" -maxdepth 1 -type f -name 'database-*.sqlite.gz.age' \
    -mtime "+${local_keep_days}" -delete

if (( failed_destinations > 0 )); then
    printf 'guestbook-offsite: FAIL component=transport reason=redundancy-degraded verified=%s failed=%s\n' \
        "${verified_destinations}" "${failed_destinations}" >&2
    exit 1
fi

printf 'guestbook-offsite: ok artifacts=%s created=%s destinations=%s source_age_seconds=%s\n' \
    "${#artifacts[@]}" "${created}" "${verified_destinations}" "${source_age}"
trap - EXIT
