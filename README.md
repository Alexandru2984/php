# Guestbook — php.micutu.com

A small, self-contained PHP + SQLite guestbook. No build step, no external
runtime dependencies, no CDNs.

Production hardening is tracked in the [security audit](docs/SECURITY_AUDIT.md)
and the staged [product and security roadmap](docs/ROADMAP.md).

## Features
- Post messages with an optional name; colored deterministic avatars.
- Search and pagination.
- Permanent message links and an RSS 2.0 feed (`/?format=rss`).
- Light/dark theme with a persisted toggle.
- Admin login for moderation (soft-deleting messages).
- Recoverable moderation trash with restore controls and a recent security-event view.
- Read-only JSON API: `/?format=json` (supports `q` and `page`).
- Privacy minimisation that detaches abuse-prevention identifiers after 24 hours;
  analytics is opt-in through configuration.
- Mobile-first responsive layout with keyboard, reduced-motion, forced-colors and
  screen-reader affordances.

## Security
- SQLite database lives **outside** the web root (`../php_micutu_app_storage`).
- CSRF tokens on every POST; hardened session cookie (HttpOnly/Secure/SameSite).
- Prepared statements everywhere; all output HTML-escaped.
- Input validation and length limits; control characters stripped.
- Rate limiting (per-session cooldown + per-IP hourly cap) and a bot honeypot.
- Deletion is admin-only (bcrypt password) with failed-login throttling.
- Strict Content-Security-Policy (no `unsafe-inline`), set at the nginx vhost.

## Structure
- `index.php` — controller + view.
- `config.php` — paths, `.env` loading, session and database bootstrap.
- `lib.php` — helpers (escaping, CSRF, rate limiting, icons, avatars, time).
- `assets/` — self-hosted CSS/JS (`style.css`, `app.js`, `theme-init.js`).
- `../php_micutu_app_storage/database.sqlite` — the database (not web-served).

## Setup
1. Copy `.env.example` to `.env` and fill in:
   - `ADMIN_PASSWORD_HASH` — `php -r 'echo password_hash("pw", PASSWORD_DEFAULT), "\n";'`
   - `APP_SECRET` — `php -r 'echo bin2hex(random_bytes(32)), "\n";'`
   - `APP_ORIGIN` — the canonical HTTPS origin, without a trailing path.
2. Ensure `../php_micutu_app_storage/` exists and is writable by the PHP-FPM
   user (`www-data`): `chown micu:www-data` + `chmod 2770` on the directory.
3. Apply database migrations before switching a release live:
   `php scripts/migrate.php`.
4. Run `php scripts/maintenance.php` daily to prune expired pseudonymous security
   records and optimize SQLite.

## Verification

Run the dependency-free checks from the project root:

```bash
php tests/run.php
bash tests/http-smoke.sh
bash tests/backup-smoke.sh
shellcheck scripts/*.sh tests/*.sh
```

Reviewed production templates and the isolation rollout are in [`ops/`](ops/README.md).

## Deployment
Nginx + PHP-FPM (8.5), TLS via Certbot, proxied through Cloudflare.
The vhost:
- includes `snippets/cloudflare-realip.conf` so `REMOTE_ADDR` is the real
  visitor IP behind Cloudflare;
- denies data/secret files (`*.sqlite`, `.env`, internal `*.php` includes);
- sends `snippets/php-guestbook-headers.conf` (tailored CSP).
