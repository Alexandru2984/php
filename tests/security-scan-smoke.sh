#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"

cleanup() {
    if [[ -n "${tmp_dir}" && "${tmp_dir}" == /tmp/* ]]; then
        rm -rf -- "${tmp_dir}"
    fi
}
trap cleanup EXIT

fixture="${tmp_dir}/repo"
mkdir -p "${fixture}/scripts" "${fixture}/.github/workflows"
cp "${repo_dir}/scripts/security-scan.sh" "${fixture}/scripts/security-scan.sh"
cp "${repo_dir}/package.json" "${repo_dir}/package-lock.json" "${fixture}/"
printf '%s\n' '# Fixture' >"${fixture}/README.md"
printf '%s\n' \
    'name: fixture' \
    'on: [push]' \
    'jobs:' \
    '  test:' \
    '    runs-on: ubuntu-24.04' \
    '    steps:' \
    '      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1' \
    >"${fixture}/.github/workflows/ci.yml"

git -C "${fixture}" init --quiet
git -C "${fixture}" add scripts/security-scan.sh README.md package.json package-lock.json .github/workflows/ci.yml
bash "${fixture}/scripts/security-scan.sh" >/dev/null

sed -i 's/"4.14.0"/"^4.14.0"/' "${fixture}/package.json"
git -C "${fixture}" add package.json
if bash "${fixture}/scripts/security-scan.sh" >"${tmp_dir}/package.log" 2>&1; then
    echo 'Non-exact npm dependency unexpectedly passed the scanner.' >&2
    exit 1
fi
grep --quiet 'rule=non-exact-version' "${tmp_dir}/package.log"
cp "${repo_dir}/package.json" "${fixture}/package.json"
git -C "${fixture}" add package.json

secret_value="github_pat_$(printf 'a%.0s' {1..30})"
printf '%s\n' "${secret_value}" >"${fixture}/leak.txt"
git -C "${fixture}" add leak.txt
if bash "${fixture}/scripts/security-scan.sh" >"${tmp_dir}/secret.log" 2>&1; then
    echo 'Secret signature unexpectedly passed the scanner.' >&2
    exit 1
fi
grep --quiet 'rule=github-token' "${tmp_dir}/secret.log"
if grep --fixed-strings --quiet "${secret_value}" "${tmp_dir}/secret.log"; then
    echo 'Secret candidate leaked into scanner output.' >&2
    exit 1
fi

git -C "${fixture}" rm --cached --quiet leak.txt
printf '%s\n' \
    'name: fixture' \
    'on: [push]' \
    'jobs:' \
    '  test:' \
    '    runs-on: ubuntu-24.04' \
    '    steps:' \
    '      - uses: actions/checkout@v7' \
    >"${fixture}/.github/workflows/ci.yml"
git -C "${fixture}" add .github/workflows/ci.yml
if bash "${fixture}/scripts/security-scan.sh" >"${tmp_dir}/action.log" 2>&1; then
    echo 'Mutable GitHub Action reference unexpectedly passed the scanner.' >&2
    exit 1
fi
grep --quiet 'rule=unpinned-action' "${tmp_dir}/action.log"

printf '%s\n' 'not-a-secret' >"${fixture}/.env"
git -C "${fixture}" add --force .env
if bash "${fixture}/scripts/security-scan.sh" >"${tmp_dir}/filename.log" 2>&1; then
    echo 'Tracked .env unexpectedly passed the scanner.' >&2
    exit 1
fi
grep --quiet 'rule=sensitive-filename' "${tmp_dir}/filename.log"

echo 'Security scanner smoke tests passed.'
