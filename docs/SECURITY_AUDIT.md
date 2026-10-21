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
checks before and after migration. Detection, incident response, privacy disclosure,
off-host backups and full browser/accessibility automation remain incomplete.

## Deployment verification snapshot

- Production serves the tested immutable release through the `current` symlink and
  the dedicated `php-guestbook` FPM pool. The previous release and nginx configuration
  are retained for rollback.
- `www-data` cannot read either production `.env` copy or the SQLite database. The
  runtime identity cannot write a release; storage, sessions and local backups are
  mode `0700` directories owned by `php-guestbook`.
- Versioned migrations ran once and then skipped idempotently. The database reported
  `integrity=ok`, three messages and three applied migrations after cutover.
- Public probes returned `200` for HTML, JSON, RSS, CSS and `security.txt`; sensitive
  paths and path-info returned `404`, unsupported methods returned `405`, and direct
  access through the VPS public address returned `403`.
- Manual one-shot backup and maintenance jobs succeeded under their sandboxed service
  identity. Both daily timers are enabled; restored disposable backups pass the test
  suite. The systemd exposure score was `2.9 OK` for both services.
- The application test gate passes PHP/JS/CSS/Bash validation, ShellCheck, 41 unit
  tests, HTTP integration tests and a compressed backup/restore smoke test.

## Findings

