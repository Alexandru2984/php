# Product and security roadmap

The roadmap is ordered by risk reduction and dependency, not by visual impact. Each
stage must be an atomic commit (or small reviewed commit series), include tests and
have a rollback path. Production changes happen only after the stage passes its exit
criteria.

## Status after the 2026-10-21 hardening release

- **Deployed:** Stages 0–2 core controls: isolated build/test, recoverable backup,
  fail-closed configuration, persistent auth throttling, versioned migrations,
  security events, immutable releases, dedicated FPM identity, strict nginx routing,
  origin denial and nonce-based CSP.
- **Partially delivered:** Stage 3 now has category-only abuse reports, a review queue,
  five moderation states, reversible actions, controlled reasons, privacy/about UI,
  identifier minimisation and append-only admin audit; RSS/permalinks from Stage 5;
  responsive/accessibility foundations from Stage 6; pinned CI, `security.txt` and
  hardened systemd jobs from Stage 8.
- **Next priority:** off-host encrypted backup plus a production-snapshot restore
  drill, an incident-response tabletop, browser accessibility checks, API endpoints
  that avoid sessions, retention/export/erasure operations and risk-scored submission
  heuristics that queue rather than delete content.
- **Still deferred:** Stage 7 community features and the explicitly deferred features
  at the end of this document. They must not outrun the remaining privacy, abuse and
  observability controls.

## Stage 0 — Release safety and baseline (P0)

- Work in an isolated worktree/branch; never edit the served checkout in place.
- Capture a database snapshot and prove it can be restored into a disposable path.
- Add lint, unit, HTTP integration and malformed-input regression checks.
- Add a deployment script using immutable release directories and an atomic
  `current` symlink, plus health-check and automatic rollback.
- Record the live nginx, FPM pool, systemd backup unit and required file ownership as
  versioned templates without copying secrets or certificates.

**Exit:** clean test run, successful disposable restore, documented rollback, no
uncommitted production edits.

## Stage 1 — Application security core (P0)

- Fail closed on missing/weak `APP_SECRET`, invalid `APP_ENV` or unusable admin hash.
- Enable strict sessions; add admin idle/absolute expiry, privilege-transition CSRF
  rotation and complete logout destruction.
- Add typed request helpers, HTTP method allow-list, origin validation for mutations,
  canonical path handling and consistent security-safe errors.
- Replace session-only auth throttling with transactional server-side limits keyed by
  purpose and salted client identifier; add exponential lockouts and bounded cleanup.
- Move schema changes to versioned, transactional CLI migrations.
- Add security-event records without raw passwords, raw IPs, CSRF values or session
  IDs.

**Exit:** tests prove cookie rotation cannot reset the login limit, malformed arrays
are rejected without warnings, absent secrets stop production startup, and old admin
sessions expire.

## Stage 2 — Host isolation and edge policy (P0)

- Create a dedicated Unix identity and PHP-FPM pool for this app.
- Make releases, parent directory and `.env` non-writable by the worker; allow writes
  only to storage and a dedicated session directory.
- Restrict FPM with a dedicated socket, `clear_env`, `security.limit_extensions`,
  timeouts and carefully scoped filesystem access; test rather than blindly enabling
  a broad `disable_functions` list.
- Execute PHP only at `/index.php`; deny path-info and all other PHP files.
- Keep the Cloudflare-only origin guard and validate automatic IP-list refresh.
- Resolve Cloudflare JavaScript Detection with a per-response nonce or an explicit
  no-injection policy. Never add `unsafe-inline`.
- Add a report-only CSP rollout path and an authenticated CSP report collector with
  sampling/rate limits before tightening directives.

**Exit:** the worker cannot create a PHP file in any release or read another app's
secrets; direct-origin and protected-path probes remain blocked; CSP browser tests are
clean.

## Stage 3 — Moderation, auditability and privacy (P1)

- Introduce message states: pending, published, hidden, deleted and spam.
- Replace hard delete with soft delete, reason, timestamp, restore and separately
  authorized permanent purge.
- Add an append-only admin audit trail for login, approval, hide, restore, export and
  settings changes.
- Add a clean responsive admin dashboard: review queue, search/filter/sort, bulk
  actions, stats and suspicious-activity view.
- Add privacy/about/report-abuse pages, an operator contact, consent-aware analytics,
  retention policy and data export/erasure workflow.
- Erase `ip_hash` after the rate-limit/abuse window and rotate purpose-specific keys
  without exposing raw addresses.

