# Security audit

**Date:** 2026-10-21  
**Scope:** application source, Git history, live response behaviour, nginx/PHP-FPM
configuration, SQLite metadata and backup controls. Message bodies and secret values
were not inspected.

## Executive verdict

**HIGH-PRIORITY REMEDIATION DEPLOYED; RESIDUAL OPERATIONAL RISK REMAINS.** S-01
through S-05 were remediated on 2026-10-21 using an immutable release, a dedicated
Unix/FPM identity, fail-closed configuration, persistent throttling and an atomic
cutover. The production database remained at three messages and passed integrity
checks before and after migration. Detection, a privacy disclosure, reversible
moderation, category-only abuse reporting and an incident-response runbook are now
deployed. Off-host backups, a formal tabletop, data-subject operations and full
browser/accessibility automation remain incomplete.

## Deployment verification snapshot

- Production serves the tested immutable release through the `current` symlink and
  the dedicated `php-guestbook` FPM pool. The previous release and nginx configuration
  are retained for rollback.
- `www-data` cannot read either production `.env` copy or the SQLite database. The
  runtime identity cannot write a release; storage, sessions and local backups are
  mode `0700` directories owned by `php-guestbook`.
- Versioned migrations ran once and then skipped idempotently. The database reported
  `integrity=ok`, three published messages and four applied migrations after the
  moderation cutover. The new report queue was empty at deployment.
- Public probes returned `200` for HTML, JSON, RSS, CSS and `security.txt`; sensitive
  paths and path-info returned `404`, unsupported methods returned `405`, and direct
  access through the VPS public address returned `403`.
- Manual one-shot backup and maintenance jobs succeeded under their sandboxed service
  identity. Both daily timers are enabled; restored disposable backups pass the test
  suite. The systemd exposure score was `2.9 OK` for both services.
- The application test gate passes PHP/JS/CSS/Bash validation, ShellCheck, 58 unit
  tests, HTTP integration, moderation lifecycle, health alerting and a compressed
  backup/restore smoke test.

## Findings

