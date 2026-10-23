# Guestbook — php.micutu.com

A small, self-contained PHP + SQLite guestbook. No build step, no external
runtime dependencies, no CDNs.

Production hardening is tracked in the [security audit](docs/SECURITY_AUDIT.md)
and the staged [product and security roadmap](docs/ROADMAP.md).

## Features
- Post messages with an optional name; colored deterministic avatars.
- Unicode, diacritic-insensitive SQLite FTS5 search with relevance ranking, prefix
  indexes, bounded plain-text queries and pagination.
- Permanent message links and an RSS 2.0 feed (`/?format=rss`).
- Sessionless `/api/v1/messages` with signed cursor pagination, ETags, conditional
  requests and a published OpenAPI 3.1 contract.
- Light/dark theme with a persisted toggle.
- Privacy-minimal abuse reporting with a rate-limited admin review queue.
- Five-state moderation, reversible actions and an append-only admin audit trail.
- Responsive admin workspace with status totals, controlled reasons and security events.
- Read-only JSON API: `/?format=json` (supports `q` and `page`).
- Privacy minimisation that detaches abuse-prevention identifiers after 24 hours;
  analytics is opt-in through configuration.
- Mobile-first responsive layout with keyboard, reduced-motion, forced-colors and
  screen-reader affordances, continuously checked in real Chromium with Axe.

## Security
- SQLite database lives **outside** the web root (`../php_micutu_app_storage`).
- CSRF tokens on every POST; hardened session cookie (HttpOnly/Secure/SameSite).
- Prepared statements everywhere; all output HTML-escaped.
- Input validation and length limits; control characters stripped.
- Rate limiting (per-session cooldown + per-IP hourly cap) and a bot honeypot.
- Deletion is admin-only (bcrypt password) with failed-login throttling.
- Reports never store a reporter identifier or arbitrary text; moderation cannot be
  triggered automatically by public reports.
- Strict Content-Security-Policy (no `unsafe-inline`), set at the nginx vhost.

## Structure
- `index.php` — controller + view.
- `config.php` — paths, `.env` loading, session and database bootstrap.
- `lib.php` — helpers (escaping, CSRF, rate limiting, icons, avatars, time).
- `api.php` / `openapi.json` — public API implementation and machine contract.
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

Run the dependency-free application and operations checks from the project root:

```bash
php tests/run.php
bash tests/http-smoke.sh
bash tests/backup-smoke.sh
shellcheck scripts/*.sh tests/*.sh
```

`bash scripts/ci.sh` runs the complete dependency-free suite. The same command runs in
GitHub Actions with read-only token permissions and a commit-pinned checkout action.

Browser checks use development-only, exact-version dependencies and a disposable
database/server. They never load the production `.env` or storage:

```bash
npm ci --ignore-scripts --no-audit --no-fund
./node_modules/.bin/playwright install chromium
npm run test:browser
```

GitHub CI installs the matching Chromium runtime, audits the locked npm graph and runs
the browser suite separately. See [the accessibility verification notes](docs/ACCESSIBILITY.md)
for automated coverage and the remaining manual checks.

Reviewed production templates and the isolation rollout are in [`ops/`](ops/README.md).

## Deployment
Nginx + PHP-FPM (8.5), TLS via Certbot, proxied through Cloudflare.
The vhost:
- includes `snippets/cloudflare-realip.conf` so `REMOTE_ADDR` is the real
  visitor IP behind Cloudflare;
- denies data/secret files (`*.sqlite`, `.env`, internal `*.php` includes);
- sends `snippets/php-guestbook-headers.conf` (tailored CSP).
