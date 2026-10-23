#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="${SECURITY_SCAN_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "${repo_dir}"

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
    echo 'security-scan: repository-required' >&2
    exit 2
}

failures=0
report() {
    printf 'security-scan: FAIL file=%s rule=%s\n' "$1" "$2" >&2
    failures=$((failures + 1))
}

# Reject secret/data containers by filename before looking at their contents.
# Public examples and public-key files remain allowed.
while IFS= read -r -d '' path; do
    base="${path##*/}"
    lower="${base,,}"
    case "${lower}" in
        .env.example|*.env.example)
            ;;
        .env|*.env|.env.*|*.pem|*.key|*.p12|*.pfx|*.sqlite|*.sqlite3|*.sqlite-*|*.db|*.age|id_rsa|id_ed25519|id_ecdsa|rclone.conf|credentials.json|service-account.json|.npmrc)
            report "${path}" sensitive-filename
            ;;
    esac
done < <(git ls-files -z)

# Only high-confidence signatures are blocking. Never print matched content: CI logs
# identify the file and rule, not the credential candidate.
rule_names=(
    pem-private-key
    age-private-key
    github-token
    aws-access-key
    google-api-key
    slack-token
    stripe-secret-key
    telegram-bot-token
)
rule_patterns=(
    '-----BEGIN[[:space:]]+([A-Z0-9]+[[:space:]]+)?PRIVATE[[:space:]]+KEY-----'
    'AGE-SECRET-KEY-1[0-9A-Z]+'
    '(gh[pousr]_[A-Za-z0-9]{36,255}|github_pat_[A-Za-z0-9_]{22,255})'
    'AKIA[0-9A-Z]{16}'
    'AIza[0-9A-Za-z_-]{35}'
    'xox[baprs]-[0-9A-Za-z-]{10,255}'
    'sk_(live|test)_[0-9A-Za-z]{16,255}'
    '[0-9]{8,12}:[A-Za-z0-9_-]{30,255}'
)

while IFS= read -r -d '' path; do
    [[ -f "${path}" ]] || continue
    for index in "${!rule_names[@]}"; do
        if rg --quiet --pcre2 -- "${rule_patterns[$index]}" "${path}" 2>/dev/null; then
            report "${path}" "${rule_names[$index]}"
        fi
    done
done < <(git ls-files -z)

# Every external GitHub Action or reusable workflow must use an immutable 40-character
# commit SHA. Local actions are repository-controlled and may use a relative path.
while IFS= read -r -d '' workflow; do
    # report() writes only to stderr; it never mutates the workflow being read.
    # shellcheck disable=SC2094
    while IFS= read -r line; do
        if [[ "${line}" =~ uses:[[:space:]]+([^[:space:]#]+) ]]; then
            action="${BASH_REMATCH[1]}"
            [[ "${action}" == ./* ]] && continue
            reference="${action##*@}"
            if [[ "${action}" != *@* || ! "${reference}" =~ ^[0-9a-f]{40}$ ]]; then
                report "${workflow}" unpinned-action
            fi
        fi
    done <"${workflow}"
done < <(git ls-files -z '.github/workflows/*.yml' '.github/workflows/*.yaml')

# Lockfile checks are implemented with the already-required Node runtime. Packages
# with install hooks are denied even though CI also disables lifecycle scripts.
if [[ -f package.json ]]; then
    node <<'NODE' || failures=$((failures + 1))
const fs = require('node:fs');

function fail(subject, rule) {
  process.stderr.write(`security-scan: FAIL file=${subject} rule=${rule}\n`);
  process.exitCode = 1;
}

const manifest = JSON.parse(fs.readFileSync('package.json', 'utf8'));
const lock = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'));
const exact = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/;
const sha512 = /^sha512-[A-Za-z0-9+/]{80,}={0,2}$/;

for (const scope of ['dependencies', 'devDependencies', 'optionalDependencies']) {
  for (const [name, version] of Object.entries(manifest[scope] ?? {})) {
    if (!exact.test(version)) fail(`package.json:${scope}:${name}`, 'non-exact-version');
    const locked = lock.packages?.[`node_modules/${name}`]?.version;
    if (locked !== version) fail(`package-lock.json:${name}`, 'manifest-lock-mismatch');
  }
}

for (const [path, metadata] of Object.entries(lock.packages ?? {})) {
  if (path === '') continue;
  if (typeof metadata.resolved !== 'string' || !metadata.resolved.startsWith('https://')) {
    fail(`package-lock.json:${path}`, 'non-https-resolution');
  }
  if (typeof metadata.integrity !== 'string' || !sha512.test(metadata.integrity)) {
    fail(`package-lock.json:${path}`, 'missing-sha512-integrity');
  }
  if (metadata.hasInstallScript === true) {
    fail(`package-lock.json:${path}`, 'dependency-install-script');
  }
  if (typeof metadata.license !== 'string' || metadata.license.length === 0) {
    fail(`package-lock.json:${path}`, 'missing-license-metadata');
  }
}
NODE
fi

if (( failures > 0 )); then
    printf 'security-scan: failed checks=%s\n' "${failures}" >&2
    exit 1
fi

echo 'Security and supply-chain scan passed.'