| ID | Severity | Finding | Evidence / impact | Required remediation |
| --- | --- | --- | --- | --- |
| S-01 | Critical | Web code and secrets share a broad PHP-FPM trust boundary. | The site uses the shared `www-data` pool. Its `.env` and SQLite database are readable by that identity, so compromise of another app in the pool can expose this app's admin hash, secret and pseudonymous visitor identifiers. | Create a dedicated Unix user and FPM pool; make application code and `.env` read-only to that user; allow writes only to the guestbook storage/session paths. |
| S-02 | Critical | The PHP worker can write to the live document root. | The served checkout directory is group-writable by `www-data`. A file-write primitive in any PHP app sharing that identity can become persistent code execution by creating a PHP file here. | Remove web-worker write access from the release tree and parent path; deploy immutable releases; deny PHP execution everywhere except the front controller. |
| S-03 | High | Admin login throttling is session-local and trivially reset. | Clearing the `gbsid` cookie resets the five-attempt counter. An attacker can make unlimited distributed or cookie-rotated password guesses behind the edge request limit. | Store attempt counters server-side by keyed IP hash, add exponential lockouts and emit security events. Keep error responses non-enumerating. |
| S-04 | High | Configuration fails open for the IP-hash secret. | If `APP_SECRET` is absent, the code silently uses the public string `insecure-default`. This makes stored client hashes linkable and predictable. | Refuse startup in production when required secrets are missing, malformed or weak. Validate the admin password hash as well. |
| S-05 | High | There is no reliable production release boundary. | The checked-out `main` branch is the nginx document root, so editing it changes production before tests complete and leaves partial-release windows. | Build in a separate worktree, test, snapshot, switch an atomic `current` symlink, health-check, and roll back automatically on failure. |
| S-06 | Medium | Detection is operational; response rehearsal remains incomplete. | Security/moderation events, five-minute health alerts, Telegram delivery/recovery and an IR runbook are deployed. A formal tabletop and measured delivery SLO are not complete. | Run credential-compromise and data-loss tabletops; record delivery and response times quarterly. |
| S-07 | Medium | Admin sessions lack explicit idle and absolute expiry. | Login rotates the session ID, but admin state persists until PHP garbage collection and there is no server-enforced admin lifetime. PHP strict-session mode is disabled globally. | Enable strict session mode, enforce idle/absolute timeouts, rotate CSRF on privilege changes and fully destroy logout sessions. |
| S-08 | Medium | Input type confusion can create warnings and unintended values. | Array values for `q`, `page`, `author`, `message` or `password` are cast instead of rejected. This can generate log noise or accept the literal value `Array`. | Accept only expected scalar types, use centralized input helpers, and test malformed nested parameters. |
| S-09 | Medium | Database DDL runs on every web request and migration errors are swallowed. | `CREATE`/`ALTER`/index statements execute during bootstrap; every migration exception is treated as "column exists". Disk, permission and corruption errors can be hidden. | Move schema changes to versioned, transactional CLI migrations and keep web requests read/write-data only. |
| S-10 | Medium | Cloudflare JavaScript Detection conflicts with CSP. | The live edge injects an inline challenge script while `script-src` disallows inline code. Browsers will block it, reducing bot signal quality. | Keep `unsafe-inline` forbidden. Use a per-response CSP nonce that Cloudflare can propagate, or intentionally disable injection for this hostname. |
| S-11 | Medium | Core identifier retention is defined; full data-subject workflow remains. | Message identifiers detach after 24 hours, rate limits expire after seven days, security/closed reports after 90 days, and the public notice explains processing. Export/erasure operations and key-rotation procedure are not complete. | Add authenticated export/erasure commands, record legal basis and test purpose-key rotation. |
| S-12 | Low | Reversible moderation and audit are deployed; purge policy remains deferred. | Five states, controlled reasons, a report queue, atomic transitions and trigger-protected append-only audit remove immediate-deletion and repudiation risk. Permanent purge and bulk actions are intentionally absent. | Define purge authorization/retention before adding permanent deletion; keep public reports non-automatic. |
| S-13 | Medium | Local backup automation matches the repository; off-host resilience is absent. | Hardened versioned scripts/timers, age alerts and disposable restores pass, including a production-snapshot migration drill. A host/storage loss can still remove both live data and local backups. | Add an encrypted off-host copy, key recovery procedure and scheduled off-host restore drill. |
| S-14 | Medium | The public JSON feed starts sessions and advertises public caching. | Read-only API requests receive a session cookie and conflicting legacy no-cache headers alongside `Cache-Control: public`. This hurts caching and unnecessarily creates identifiers. | Avoid sessions for truly public reads or mark the endpoint private/no-store; add API versioning, ETags and explicit cache semantics. |
| S-15 | Medium | Self-hosted analytics expands the script trust boundary. | The CSP permits the VPS-hosted analytics origin to execute scripts. The tracker is domain-bound, DNT-aware and excludes query/hash, and the public notice exists; origin compromise still becomes same-page script execution. | Pin/review upgrades, document retention/processor basis and consider serving a verified local tracker asset. |
| S-16 | Low | Application method and canonical-path handling relies on the edge. | Unsupported methods are rejected live by nginx/Cloudflare, but the PHP controller treats every non-POST method as a read; `/index.php/...` also renders successfully. | Enforce `GET`, `HEAD`, and `POST` in PHP, reject path-info, and add canonical redirects/tests. |
| S-17 | Low | Automated CI exists; real-browser accessibility coverage is absent. | Unit, HTTP, malformed-input, backup/restore, health, operational and secret-leakage checks run locally and in GitHub CI. Axe/Lighthouse and screen-reader flows remain manual. | Add pinned browser/a11y automation and branch-protection enforcement after push. |

## Controls verified

- No secret, database or private key is tracked in the current tree or detected in
  repository history by the audit patterns.
- The live `.env` is not Git-tracked and is mode `0640`; the SQLite database passes
  `PRAGMA integrity_check`.
- SQL calls handling user input use prepared statements; rendered message content,
  author names and queries are HTML-escaped.
- POST actions use CSRF tokens and cookies are `Secure`, `HttpOnly`, and
  `SameSite=Strict`; successful login regenerates the session ID.
