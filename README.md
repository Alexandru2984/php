# Guestbook — php.micutu.com

A small, self-contained PHP + SQLite guestbook. No build step, no external
runtime dependencies, no CDNs.

## Features
- Post messages with an optional name; colored deterministic avatars.
- Search and pagination.
- Light/dark theme with a persisted toggle.
- Admin login for moderation (deleting messages).
- Read-only JSON API: `/?format=json` (supports `q` and `page`).

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
2. Ensure `../php_micutu_app_storage/` exists and is writable by the PHP-FPM
   user (`www-data`): `chown micu:www-data` + `chmod 2770` on the directory.

## Deployment
Nginx + PHP-FPM (8.5), TLS via Certbot, proxied through Cloudflare.
The vhost:
- includes `snippets/cloudflare-realip.conf` so `REMOTE_ADDR` is the real
  visitor IP behind Cloudflare;
- denies data/secret files (`*.sqlite`, `.env`, internal `*.php` includes);
- sends `snippets/php-guestbook-headers.conf` (tailored CSP).
