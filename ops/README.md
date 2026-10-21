# Production operations

These files are reviewed templates, not an unattended installer. Validate every path
against the target host and take a database/config snapshot before changing services.

## Current production boundary

The hardened layout uses `/srv/canary/php.micutu.com/current` as an atomic symlink to
an immutable release. PHP runs as `php-guestbook`; its `.env` is a link to a shared
`root:php-guestbook` mode `0640` file. Storage, sessions and backups are private to the
runtime identity. The shared nginx identity must be denied direct access:

```bash
sudo -u www-data test ! -r /srv/canary/php.micutu.com/shared/.env
sudo -u www-data test ! -r /srv/canary/php.micutu.com/php_micutu_app_storage/database.sqlite
sudo -u php-guestbook test ! -w /srv/canary/php.micutu.com/current/index.php
```

The nginx socket remains accessible to `www-data` because nginx workers must connect
to FPM. `clear_env`, `open_basedir`, the single executable front controller and the
non-writable release boundary limit what can be reached through that socket.

## Legacy immediate mitigation

`ops/scripts/harden-current-permissions.sh` removes the shared `www-data` group's
ability to create or replace code in the current document root while preserving its
read access and SQLite write access. This is only an emergency intermediate state
before the dedicated-pool rollout, not the desired final boundary. Verify afterward:

```bash
sudo -u www-data test ! -w /srv/canary/php.micutu.com/php_micutu_app
sudo -u www-data test -r /srv/canary/php.micutu.com/php_micutu_app/.env
sudo -u www-data test -w /srv/canary/php.micutu.com/php_micutu_app_storage/database.sqlite
curl -fsS https://php.micutu.com/ >/dev/null
```

Do not roll back by making the webroot writable. If a deployment currently depends on
that, fix the deployment process and keep runtime writes in storage.

## Dedicated pool rollout

1. Create a locked system user/group named `php-guestbook` with no login shell.
2. Create `/srv/canary/php.micutu.com/releases`, `current`, and `sessions`. Releases
   are owned by the deploy user and never writable by `php-guestbook`; sessions and
   `php_micutu_app_storage` are owned by `php-guestbook`.
3. Install `.env` as `root:php-guestbook` mode `0640`; do not put it in a release.
   Supply it via a protected shared link or the process environment.
4. Install the FPM template, run `php-fpm8.5 -tt`, start the pool and confirm its
   socket identity/mode.
5. Apply migrations against a restored disposable copy, snapshot production, apply
   production migrations with `APP_STORAGE_DIR` explicitly set, and atomically
   repoint `current` to the tested release. Release-directory auto-detection must not
   be used for the shared database path.
6. Install the nginx template, run `nginx -t`, reload, and probe HTML, API, protected
   paths, unsupported methods, direct-origin denial and CSP in a browser.
7. Install/enable the systemd units only after `systemd-analyze security` and manual
   one-shot backup/maintenance runs pass.

Rollback is an atomic `current` symlink switch plus nginx/FPM reload if configuration
changed. Database migrations must remain backward compatible or ship with an explicit,
tested down migration; never restore over the live database while PHP-FPM is writing.

## Backup recovery objective

The tracked backup script uses SQLite's online `.backup`, validates integrity,
compresses atomically, applies mode `0600`, serializes overlapping runs and keeps 14
days by default. The weekly sandboxed restore drill independently decompresses the
newest production snapshot, verifies SQLite integrity/foreign keys and compiles reads
against the current schema. Its status becomes unhealthy after eight days. The
engineering targets are a 24-hour RPO and two-hour RTO; production still needs an
encrypted off-host copy and owner acceptance of those targets. Full recovery and key
separation are documented in [`docs/RECOVERY_DRILL.md`](../docs/RECOVERY_DRILL.md).

## Monitoring and Telegram pager

The health probe is intentionally independent of the application notification path.
It reuses the host's existing `swift-vapor-alert@.service` and
`swift-vapor-resolved@.service` pager templates, which own encrypted Telegram
credentials, deduplication and recovery state. Verify those templates and their helper
before enabling the guestbook timer; no Telegram secret belongs in this repository.

Install and validate the versioned units only after the matching immutable release is
live:

```bash
sudo install -o root -g root -m 0644 ops/systemd/php-guestbook-health.service /etc/systemd/system/
sudo install -o root -g root -m 0644 ops/systemd/php-guestbook-health.timer /etc/systemd/system/
sudo install -o root -g root -m 0644 ops/systemd/php-guestbook-backup.service /etc/systemd/system/
sudo install -o root -g root -m 0644 ops/systemd/php-guestbook-restore-drill.service /etc/systemd/system/
sudo install -o root -g root -m 0644 ops/systemd/php-guestbook-restore-drill.timer /etc/systemd/system/
sudo install -o root -g root -m 0644 ops/systemd/php-guestbook-maintenance.service /etc/systemd/system/
sudo systemd-analyze verify /etc/systemd/system/php-guestbook-{health,backup,restore-drill,maintenance}.service
sudo systemctl daemon-reload
sudo systemctl start php-guestbook-restore-drill.service
sudo systemctl start php-guestbook-health.service
sudo systemctl enable --now php-guestbook-health.timer php-guestbook-restore-drill.timer
```

The manual health run must report a single privacy-minimal `ok` line. Inspect its
systemd sandbox score, timer schedule and journal. Test a synthetic alert and resolved
notification without damaging the live database or making the release writable.
Thresholds, payload constraints, CISO review and response steps are in
[`docs/MONITORING.md`](../docs/MONITORING.md) and
[`docs/INCIDENT_RESPONSE.md`](../docs/INCIDENT_RESPONSE.md).
