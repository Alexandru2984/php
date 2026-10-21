#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_dir}"

health_service='ops/systemd/php-guestbook-health.service'
health_timer='ops/systemd/php-guestbook-health.timer'

grep --quiet '^User=php-guestbook$' "${health_service}"
grep --quiet '^NoNewPrivileges=yes$' "${health_service}"
grep --quiet '^ProtectSystem=strict$' "${health_service}"
grep --quiet '^ReadWritePaths=/srv/canary/php.micutu.com/php_micutu_app_storage$' "${health_service}"
grep --quiet '^MemoryDenyWriteExecute=yes$' "${health_service}"
grep --quiet '^OnFailure=swift-vapor-alert@%n.service$' "${health_service}"
grep --quiet '^OnSuccess=swift-vapor-resolved@%n.service$' "${health_service}"
grep --quiet '^Persistent=true$' "${health_timer}"
grep --quiet '^OnFailure=swift-vapor-alert@%n.service$' ops/systemd/php-guestbook-backup.service
grep --quiet '^OnFailure=swift-vapor-alert@%n.service$' ops/systemd/php-guestbook-maintenance.service

if rg --ignore-case --glob '!tests/ops-smoke.sh' \
    'BOT_TOKEN|CHAT_ID|api\.telegram\.org|[0-9]{8,12}:[A-Za-z0-9_-]{30,}' \
    scripts tests ops docs .github; then
    echo 'Telegram credentials or a direct Bot API integration entered the repository.' >&2
    exit 1
fi

systemd-analyze calendar '*-*-* *:0/5:00' >/dev/null

echo 'Operations smoke tests passed.'
