# Security audit

**Date:** 2026-10-24
**Scope:** application source, Git history, live response behaviour, nginx/PHP-FPM
configuration, SQLite metadata and backup controls. Message bodies and secret values
were not inspected.

## Executive verdict

**HIGH-PRIORITY REMEDIATION DEPLOYED; RESIDUAL OPERATIONAL RISK REMAINS.** S-01
through S-05 were remediated on 2026-10-21 using an immutable release, a dedicated
Unix/FPM identity, fail-closed configuration, persistent throttling and an atomic
cutover. The production database remained at three messages and passed integrity
checks before and after migration. Detection, a privacy disclosure, reversible
moderation, category-only abuse reporting, explainable submission-risk review,
indexed search and an incident-response runbook are now deployed. R2 holds verified encrypted backups, while Google Drive
redundancy is quota-degraded. Real-browser Axe, reflow and keyboard checks now gate
CI. First-party social preview metadata and single-message curation add no new vendor
or private data flow. An active Telegram bot token was also found in local agent histories; file access
has been restricted to the owner, but credential rotation remains urgent. A formal
tabletop, data-subject operations, Lighthouse and manual screen-reader sign-off remain
incomplete.

## Deployment verification snapshot

- Production serves the tested immutable release through the `current` symlink and
  the dedicated `php-guestbook` FPM pool. The previous release and nginx configuration
  are retained for rollback.
- `www-data` cannot read either production `.env` copy or the SQLite database. The
  runtime identity cannot write a release; storage, sessions and local backups are
  mode `0700` directories owned by `php-guestbook`.
- Versioned migrations ran once and then skipped idempotently. The database reported
  `integrity=ok`, zero foreign-key violations, three published messages, three indexed
  FTS rows and seven applied migrations after the curation cutover. The rank-1 external
  content/index integrity check passed.
- Public probes returned `200` for HTML, JSON, RSS, CSS and `security.txt`; sensitive
  paths and path-info returned `404`, unsupported methods returned `405`, and direct
  access through the VPS public address returned `403`.
- Manual one-shot backup, isolated restore and maintenance jobs succeeded under their
  sandboxed service identity. Daily jobs and the weekly restore timer are enabled;
  restored disposable backups pass integrity, foreign-key, migration and schema
  checks. The restore unit's systemd exposure score was `0.8 SAFE` at rollout.
- The application test gate passes PHP/JS/CSS/Bash validation, ShellCheck, 108 unit
  tests, HTTP/FTS integration, moderation lifecycle, health alerting and compressed
  backup/restore rejection tests. A separate Chromium job checks five viewport widths,
  primary 44px touch targets, keyboard focus, dynamic post/search/moderation flows,
  light/dark WCAG A/AA Axe rules and JavaScript/network errors against disposable state.