| ID | Severity | Finding | Evidence / impact | Required remediation |
| --- | --- | --- | --- | --- |
| S-01 | Critical | Web code and secrets share a broad PHP-FPM trust boundary. | The site uses the shared `www-data` pool. Its `.env` and SQLite database are readable by that identity, so compromise of another app in the pool can expose this app's admin hash, secret and pseudonymous visitor identifiers. | Create a dedicated Unix user and FPM pool; make application code and `.env` read-only to that user; allow writes only to the guestbook storage/session paths. |
| S-02 | Critical | The PHP worker can write to the live document root. | The served checkout directory is group-writable by `www-data`. A file-write primitive in any PHP app sharing that identity can become persistent code execution by creating a PHP file here. | Remove web-worker write access from the release tree and parent path; deploy immutable releases; deny PHP execution everywhere except the front controller. |
| S-03 | High | Admin login throttling is session-local and trivially reset. | Clearing the `gbsid` cookie resets the five-attempt counter. An attacker can make unlimited distributed or cookie-rotated password guesses behind the edge request limit. | Store attempt counters server-side by keyed IP hash, add exponential lockouts and emit security events. Keep error responses non-enumerating. |
| S-04 | High | Configuration fails open for the IP-hash secret. | If `APP_SECRET` is absent, the code silently uses the public string `insecure-default`. This makes stored client hashes linkable and predictable. | Refuse startup in production when required secrets are missing, malformed or weak. Validate the admin password hash as well. |
| S-05 | High | There is no reliable production release boundary. | The checked-out `main` branch is the nginx document root, so editing it changes production before tests complete and leaves partial-release windows. | Build in a separate worktree, test, snapshot, switch an atomic `current` symlink, health-check, and roll back automatically on failure. |
| S-06 | High | Detection and incident response are not operationalized. | Access/error logs exist, but there are no application security events, alerts, named on-call owner, tested runbook or measurable MTTD. The deploy user cannot currently read the site logs. | Add structured auth/moderation events, alert rules, log rotation/access policy, an IR runbook and a quarterly tabletop. |
| S-07 | Medium | Admin sessions lack explicit idle and absolute expiry. | Login rotates the session ID, but admin state persists until PHP garbage collection and there is no server-enforced admin lifetime. PHP strict-session mode is disabled globally. | Enable strict session mode, enforce idle/absolute timeouts, rotate CSRF on privilege changes and fully destroy logout sessions. |
| S-08 | Medium | Input type confusion can create warnings and unintended values. | Array values for `q`, `page`, `author`, `message` or `password` are cast instead of rejected. This can generate log noise or accept the literal value `Array`. | Accept only expected scalar types, use centralized input helpers, and test malformed nested parameters. |
| S-09 | Medium | Database DDL runs on every web request and migration errors are swallowed. | `CREATE`/`ALTER`/index statements execute during bootstrap; every migration exception is treated as "column exists". Disk, permission and corruption errors can be hidden. | Move schema changes to versioned, transactional CLI migrations and keep web requests read/write-data only. |
| S-10 | Medium | Cloudflare JavaScript Detection conflicts with CSP. | The live edge injects an inline challenge script while `script-src` disallows inline code. Browsers will block it, reducing bot signal quality. | Keep `unsafe-inline` forbidden. Use a per-response CSP nonce that Cloudflare can propagate, or intentionally disable injection for this hostname. |
| S-11 | Medium | Pseudonymous network identifiers have no defined retention. | A stable salted IP hash is retained on each message indefinitely. There is no privacy notice, erasure workflow or scheduled minimisation job. | Document purpose/legal basis, disclose analytics and identifiers, rotate the hashing purpose key, and erase rate-limit identifiers after a short defined window. |
| S-12 | Medium | Moderation has no audit trail or recovery. | Admin deletion is immediate and permanent, with no actor/event record, reason, tombstone or undo path. This creates tampering and repudiation risk. | Add a moderation queue, soft deletion, append-only audit events and restore/purge actions. |
| S-13 | Medium | Repository backup automation has drifted from production. | The tracked script points to old home-directory paths; production uses a separate hardened script and timer. The live job succeeds, but the repository cannot recreate it. | Bring the hardened script and systemd units under version control; add an automated restore/integrity drill and encrypted off-host copy. |
| S-14 | Medium | The public JSON feed starts sessions and advertises public caching. | Read-only API requests receive a session cookie and conflicting legacy no-cache headers alongside `Cache-Control: public`. This hurts caching and unnecessarily creates identifiers. | Avoid sessions for truly public reads or mark the endpoint private/no-store; add API versioning, ETags and explicit cache semantics. |
| S-15 | Medium | External analytics expands the script and privacy trust boundary. | The CSP permits an analytics origin to execute scripts; compromise there becomes same-page script execution. No repository privacy disclosure or vendor register exists. | Make analytics opt-in/configurable, self-host a pinned asset where possible, document retention/DPA, and minimize collected fields. |
| S-16 | Low | Application method and canonical-path handling relies on the edge. | Unsupported methods are rejected live by nginx/Cloudflare, but the PHP controller treats every non-POST method as a read; `/index.php/...` also renders successfully. | Enforce `GET`, `HEAD`, and `POST` in PHP, reject path-info, and add canonical redirects/tests. |
| S-17 | Low | Automated verification is absent. | There are no unit, integration, browser, accessibility, security-regression or CI checks. Only manual syntax checks are available. | Add a dependency-free test harness first, then CI linting, ShellCheck, secret scanning and browser/a11y checks. |

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
| Repudiation | Admin deletion or auth abuse cannot be reconstructed from an application audit trail. | High | Medium | 4 |
| Denial of service | SQLite write contention, search scans, disk exhaustion or broad per-IP limits degrade service. | Medium | Medium | 5 |

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
  failures in 15 minutes. Watched systemd failures dispatch immediately. The existing
  Cloudflare bot checks traffic spikes and edge 5xx responses every five minutes.
- Remaining alerts: moderation anomalies, release-hash attestation and measured
  notification-delivery SLOs.

### Response

No repository incident-response runbook or tabletop evidence exists. Before rollout,
document containment, credential/key rotation, session invalidation, evidence
preservation, restore, Cloudflare rule changes, stakeholder decisions and postmortem
ownership. Run one credential-compromise and one data-loss tabletop.

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
exploit. Deployment did apply backed-up schema migrations and the retention job
removed one expired pseudonymous message identifier. Passing this review is not a
guarantee of security; repeat it after architecture or data-flow changes.