- Direct origin access is denied, HTTP redirects to HTTPS, TLS is limited to 1.2/1.3,
  and sensitive paths return `403`/`404` through the public hostname.
- The daily systemd backup completed successfully during this audit and creates
  integrity-checked, compressed, mode `0600` snapshots.
- PHP and Bash syntax checks pass; ShellCheck reports no issue in the tracked backup
  script.

## STRIDE threat model

| Category | Primary scenario | Likelihood | Impact | Priority |
| --- | --- | --- | --- | --- |
| Elevation / Tampering | Shared PHP worker writes executable code or reads another site's secrets. | High | Critical | 1 |
| Spoofing | Unlimited cookie-rotated guesses obtain the single admin credential. | High | High | 2 |
| Information disclosure | Shared-pool, backup, analytics or admin compromise exposes messages, identifiers and secrets. | Medium | High | 3 |
| Repudiation | Host-level compromise could alter both data and local audit history despite application append-only triggers. | Low | Medium | 4 |
| Denial of service | Distributed report floods, SQLite contention, search scans or disk exhaustion degrade service. | Medium | Medium | 5 |

## CISO forcing questions

### Blast radius

Before remediation, compromise of the shared FPM identity could expose or modify all
guestbook records, pseudonymous IP hashes, the admin password hash and `APP_SECRET`.
The dedicated identity and filesystem permissions now prevent `www-data` from reading
the secret or database and prevent the runtime from changing releases. Residual host
risk remains: nginx must be able to connect to the dedicated Unix socket, privileged
host compromise bypasses Unix isolation, and unrelated legacy applications still need
their own isolation reviews. The database contained three message records at audit
and deployment time.

A defensible FAIR annual loss expectancy cannot be invented from source code. Record
the following before risk acceptance: incident response hours and hourly cost,
rebuild time, downtime value/hour, legal advice cost, notification population/cost,
and annual rate of each scenario. Then calculate `ALE = event frequency x loss
magnitude` for credential takeover, cross-vhost compromise and data loss.

### Detection

- Current MTTD after the monitoring rollout: about five minutes for public health,
  permission/release drift, SQLite integrity, backup age, disk pressure and five admin
  failures in 15 minutes and ten open abuse reports. Watched systemd failures dispatch
  immediately. The existing Cloudflare bot checks traffic spikes and edge 5xx
  responses every five minutes.
- Remaining alerts: distributed report-rate anomalies below the backlog threshold,
  release-hash attestation and measured notification-delivery SLOs.

### Response

The repository runbook documents containment, credential/key rotation, session
invalidation, evidence preservation, restore, Cloudflare changes, communications and
postmortem ownership. It has not completed a formal human tabletop. Run and record one
credential-compromise and one data-loss exercise before marking this control green.

### Regulatory

GDPR is plausibly in scope because the service handles author-supplied content,
online identifiers and cookies for EU visitors. The controller must document purpose,
legal basis, retention, processors and data-subject handling. A qualifying breach may
require supervisory-authority notification within 72 hours of awareness; legal
assessment remains the controller's responsibility.

### Vendors and supply chain

- Cloudflare: DNS/proxy/WAF/challenge injection, edge telemetry and the existing
  five-minute Telegram alert/report bot.
- Telegram: operational alert delivery; payloads are restricted to non-visitor
  component state and fixed reason codes.
- Analytics service at `analytics.micutu.com`: executable JavaScript and visitor
  telemetry; ownership, software version, retention and DPA need recording.
- VPS, OS packages and certificate automation: hosting and infrastructure access.
- GitHub: source hosting; branch protection, MFA, deploy keys and secret-scanning
  settings were not visible from this host and remain to be verified.

No Composer, npm or CDN runtime packages are present, which materially reduces the
application dependency attack surface.

## Audit limitations

This review did not access Cloudflare/GitHub dashboards, inspect message bodies,
attempt password guessing, restore over the live database or perform a destructive
exploit. Deployment applied the backed-up fourth schema migration after a disposable
restore of the production snapshot proved integrity and compatibility. Passing this
review is not a guarantee of security; repeat it after architecture or data-flow
changes.