- The local/CI supply-chain gate rejects sensitive tracked filenames, high-confidence
  credential signatures without logging their values, mutable Action references and
  npm packages lacking exact versions, HTTPS resolution, SHA-512 integrity or license
  metadata. Adversarial fixtures prove the blocking cases.

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
| S-13 | Medium | Encrypted off-host copying is deployed, but provider redundancy is degraded. | The first production run age-encrypted 24 snapshots and verified all 24 at R2 with zero differences. Google Drive rejected every upload because its storage quota is exceeded; the job, health probe and pager correctly remain failed/degraded. Jobs never send plaintext or delete remote history. One recovery recipient, shared-scope provider credentials and the absence of a downloaded-artifact human drill remain failure modes. | Restore Drive capacity or replace it with an independent provider; add a second offline recipient, restrict credentials per namespace, enforce documented remote retention and execute a quarterly downloaded-artifact drill. |
| S-14 | Low | API v1 is stateless and cache-consistent; the legacy JSON shape remains temporarily. | `/api/v1/messages` creates no session/cookie, exposes published columns only, uses signed indexed cursors, strong ETags and explicit public cache semantics. The old query-format feed is private/no-store and marked deprecated. | Migrate remaining consumers, measure usage, then remove the legacy JSON format in a separately announced release. |
| S-15 | Low | Analytics still receives bounded telemetry, but no longer expands the remote script trust boundary. | A reviewed Umami tracker copy is versioned locally and pinned by SHA-256 in tests and the live health probe. CSP removes the analytics origin from `script-src` while retaining only `connect-src`; the tracker remains domain-bound, DNT-aware and excludes query/hash. Backend compromise can disrupt or receive the documented aggregate payload but cannot replace executable page code. | Review upstream changes before deliberately replacing the local asset and pinned digest; document analytics retention/processor basis and alert on digest drift. |
| S-16 | Low | Application method and canonical-path handling relies on the edge. | Unsupported methods are rejected live by nginx/Cloudflare, but the PHP controller treats every non-POST method as a read; `/index.php/...` also renders successfully. | Enforce `GET`, `HEAD`, and `POST` in PHP, reject path-info, and add canonical redirects/tests. |
| S-17 | Low | Real-browser CI is implemented; human accessibility and repository enforcement remain. | Exact-version Playwright/Axe checks run against isolated state at 320–1440px, enforce WCAG A/AA rules in both themes, keyboard focus, touch targets and zero overflow. Automated review caught and fixed contrast and accessible-name defects. Lighthouse, 200% zoom, screen-reader sign-off and confirmation that the job is required by branch protection remain open. | Complete and record the manual matrix, add Lighthouse budgets, then require both CI jobs in GitHub branch protection after push. |
| S-18 | Low | Indexed search removes routine full scans; a dedicated edge limit remains absent. | FTS5 uses generated prepared prefix terms capped at eight, indexed Unicode tokenization, bounded pagination and a published-only join. Hostile FTS grammar, trigger synchronization, query plans and restored-index integrity are tested. Distributed query floods can still create SQLite/CPU contention. | Add a search-specific Cloudflare rate limit and latency/SQLite-busy alert without logging visitor query text. |
| S-19 | Medium | Explainable review catches common automation patterns; distributed and novel abuse remains. | Fixed Unicode/link/name/repetition/timing signals plus 24-hour keyed duplicate detection queue at score 40 without auto-deletion. Public surfaces exclude pending content, admin release is reversible and a ten-item pending backlog alerts. Content variation or distributed clients can evade application-local signals, while false positives wait for human review. | Add separate edge limits for post/search/API, measure false-positive and queue latency without message telemetry, then consider accessible risk-triggered Turnstile and expiring allow/block entries. |
| S-20 | Low | Public discovery is explicit; search-console governance remains manual. | Sitemap, UTC calendar archives, RSS, JSON Feed and structured metadata select published records only; search/admin views are noindex, clean message/archive paths reject mixed parameters, empty or unavailable resources return 404, outputs are context-safe and production health probes the discovery documents, one archive and a live permalink. Sharing requires a visitor click, exposes only a canonical URL plus generic title and loads no social SDK. Search-engine recrawl/removal, ownership alerts and erasure workflows are not automated. | Verify both major search consoles, monitor coverage/rich-result errors, document cache purge and URL removal, and complete the data-subject workflow. |
| S-21 | High | The active Telegram bot token exists in local agent-session history. | A fingerprint-only comparison found the current token in three local history files. Two Codex histories were mode `0664`; they and the containing session directories are now owner-only (`0600`/`0700`). The secret remains valid and present in retained history, so permission hardening limits local exposure but does not revoke copies. The token was not added to this repository. | Regenerate the token through BotFather, atomically update the root-owned bot credential, restart and verify the allow-listed chat, then redact or securely remove historical copies under an approved retention plan. Enforce owner-only creation permissions for all future agent transcripts. |
| S-22 | Low | Local drafts improve resilience but can leave message text on a shared device. | Draft storage is off by default, explicitly device-scoped in the UI, bounded to server field limits, expires after 24 hours and is deleted after successful submission or on request. The text-only preview uses DOM `textContent`; browser tests prove markup does not create elements or execute. A user with access to the same unlocked browser profile can still read an opted-in draft. | Keep opt-in off by default, do not sync drafts server-side, retain the clear control and re-evaluate before adding accounts, cloud sync or longer retention. |
| S-23 | Low | Featured-message curation adds an admin-controlled ranking signal. | A stolen admin session could promote one already-public message, but cannot feature moderated content. Same-origin CSRF-protected POST, an expiring admin session, a one-row database constraint, append-only feature/unfeature audit and automatic clearing on moderation bound the impact. | Monitor unexpected feature audit events, rotate the admin credential/session on compromise and retain the one-message/public-only invariant. |

## CISO review: featured-message curation

**Date:** 2026-10-24

- **Threat model:** elevation/spoofing of the admin is the highest-impact scenario;
  tampering through concurrent replacement and repudiation of a curation change follow.
  Existing authentication plus origin/CSRF checks, `BEGIN IMMEDIATE`, a partial unique
  index, rollback-compatible cleanup triggers and append-only feature/unfeature events
  cover those paths.
