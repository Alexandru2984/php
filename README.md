# Guestbook — php.micutu.com

A small, self-contained PHP + SQLite guestbook. No build step, no external
runtime dependencies, no CDNs.

Production hardening is tracked in the [security audit](docs/SECURITY_AUDIT.md)
and the staged [product and security roadmap](docs/ROADMAP.md).

## Features
- Post messages with an optional name; colored deterministic avatars.
- Unicode, diacritic-insensitive SQLite FTS5 search with relevance ranking, prefix
  indexes, bounded plain-text queries and pagination.
- Clean permanent message links (`/messages/ID`) plus cache-aware RSS 2.0 and
  JSON Feed 1.1 discovery (`/feed.xml` and `/feed.json`).
- Crawlable newer/older navigation on message permalinks with a UTC month
  breadcrumb, stable ordering and published-only boundaries.
- Responsive UTC calendar discovery at `/archive` plus clean `/archive/YYYY/MM`
  pages, canonical pagination, semantic breadcrumbs and published-only counts.
- Progressive native sharing with a local clipboard fallback and no third-party SDK.
- Opt-in 24-hour local drafts and a text-only preview that never renders visitor HTML.
- A single moderator-selected featured message with atomic replacement, reversible
  controls and append-only audit history.
- Technical SEO with stable canonical URLs, versioned robots policy, a sessionless
  XML sitemap, calendar discovery, RSS/JSON feeds, social metadata and
  forum-posting structured data.
- Indexable, sessionless About, Privacy and Moderation pages with honest retention
  disclosures, breadcrumb/page microdata, strong ETags and responsive policy layouts.
- Sessionless `/api/v1/messages` with signed cursor pagination, ETags, conditional
  requests and a published OpenAPI 3.1 contract.
- Light/dark theme with a persisted toggle.
- Privacy-minimal abuse reporting with a rate-limited admin review queue.
- Private, cookie-bound message management with a minimal JSON copy and irreversible
  first-party withdrawal for the posting browser.
- Explainable anti-abuse scoring that queues risky submissions for reversible human
  review without exposing them in public HTML, search, feeds or API responses.
- Five-state moderation, reversible individual actions, atomic suppression/re-queue
  batches of up to 20 explicit selections and an append-only per-message admin audit.
- Responsive admin workspace with indexed review filters, queue-age/risk statistics,
  privacy-minimal suspicious-activity aggregates, controlled reasons and audit events.
- Read-only JSON API: `/?format=json` (supports `q` and `page`).
- Privacy minimisation that detaches abuse-prevention identifiers after 24 hours;
  analytics is opt-in through configuration and its reviewed tracker is served from
  the versioned release with a pinned digest.
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
- Duplicate fingerprints and posting identifiers are purpose-separated, keyed and
  detached after 24 hours; automated review never deletes content.
- Strict Content-Security-Policy (no `unsafe-inline`), set at the nginx vhost.

## Structure
- `index.php` — controller + view.
- `public-info.php` — public About, Privacy and Moderation page view.
- `config.php` — paths, `.env` loading, session and database bootstrap.
- `lib.php` — helpers (escaping, CSRF, rate limiting, icons, avatars, time).
- `api.php` / `openapi.json` — public API implementation and machine contract.
- `assets/` — self-hosted CSS/JS (`style.css`, `app.js`, `theme-init.js`).
- `../php_micutu_app_storage/database.sqlite` — the database (not web-served).

## Setup
1. Install PHP with PDO SQLite, mbstring and intl/Normalizer support, plus SQLite CLI
   for the operational checks.
2. Copy `.env.example` to `.env` and fill in:
   - `ADMIN_PASSWORD_HASH` — `php -r 'echo password_hash("pw", PASSWORD_DEFAULT), "\n";'`
   - `APP_SECRET` — `php -r 'echo bin2hex(random_bytes(32)), "\n";'`
   - `APP_ORIGIN` — the canonical HTTPS origin, without a trailing path.
3. Ensure `../php_micutu_app_storage/` exists and is writable by the PHP-FPM
   user (`www-data`): `chown micu:www-data` + `chmod 2770` on the directory.
4. Apply database migrations before switching a release live:
   `php scripts/migrate.php`.
5. Run `php scripts/maintenance.php` daily to prune expired pseudonymous security
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