**Exit:** every destructive admin action is attributable and reversible during the
retention window; public pages disclose processing; scheduled minimisation is tested.

## Stage 4 — Anti-abuse without hostile UX (P1)

- Add message heuristics: normalized Unicode, link/domain limits, duplicate detection,
  submission timing, disposable-pattern rules and a review score rather than opaque
  auto-deletion.
- Add optional Cloudflare Turnstile only when risk triggers it, with accessible and
  no-JavaScript failure paths.
- Separate edge limits for read, search, post, login and API traffic; account for
  shared NATs and IPv6 prefixes.
- Add signed abuse-report links, admin allow/block lists with expiry, and rate-limit
  visibility.

**Exit:** legitimate shared-network users can post; scripted abuse is throttled or
queued; no raw IP address is persisted by application code.

## Stage 5 — API and data layer (P1)

- Version the public API under `/api/v1/messages`; define errors, limits, stable
  cursors, `ETag`/conditional GET, cache policy and CORS (default same-origin).
- Stop creating sessions/cookies for anonymous API reads.
- Add RSS/Atom and canonical message permalinks.
- Add SQLite FTS5 search with indexed queries and bounded result windows.
- Add maintenance commands for backup, restore verification, retention, `VACUUM` /
  `optimize`, integrity checks and safe export.
- Publish an OpenAPI description and examples after the contract stabilizes.

**Exit:** API contract and cache behaviour are integration-tested; full scans are not
used for routine search; maintenance commands are idempotent.

## Stage 6 — Responsive UI, accessibility and identity (P1)

- Establish a deliberate visual system (type scale, spacing, semantic color tokens,
  shadows, motion) and a distinct Romanian/English product voice.
- Design and test at 320, 375, 768, 1024 and wide desktop widths; respect safe-area
  insets, zoom to 200%, touch targets and dynamic viewport units.
- Add visible keyboard focus, skip link, accurate theme-toggle state, status live
  regions, form error summaries, retained drafts/failed input and accessible delete
  dialogs.
- Verify WCAG 2.2 AA contrast, keyboard-only flows, screen-reader labels, reduced
  motion and forced-colors mode.
- Add language switching, localized relative dates, shareable filters and stable
  empty/loading/error states.

**Exit:** automated Axe/Lighthouse checks plus manual keyboard and screen-reader
smoke tests pass; no horizontal overflow at the supported widths.

## Stage 7 — Community features (P2)

- Opt-in profiles/display identity without requiring public email addresses.
- Reply threads with bounded depth, mention notifications and moderator controls.
- Reactions protected against replay/abuse, pinned/featured messages, bookmarks and
  share links.
- Author edit/delete window using one-time recovery capability tokens (hashed at
  rest), with moderation overrides and audit history.
- Draft autosave stored locally, preview, Markdown subset rendered through a strict
  allow-list, and link safety (`rel`, scheme validation).
- Optional notification subscriptions with verified opt-in, rate limits and simple
  unsubscribe.

**Exit:** each feature has an abuse/privacy analysis, authorization tests and a way
for users/moderators to reverse mistakes.

## Stage 8 — Reliability, observability and delivery (P1/P2)

- Add `/health/live` and protected readiness/diagnostic checks that reveal no secrets.
- Add structured logs and metrics for latency, status, SQLite busy time, queue size,
  login failures, moderation and backups; alert on symptoms, not raw log existence.
- Version the hardened backup script/systemd units; keep daily local snapshots plus an
  encrypted off-host copy and quarterly restore drill.
- Add GitHub CI: PHP syntax/tests, ShellCheck, HTML/CSS/JS checks, secret scanning,
  SAST, dependency review when dependencies appear, browser/a11y smoke tests.
- Add branch protection, signed/tagged releases, change log, deploy approval and
  automated post-deploy checks.
- Add `security.txt`, vulnerability-reporting instructions, IR/data-breach runbooks,
  contact tree and quarterly tabletop cadence.

**Exit:** backup and release SLOs are measured; a failed health-check rolls back; a
credential-compromise tabletop reaches containment within target time.

## Explicitly deferred until foundations pass

- Full PWA/offline HTML caching (risk of caching personalized admin/CSRF pages).
- File/image uploads (large parsing, storage and malware-scanning attack surface).
- OAuth/social login (new vendor and account-recovery dependency).
- WebSockets/live chat (state, abuse and availability complexity).
- Arbitrary HTML/embeds or unrestricted Markdown (XSS and tracking surface).

These can be reconsidered with a fresh threat model after Stages 0–3.