- **Blast radius:** the attacker can reorder one already-public message on the homepage.
  The feature cannot publish or expose pending, hidden, spam or deleted content and
  does not reveal identifiers, reports or admin records. No defensible FAIR annual-loss
  estimate exists without credential-compromise frequency and response-cost inputs.
- **Detection:** blocked and successful actions create fixed-code security events;
  each successful replacement creates immutable admin events. CI covers anonymous
  rejection, atomic replacement, moderation cleanup, API projection, Axe and mobile
  reflow. The five-minute production probe enforces the database invariant.
- **Response:** remove the feature, invalidate the admin session, rotate the admin
  credential when compromise is suspected, preserve the audit rows and use the normal
  immutable-release rollback. Restoring a moderated message does not re-enable its old
  featured state.
- **Regulatory:** the only new public field is a boolean about already-public content.
  GDPR can still apply to the underlying author/content; a qualifying breach retains
  the controller's 72-hour assessment window.
- **Vendors and supply chain:** no package, vendor, cookie, remote call, processor or
  credential is added.

**Verdict:** 🟢 SHIP. Residual risk is limited to presentation of one public record and
is reversible, constrained and attributable.

## CISO review: local draft and preview

**Date:** 2026-10-24

- **Threat model:** the top risks are information disclosure to another user of the
  same browser profile (medium likelihood/low impact), analytics-script substitution
  reading draft storage (low/high), and DOM injection through preview content
  (low/high). Opt-in storage, strict length/type/expiry validation, `textContent`,
  explicit deletion, a versioned/digest-pinned local tracker and removal of remote
  analytics `script-src` permission mitigate them.
- **Blast radius:** compromise is limited to the single opted-in name/message in one
  browser origin; no server database, other visitor, credential, network identifier
  or moderation record is reachable through this feature. The analytics backend can
  no longer replace the executable tracker. A FAIR dollar estimate is not defensible
  without incident-frequency and response-cost inputs.
- **Detection:** seven Chromium scenarios now detect persistence, 24-hour expiry,
  deletion, post-submit clearing, XSS regression, accessibility and mobile overflow
  before release. Client storage is intentionally not telemetered, so server-side MTTD
  for a browser-specific failure is user-report dependent.
- **Response:** disable by reverting the feature commit and deploying the preceding
  immutable release. Browser-held drafts cannot be remotely erased; the visible
  delete control and 24-hour expiry are therefore mandatory containment controls.
- **Regulatory:** an opted-in draft may contain personal data, but remains on the data
  subject's device and is not received by the operator before form submission. Any
  qualifying server-side breach retains the GDPR 72-hour assessment window.
- **Vendors and supply chain:** no new vendor, API, cookie, permission or network
  recipient is added. The existing MIT-licensed Umami tracker is now a reviewed local
  asset with its license notice and pinned digest; upstream updates require an explicit
  source, hash, test and CSP review.

**Verdict:** 🟢 SHIP. Residual shared-device exposure is clearly disclosed and under
the visitor's control.

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
| Denial of service | Distributed report/search floods, SQLite contention or disk exhaustion degrade service. | Medium | Medium | 5 |

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
  permission/release drift, SQLite integrity, backup/off-host/restore age, disk pressure and five admin
  failures in 15 minutes, ten open abuse reports or ten pending messages. Watched systemd failures dispatch
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
- Analytics service at `analytics.micutu.com`: bounded visitor telemetry endpoint;
  its reviewed tracker is versioned locally and the remote origin has no script
  execution permission. Ownership, software version, retention and DPA need recording.
- VPS, OS packages and certificate automation: hosting and infrastructure access.
- GitHub: source hosting; branch protection, MFA, deploy keys and secret-scanning
  settings were not visible from this host and remain to be verified.

No Composer, npm or CDN **runtime** packages are present, which materially reduces the
application dependency attack surface. Browser-test packages are development-only,
exactly versioned with integrity hashes, installed with lifecycle scripts disabled,
audited in a secretless read-only CI job and covered by dependency review/Dependabot.

## Audit limitations

This review did not access Cloudflare/GitHub dashboards, inspect message bodies,
attempt password guessing, restore over the live database or perform a destructive
exploit. Deployment applies additive schema migrations only after a disposable
restore of the production snapshot proves SQLite/FTS integrity and compatibility. Passing this
review is not a guarantee of security; repeat it after architecture or data-flow
changes.
