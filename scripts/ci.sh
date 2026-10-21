#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_dir}"

for file in ./*.php scripts/*.php tests/*.php; do
    php -l "${file}"
done

node --check assets/app.js
node --check assets/theme-init.js
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$css=file_get_contents("assets/style.css"); if(substr_count($css,"{")!==substr_count($css,"}")){fwrite(STDERR,"Unbalanced CSS braces\n");exit(1);} echo "CSS braces balanced.\n";'

shellcheck -x scripts/*.sh tests/*.sh ops/scripts/*.sh
php tests/run.php
bash tests/http-smoke.sh
bash tests/backup-smoke.sh
git diff --check

echo 'All checks passed.'
