# Security audit

**Date:** 2026-10-27
**Scope:** application source, Git history, live response behaviour, nginx/PHP-FPM
configuration, SQLite metadata and backup controls. Message bodies and secret values
were not inspected.

## Executive verdict

**HIGH-PRIORITY REMEDIATION DEPLOYED; RESIDUAL OPERATIONAL RISK REMAINS.** S-01
through S-05 were remediated on 2026-10-21 using an immutable release, a dedicated
Unix/FPM identity, fail-closed configuration, persistent throttling and an atomic
cutover. The production database remained at three messages and passed integrity
checks before and after migration. Detection, a privacy disclosure, reversible
moderation, category-only abuse reporting, explainable submission-risk review, an
indexed private moderation workspace with privacy-minimal operational aggregates,
public indexed search, a sessionless central calendar archive, published-only
message/month navigation, private author export/withdrawal and an incident-response runbook are now
deployed. R2 and Google Drive both hold verified encrypted backups; the Drive remote
still needs its own OAuth client before rclone retires the shared client. Real-browser Axe, reflow and keyboard checks now gate
CI. First-party social preview metadata and single-message curation add no new vendor
or private data flow. An active Telegram bot token was also found in local agent histories; file access
has been restricted to the owner, but credential rotation remains urgent. A formal
tabletop, complete data-subject operations, Lighthouse and manual screen-reader sign-off remain
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
  FTS rows and eight applied migrations after the ownership cutover. The rank-1 external
  content/index integrity check passed.
- Public probes returned `200` for HTML, JSON, RSS, CSS and `security.txt`; sensitive
  paths and path-info returned `404`, unsupported methods returned `405`, and direct
  access through the VPS public address returned `403`.
- Manual one-shot backup, isolated restore and maintenance jobs succeeded under their
  sandboxed service identity. Daily jobs and the weekly restore timer are enabled;
  restored disposable backups pass integrity, foreign-key, migration and schema
  checks. The restore unit's systemd exposure score was `0.8 SAFE` at rollout.
- The application test gate passes PHP/JS/CSS/Bash validation, ShellCheck, 168 unit
  tests, HTTP/FTS integration, moderation lifecycle, health alerting and compressed
  backup/restore rejection tests. A separate Chromium job checks five viewport widths,
  primary 44px touch targets, keyboard focus, nine post/search/moderation/owner flows,
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
| S-07 | Low | Admin session lifecycle is bounded; clean theft remains hard to distinguish from the operator. | Strict mode rejects uninitialized IDs. Login, 15-minute renewal, expiry and logout replace the session ID and destroy its predecessor; CSRF rotates at both privilege transitions. Admin privilege expires after 30 idle minutes or 12 total hours, and malformed, negative, reversed or future timestamps fail closed. Unit boundary cases and real-cookie HTTP tests cover fixation, login, expiry and logout. A valid cookie stolen between rotations can still act until expiry and may resemble legitimate use. | Keep TLS/edge and browser hygiene strong, alert on impossible admin activity where privacy-safe, invalidate all sessions during credential response, and complete the credential-compromise tabletop. |
| S-08 | Medium | Input type confusion can create warnings and unintended values. | Array values for `q`, `page`, `author`, `message` or `password` are cast instead of rejected. This can generate log noise or accept the literal value `Array`. | Accept only expected scalar types, use centralized input helpers, and test malformed nested parameters. |
| S-09 | Medium | Database DDL runs on every web request and migration errors are swallowed. | `CREATE`/`ALTER`/index statements execute during bootstrap; every migration exception is treated as "column exists". Disk, permission and corruption errors can be hidden. | Move schema changes to versioned, transactional CLI migrations and keep web requests read/write-data only. |
| S-10 | Medium | Cloudflare JavaScript Detection conflicts with CSP. | The live edge injects an inline challenge script while `script-src` disallows inline code. Browsers will block it, reducing bot signal quality. | Keep `unsafe-inline` forbidden. Use a per-response CSP nonce that Cloudflare can propagate, or intentionally disable injection for this hostname. |
| S-11 | Medium | Core retention and self-service owner controls are documented; the full data-subject workflow remains. | The sessionless Privacy page distinguishes public/indexed content, a minimal one-message copy, live soft withdrawal, application retention, backups and third-party copies. Future posters get a private capability that can export or irreversibly hide one message. A dedicated privacy contact, complete statutory access/erasure/objection operations and key-rotation procedure are not complete. | Publish a controller contact and complete authenticated rights handling and purge policy; record legal basis and test purpose-key rotation. |
| S-12 | Low | Reversible moderation and audit are deployed; purge policy remains deferred. | Five states, controlled reasons, a report queue, atomic individual/bulk transitions and trigger-protected append-only audit remove immediate-deletion and repudiation risk. Moderator actions are reversible; author withdrawals are terminal and database-enforced. Permanent purge remains intentionally absent. | Define purge authorization/retention before adding permanent deletion; keep public reports non-automatic. |
| S-13 | Medium | Encrypted off-host redundancy is healthy; credential and recovery independence remain incomplete. | The post-ownership run age-encrypted 39 snapshots and verified all 39 at both R2 and Google Drive with zero differences. The health probe recovered automatically after the earlier Drive quota failure. Jobs never send plaintext or delete remote history. Rclone warns that the Drive remote still uses its retiring shared OAuth client; one recovery recipient, shared-scope provider credentials and the absence of a downloaded-artifact human drill remain failure modes. | Provision a dedicated least-privilege Google OAuth client before the shared client is retired; add a second offline recipient, restrict credentials per namespace, enforce documented remote retention and execute a quarterly downloaded-artifact drill. |
| S-14 | Low | API v1 is stateless and cache-consistent; the legacy JSON shape remains temporarily. | `/api/v1/messages` creates no session/cookie, exposes published columns only, uses signed indexed cursors, strong ETags and explicit public cache semantics. The old query-format feed is private/no-store and marked deprecated. | Migrate remaining consumers, measure usage, then remove the legacy JSON format in a separately announced release. |
| S-15 | Low | Analytics still receives bounded telemetry, but no longer expands the remote script trust boundary. | A reviewed Umami tracker copy is versioned locally and pinned by SHA-256 in tests and the live health probe. CSP removes the analytics origin from `script-src` while retaining only `connect-src`; the tracker remains domain-bound, DNT-aware and excludes query/hash. Backend compromise can disrupt or receive the documented aggregate payload but cannot replace executable page code. | Review upstream changes before deliberately replacing the local asset and pinned digest; document analytics retention/processor basis and alert on digest drift. |
| S-16 | Low | Application method and canonical-path handling relies on the edge. | Unsupported methods are rejected live by nginx/Cloudflare, but the PHP controller treats every non-POST method as a read; `/index.php/...` also renders successfully. | Enforce `GET`, `HEAD`, and `POST` in PHP, reject path-info, and add canonical redirects/tests. |
| S-17 | Low | Real-browser CI is implemented; human accessibility and repository enforcement remain. | Exact-version Playwright/Axe checks run against isolated state at 320–1440px, enforce WCAG A/AA rules in both themes, keyboard focus, touch targets and zero overflow. Automated review caught and fixed contrast and accessible-name defects. Lighthouse, 200% zoom, screen-reader sign-off and confirmation that the job is required by branch protection remain open. | Complete and record the manual matrix, add Lighthouse budgets, then require both CI jobs in GitHub branch protection after push. |
| S-18 | Low | Indexed search removes routine full scans; a dedicated edge limit remains absent. | FTS5 uses generated prepared prefix terms capped at eight, indexed Unicode tokenization, bounded pagination and a published-only join. Hostile FTS grammar, trigger synchronization, query plans and restored-index integrity are tested. Distributed query floods can still create SQLite/CPU contention. | Add a search-specific Cloudflare rate limit and latency/SQLite-busy alert without logging visitor query text. |
| S-19 | Medium | Explainable review catches common automation patterns; distributed and novel abuse remains. | Fixed Unicode/link/name/repetition/timing signals plus 24-hour keyed duplicate detection queue at score 40 without auto-deletion. Public surfaces exclude pending content, admin release is reversible and a ten-item pending backlog alerts. Content variation or distributed clients can evade application-local signals, while false positives wait for human review. | Add separate edge limits for post/search/API, measure false-positive and queue latency without message telemetry, then consider accessible risk-triggered Turnstile and expiring allow/block entries. |
| S-20 | Low | Public discovery and trust pages are explicit; search-console governance remains manual. | Sitemap, a bounded sessionless `/archive` index, UTC monthly archives, RSS, JSON Feed, About/Privacy/Moderation pages, permalink archive breadcrumbs and published-only adjacent message/month links expose only intended public documents; search/admin views are noindex, clean paths reject mixed parameters, empty or unavailable resources return 404, and production health probes discovery plus the Privacy contract. Sharing requires a visitor click, exposes only a canonical URL plus generic title and loads no social SDK. Search-engine recrawl/removal, ownership alerts and erasure workflows are not automated. | Verify both major search consoles, monitor coverage/rich-result errors, document cache purge and URL removal, and complete the data-subject workflow. |
| S-21 | High | The active Telegram bot token exists in local agent-session history. | A fingerprint-only comparison found the current token in three local history files. Two Codex histories were mode `0664`; they and the containing session directories are now owner-only (`0600`/`0700`). The secret remains valid and present in retained history, so permission hardening limits local exposure but does not revoke copies. The token was not added to this repository. | Regenerate the token through BotFather, atomically update the root-owned bot credential, restart and verify the allow-listed chat, then redact or securely remove historical copies under an approved retention plan. Enforce owner-only creation permissions for all future agent transcripts. |
| S-22 | Low | Local drafts improve resilience but can leave message text on a shared device. | Draft storage is off by default, explicitly device-scoped in the UI, bounded to server field limits, expires after 24 hours and is deleted after successful submission or on request. The text-only preview uses DOM `textContent`; browser tests prove markup does not create elements or execute. A user with access to the same unlocked browser profile can still read an opted-in draft. | Keep opt-in off by default, do not sync drafts server-side, retain the clear control and re-evaluate before adding accounts, cloud sync or longer retention. |
| S-23 | Low | Featured-message curation adds an admin-controlled ranking signal. | A stolen admin session could promote one already-public message, but cannot feature moderated content. Same-origin CSRF-protected POST, an expiring admin session, a one-row database constraint, append-only feature/unfeature audit and automatic clearing on moderation bound the impact. | Monitor unexpected feature audit events, rotate the admin credential/session on compromise and retain the one-message/public-only invariant. |
| S-24 | Medium | An owner capability can export or irreversibly hide one future message but is not a complete statutory rights channel. | A 256-bit capability is held in an HttpOnly, Secure-in-production, SameSite Strict cookie scoped to one private path; only its HMAC is stored. Export and withdrawal need session plus capability CSRF and are audited. Withdrawal destroys the token, clears temporary identifiers and cannot be republished. Theft of a valid browser cookie or full same-origin execution can disclose and/or withdraw that one message, while backups and soft-deleted text remain. | Run the owner-token abuse tabletop, publish the dedicated privacy contact and authenticated statutory-rights procedure, and assess successful-action anomaly detection without collecting new identity data. |
| S-25 | Low | The admin can now find the complete moderation set without exposing private discovery publicly. | Authenticated search/filter/sort/pagination replaces the fixed latest-30 view. Generated FTS terms, fixed SQL fragments, bounded pages and a minimal projection limit injection, load and identifier exposure. Admin responses are no-store/noindex, same-origin referrer only and analytics-free; anonymous `admin_*` parameters fail closed. A valid stolen session can still read non-public content without an automatic read anomaly alert. | Preserve the private response and minimal-projection contracts, consider privacy-safe read anomaly detection, and include private discovery in the credential-compromise tabletop. |
| S-26 | Low | Operational aggregates improve moderator triage without creating a new telemetry stream, but generic exceptions are not proactive detection. | The authenticated workspace shows queue/report age, high-risk pending work, 24-hour failure/blocked groups and active throttles. Aggregation occurs before projection and excludes subject hashes, related identifiers, names and content. Specific login, owner-capability and backlog thresholds still alert; all other groups require an operator to open the workspace, so MTTD is unknown. | Preserve the identifier-free query contract, define evidence-based alert rules only where signal quality supports them, and exercise dashboard triage in the credential-compromise tabletop. |
| S-27 | Low | Bounded bulk moderation reduces repetitive triage without adding a mass-publication or purge path. | An authenticated, same-origin, CSRF-protected request carries 1–20 unique canonical IDs. A locked preflight and one transaction make the batch all-or-nothing; owner-withdrawn or unchanged rows reject it. Only pending/hidden/spam/soft-delete targets exist, each changed row is append-only audited, and the aggregate security event carries no IDs. A stolen valid admin session can repeat bounded requests and suppress every eligible message before expiry. | Preserve the no-bulk-publish/no-purge boundary, review unexpected audit bursts, invalidate sessions on suspected compromise and complete a measured credential-takeover tabletop. |
| S-28 | Medium | Self-service export is narrowly projected, but a stolen owner capability becomes a confidentiality path. | The protected attachment contains only one message number, submitted name/text, submission time and current state. It excludes network hashes, fingerprints, risk/review metadata, moderation reasons and capability material. Same-origin POST, two CSRF checks, no-store headers, JSON attachment handling, audit/security events and a shared owner-action alert constrain the path. A valid stolen cookie can still retrieve that message without a distinct real-time alert. | Preserve the field allow-list and attachment/no-store contract, investigate unexpected export audits, invalidate exposed digests after a snapshot and complete the owner-capability tabletop. |
| S-29 | Low | Sequential permalink navigation improves crawlability without expanding the intended public dataset. | One indexed query projects at most two IDs with an explicit published-state predicate, stable timestamp/ID ordering and fixed numeric paths. Hidden states are excluded and target routes independently return 404 when unavailable; a future predicate regression could still reveal a moderated numeric ID even though it would not reveal that message body. Production health checks rendered neighbour IDs against the database. | Preserve the published-only query and independent 404 boundary, monitor Search Console coverage and include permalink/cache removal in the disclosure tabletop. |
| S-30 | Low | Adjacent-month navigation links the public archive sequence without exposing message fields. | One prepared indexed query projects at most two validated `YYYY/MM` keys, requires published rows in the current and target months and skips moderated-only gaps. Destination routes independently return 404 when empty, while production health checks every rendered month against SQLite. A future predicate regression could reveal that a moderated-only month exists, but not its count, author or content. | Preserve the published-only query and independent 404 boundary, monitor Search Console coverage and include archive-link/cache removal in the disclosure tabletop. |

## CISO review: moderation operational overview

**Date:** 2026-10-26

**Verdict:** 🟡 MITIGATE THEN SHIP — the read-only view materially improves private
triage and adds no data flow, but it must not be mistaken for proactive detection or
an immutable incident record.

### 1. Threat model (STRIDE top three)

1. **Information disclosure (low likelihood, high impact):** an implementation that
   projected limiter or event rows could expose stable keyed subject identifiers or
   connect activity to a message. Dedicated aggregate queries select only fixed event
   type/outcome, scope, count and time fields; tests inject hashes and related IDs and
   prove they never leave the helper or rendered HTML. The existing authenticated,
   no-store/noindex, same-origin-referrer and analytics-free boundary remains intact.
2. **Denial of service (medium likelihood, medium impact):** an attacker can generate
   noisy failure groups or make the admin page repeatedly aggregate retained rows.
   The view uses a fixed 24-hour window, two bounded 20-group queries, existing
   seven/90-day retention and authenticated access. Rate limits and existing health
   thresholds constrain the highest-value abuse paths, but distributed noise remains.
3. **Tampering/repudiation (low likelihood, medium impact):** a snapshot can be
   misread as complete evidence, while `security_events` is retention-pruned and not
   trigger-immutable. The UI and monitoring documentation explicitly classify it as
   triage; journald and append-only admin audit remain the response records.

### 2. Blast radius and annual-loss input

Compromise of this view alone reveals queue/report counts and age, fixed security
event names/outcomes/counts/last-seen times, and throttle scope/counts. It does not
reveal subject hashes, raw addresses, names, content, related message IDs, capability
digests, secrets or a new dataset. A stolen admin session already has broader access
to all guestbook messages; production contains three records at review time. No FAIR
ALE is invented without attack frequency, review/response labour, downtime value,
notification population/cost and legal-cost inputs.

### 3. Detection and MTTD

Existing admin-login, invalid-owner-capability and queue/report thresholds page on the next
five-minute health cycle. CI detects projection regressions, window-boundary errors,
future/old/success event inclusion, rendered hash leakage, mobile overflow and Axe
issues before release. Other grouped exceptions create no new alert and become
visible only on authenticated review, so their honest MTTD is unknown.

### 4. Response, runbook and exercise

The incident-response runbook covers session invalidation, credential rotation,
evidence preservation, journal/audit review, rollback and notification assessment.
The automated suite rehearses the dashboard with mixed event classes and active or
expired throttles. A human credential-compromise exercise using this triage view has
not been recorded; that operational gap prevents a green verdict.

### 5. Regulatory window

The aggregate view is designed not to receive personal data, but the underlying
security tables contain keyed online identifiers and the broader admin workspace can
show names/content. Suspected unauthorized access still requires breach assessment;
a qualifying GDPR incident retains the supervisory-authority notification window of
up to 72 hours. Jurisdiction-specific obligations require operator/legal assessment.

### 6. Vendors and supply chain

No package, migration, API, cookie, credential, vendor, processor or outbound request
is added. The feature reuses first-party PHP and SQLite. Existing Cloudflare, VPS,
self-hosted analytics and Telegram boundaries do not change; analytics and Telegram
receive none of these aggregates.

## CISO review: private moderation discovery

**Date:** 2026-10-26

**Verdict:** 🟡 MITIGATE THEN SHIP — the feature closes a real review blind spot with
bounded, tested controls; clean reads through a stolen valid session still have
unknown MTTD and the human incident exercise remains outstanding.

### 1. Threat model (STRIDE top three)

1. **Information disclosure (medium likelihood, high impact):** a shared cache,
   crawler, referrer or analytics request could disclose non-public message content or
   an operator query. Authentication, `private, no-store`,
   `noindex,nofollow,noarchive`, same-origin referrers, an analytics-free response and
   a projection that omits network hashes, ownership digests and fingerprints contain
   those paths.
2. **Injection/tampering (low likelihood, high impact):** crafted FTS or sort/state
   input could alter the query. Search text becomes capped generated quoted terms and
   a bound parameter; state and order clauses come only from fixed maps. Scalar,
   length, value and page validation fails closed and is covered by unit and HTTP
   tests.
3. **Denial of service (low likelihood, medium impact):** expensive terms or deep
   offsets could consume SQLite/CPU. Only an authenticated admin can reach the query;
   input is capped at 100 characters/eight indexed terms, result pages contain ten
   rows, and an out-of-range page is rejected after the bounded count. A stolen admin
   session can still generate legitimate-looking query load until expiry.

### 2. Blast radius and annual-loss input

A compromised admin session can read the author, text, state and moderation/risk
metadata for every public and non-public guestbook message. At review time production
contains three messages. The workspace does not expose keyed network identifiers,
ownership capability hashes, content fingerprints, application secrets, source code
or other services. No monetary ALE is invented: calculation needs compromise
frequency, record growth, operator response time/rate, notification population/cost
and legal impact.

### 3. Detection and MTTD

CI catches anonymous exposure, cache/robot/referrer regressions, analytics inclusion,
malformed filters, projection drift and browser reflow before deployment. Successful
login and mutations remain audited, but query text is intentionally absent from logs,
analytics and alerts. The five-minute production health probe does not authenticate
or exercise this privileged read route. Therefore a clean read using a stolen valid
session has no automatic alert and its honest MTTD is unknown.

### 4. Response, runbook and exercise

The incident-response runbook covers credential rotation, global session
invalidation, evidence preservation, audit review, immutable rollback and edge-cache
purge. Automated tests rehearse confidentiality headers and anonymous rejection. A
human credential-compromise/private-discovery tabletop with measured containment and
notification decisions has not been completed, preventing a green verdict.

### 5. Regulatory window

Non-public author names and content can be personal data. Suspected unauthorized
access requires a documented breach assessment, and a qualifying incident retains
GDPR's supervisory-authority notification window of up to 72 hours. The workspace
does not complete the still-outstanding statutory access/erasure/objection process.

### 6. Vendors and supply chain

This feature adds no package, migration, cookie, credential, subprocess, vendor,
processor or external request. It reuses first-party PHP and the existing SQLite FTS5
index. Self-hosted analytics is explicitly excluded from authenticated admin pages;
Cloudflare and the VPS remain the existing transport/hosting boundary.

## CISO review: admin session lifecycle

**Date:** 2026-10-26

**Verdict:** 🟡 MITIGATE THEN SHIP — the application control is fail-closed and
regression-tested; stolen-session anomaly detection and the human credential drill
remain operational gaps.

### 1. Threat model (STRIDE top three)

1. **Spoofing/elevation (low likelihood, high impact):** theft of a live `gbsid`
   could grant moderator privilege. TLS, `Secure`, `HttpOnly`, `SameSite=Strict`,
   same-origin mutation checks, CSRF, a 30-minute idle limit, a 12-hour absolute
   limit and 15-minute ID renewal reduce exposure; XSS, browser or edge compromise
   can still bypass several layers together.
2. **Session fixation/replay (low likelihood, high impact):** an attacker tries to
   choose or reuse an identifier across login, expiry or logout. PHP strict mode
   rejects unknown IDs, every privilege boundary regenerates the ID while deleting
   its predecessor, and the HTTP suite proves the old session file is unavailable.
3. **Tampering (low likelihood, medium impact):** corrupt serialized state attempts
   to extend lifetime with negative, future, reversed or string timestamps. The
   policy accepts only coherent integers and fails closed at both time bounds.

These controls follow the PHP project's
[session security guidance](https://www.php.net/manual/en/features.session.security.management.php),
including strict mode, timestamp-managed lifetime and ID regeneration.

### 2. Blast radius and annual-loss input

A stolen admin session can review category-only reports, promote an already-public
message and make reversible moderation changes to all guestbook records. It cannot
write a release, retrieve plaintext network identifiers, permanently purge records
or cross the dedicated Unix/FPM boundary by design. There were three production
records at review time. No monetary ALE is invented: responsible calculation still
requires takeover frequency, operator response time/rate, downtime value, legal cost
and notification population/cost.

### 3. Detection and MTTD

Failed/blocked logins and successful login/logout and moderation outcomes enter
bounded security or append-only audit records. Five recent admin failures page through
the five-minute health cycle, so that abuse path targets an MTTD of five minutes plus
timer jitter. A clean stolen-cookie action can look valid; its honest MTTD is unknown
until an operator notices an unexpected audit event. Session IDs, CSRF values and
message text never enter the alert payload.

### 4. Response, runbook and exercise

The incident-response runbook covers containment, admin credential rotation, global
session invalidation, evidence preservation, audit review and recovery. The automated
suite rehearses fixation refusal and old-ID destruction at login, timeout and logout.
A human credential-compromise tabletop with measured containment time is still due;
this prevents a green operational verdict.

### 5. Regulatory window

Session compromise can affect public content and expose moderation metadata. If the
event qualifies as a personal-data breach, the controller still needs documented
assessment against GDPR's 72-hour supervisory-authority notification window. This
code change does not replace legal assessment or the incomplete data-subject workflow.

### 6. Vendors and supply chain

This hardening adds one first-party PHP policy module and no package, cookie, vendor,
processor, external API, credential or new network flow. Existing Cloudflare, VPS,
self-hosted analytics and Telegram monitoring boundaries are unchanged.

## CISO review: private author controls

**Date:** 2026-10-25
**Verdict:** 🟡 MITIGATE THEN SHIP — the technical blast radius is tightly bounded and
the deployment is fail-closed, but the valid-token abuse tabletop and complete legal
rights workflow remain explicit operational follow-ups.

### 1. Threat model (STRIDE top three)

1. **Spoofing/information disclosure (low likelihood, medium impact):** a stolen
   ownership cookie lets an attacker export or withdraw that message as its author.
   Controls are 256
   random bits, HttpOnly, Secure in production, SameSite Strict, an exact management
   path, no URL/HTML/JavaScript/log projection, a database HMAC and session plus
   capability-bound CSRF. Path scoping reduces routine transmission but is not treated
   as a standalone security boundary, consistent with
   [IETF RFC 6265](https://datatracker.ietf.org/doc/html/rfc6265#section-4.1.2.4).
2. **Tampering/repudiation (low likelihood, high impact):** an unrecorded export, a
   forged withdrawal or a later admin restore could contradict the author. Both
   actions append content-free accountability events. Withdrawal consumes the
   capability, records the controlled transition under a non-identifying logical
   actor, and SQLite blocks republication and removal of the withdrawal marker even
   after application rollback.
3. **Denial of service (medium likelihood, low impact):** guesses or floods against
   numeric management routes can create DB/log load. Missing GET capabilities stay
   sessionless and return generic 404; POST failures use a persistent keyed limiter,
   bounded security events and a shared ten-in-15-minute owner-action alert. A valid
   stolen capability affects one message, not an account or collection.

The cookie flags follow PHP's documented `setcookie` controls for path, Secure,
HttpOnly and SameSite, while CSRF remains separately required as recommended by the
[PHP session security guidance](https://www.php.net/manual/en/features.session.security.management.php).

### 2. Blast radius and annual-loss input

One stolen capability can export and hide only its one future submission. The export
contains its submitted author/text, timestamp and current state, not internal metadata.
A database-only leak
reveals keyed digests, not the random browser values. Full application-secret plus DB
loss still does not make the random capabilities enumerable, while browser takeover,
same-origin script execution or Cloudflare/TLS-edge compromise can reach a capability
in flight. Existing pre-migration messages have no capability. No responsible FAIR/ALE
number can be invented without capability-theft frequency, incident labour/rate,
wrongful-removal cost, legal cost and affected-person notification cost.

### 3. Detection and MTTD

Every export or withdrawal success/rejection emits fixed-code `message.owner_export`
or `message.owner_remove` state; append-only audit records both successful actions.
Ten combined failures/blocks in 15 minutes fail the next five-minute health run and
reach the existing Telegram pager.
Health and every restore drill verify token shape and terminal-row invariants; unit,
HTTP and Chromium CI cover wrong/replayed tokens, stateless misses, cookie flags,
CSRF, first-party surface removal, rollback safety, mobile reflow and WCAG. A single
valid stolen-token use resembles a legitimate withdrawal, so its MTTD remains manual
audit/user-report dependent without collecting more identity data.

### 4. Response, runbook and rehearsal

The [incident runbook](INCIDENT_RESPONSE.md) now covers route disablement by immutable
rollback, evidence preservation, bulk digest invalidation after a verified snapshot,
export-disclosure assessment, Cloudflare/search cleanup and the prohibition on silent
backup republication. The feature cookie is consumed on withdrawal, but an operator
cannot recall an already downloaded file or remotely erase an unvisited browser cookie;
clearing the server digest invalidates future use. No owner-token tabletop has yet
been completed, so date, containment time, notification decision and corrective owners
still need a recorded exercise.

### 5. Regulatory boundary

The self-service copy is not represented as a complete Article 15 access or Article 20
portability response. Withdrawal removes the record from application-controlled
publication but retains soft-deleted text, audit history, backups and possible
third-party copies, so it is not complete GDPR erasure. The public notice retains a
Report fallback and states the missing authenticated rights channel. Articles 15, 17
and 20 and their exceptions require case-specific handling, and a qualifying breach
keeps the Article 33 supervisory-authority window of up to 72 hours; see the
[official GDPR text](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32016R0679).

### 6. Vendors and supply chain

The change adds zero package, API, credential, subprocess or new processor. It adds
one first-party functional cookie per future message; the self-hosted analytics script
is absent from the private page. Cloudflare already terminates the site's HTTPS and can
therefore observe the cookie on the private path inside the existing edge boundary.
Processor terms, region/transfer basis and edge-log retention remain operator records
outside this repository.

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

## CISO review: public trust pages

**Date:** 2026-10-25

- **Threat model:** information disclosure through a policy route accidentally
  becoming session-backed or projecting private records is the highest-impact case;
  tampering that publishes false retention/moderation claims follows; crawler-driven
  denial of service is third. The routes render fixed, reviewed copy without a data
  query, accept GET/HEAD only, reject all query parameters, create no session/cookie,
  escape metadata, use short public caching and share the existing edge limit.
  `no-transform` keeps their ETags valid and excludes Cloudflare's per-request script
  injection on these read-only documents; interactive guestbook pages retain it.
- **Blast radius and FAIR:** the new routes expose no database row, identifier,
  report, audit event, credential or visitor-specific response. A tampered policy can
  mislead every prospective poster, while a routing regression could at worst expose
  the non-public message collection through adjacent discovery code. At rollout the
  database has three published messages. Annual loss expectancy cannot be calculated
  without crawler/request frequency, incident-response cost, affected-person cost
  and probability of policy/deployment compromise; no monetary value is invented.
- **Detection:** CI validates route/method/query/cookie/cache contracts, exact sitemap
  inclusion, microdata, Axe WCAG A/AA rules and 320-pixel reflow. The five-minute
  production probe checks the Privacy canonical, publication warning, schema, ETag,
  cache policy and absence of a session cookie, giving an MTTD target of five minutes
  plus timer jitter for those regressions. Legal-copy accuracy still needs scheduled
  human review and has no automatic semantic detector.
- **Response:** switch the immutable release symlink back, purge the affected
  Cloudflare objects, remove incorrect search results where necessary, preserve the
  release diff and follow the incident-response runbook. A crawler-leak and privacy-
  notice tabletop has not been completed.
- **Regulatory:** public names/content and keyed online identifiers keep GDPR
  plausibly in scope. The page records retention and current operational limits, but
  it explicitly is not a complete statutory notice: controller identity/contact,
  legal bases, rights handling, transfer/processor records and a private data-subject
  channel remain to be completed. A qualifying personal-data breach retains the
  controller's 72-hour supervisory-authority assessment window.
- **Vendors and supply chain:** the feature adds zero packages, vendors, processors,
  credentials, cookies, CDNs or outbound APIs. Existing Cloudflare, self-hosted
  analytics, hosting, backup providers and search crawlers retain their already-
  documented boundaries.

**Verdict:** 🟡 MITIGATE THEN SHIP. Technical publication controls are strong and the
copy is deliberately honest; a dedicated private contact and complete data-subject
workflow are required before calling privacy governance complete.

## CISO review: bounded bulk moderation

**Date:** 2026-10-26

**Verdict:** 🟡 MITIGATE THEN SHIP — the shipped control is bounded, atomic,
reversible and attributable, but clean misuse through a stolen valid admin session
has no dedicated alert and the credential-compromise exercise remains outstanding.

### 1. Threat model (STRIDE top three)

1. **Elevation/tampering (low likelihood, high impact):** a stolen valid admin
   session could suppress multiple public messages. Same-origin CSRF-protected POST,
   expiring sessions, canonical unique IDs, a maximum of 20 selections per request,
   reversible states and per-message audit contain the path. Bulk publication and
   permanent purge are absent, but an attacker can repeat requests until containment.
2. **Repudiation/integrity (low likelihood, high impact):** a partial batch or a
   single aggregate record could obscure exactly what changed. `BEGIN IMMEDIATE`
   locks the preflight and writes; every selected row is validated before the first
   update, any mismatch rolls back the batch, and every successful message receives
   its normal append-only transition event with actor, old/new state, reason and time.
3. **Denial of service (low likelihood, medium impact):** repeated authenticated
   batches could hold SQLite's write lock or create review churn. Each request is
   limited to 20 rows and fixed prepared operations, the admin session is time-bounded,
   and ordinary busy/health monitoring remains in place. There is no per-session bulk
   rate limit because current legitimate volume is very small; this should be revisited
   if usage or contention evidence changes.

### 2. Blast radius and annual-loss input

One request can change at most 20 explicitly named, non-withdrawn messages to pending,
hidden, spam or soft-deleted. Repeated requests under a compromised session can
eventually suppress every eligible guestbook message; the per-request cap is not
represented as a total-loss boundary. The path cannot publish, permanently purge,
change author-withdrawn records, alter code/configuration, read secrets or affect
another service. Production contained three message records at review time. No FAIR
annual-loss value is invented without credential-compromise frequency, moderation
recovery labour, downtime/reputation cost and affected-person/legal inputs.

### 3. Detection and MTTD

CI detects authorization, malformed-list, atomicity, audit, no-bulk-publication,
confirmation, mobile overflow and accessibility regressions before release. Runtime
success produces one identifier-free `message.bulk_moderate` security event plus the
append-only event for every changed message; failures and blocks enter the private
24-hour operations summary. There is no dedicated Telegram threshold for successful
bulk activity, so clean use of a stolen valid session may only be noticed during audit
review or content observation. Its honest MTTD is unknown.

### 4. Response, runbook and exercise

On suspected misuse, follow the incident-response runbook: invalidate all admin
sessions, rotate the credential as warranted, preserve the audit/database/journal
evidence, identify the exact transitions, and restore intended states through audited
moderation. Rolling the application release back does not reverse data already
moderated. The automated suite rehearses full rollback on an invalid batch; a human
credential-takeover exercise that measures detection and restoration time is not yet
recorded and prevents a green verdict.

### 5. Regulatory window

Author names and message text can be personal data, and unauthorized suppression or
access requires a documented integrity/confidentiality breach assessment. If an
incident meets GDPR notification criteria, the supervisory-authority window remains
up to 72 hours from awareness; operator/legal assessment determines applicability and
any communication to affected people. Soft moderation is not statutory erasure.

### 6. Vendors and supply chain

The feature adds no migration, package, cookie, credential, subprocess, external API,
vendor or processor. It reuses first-party PHP, JavaScript and SQLite. Cloudflare and
the VPS retain their existing request/hosting roles; self-hosted analytics is absent
from admin views, and Telegram receives no new bulk payload or message identifier.

## CISO review: private owner message export

**Date:** 2026-10-27

**Verdict:** 🟡 MITIGATE THEN SHIP — the export is capability-bound, field-allow-listed
and auditable, but a valid stolen capability is still a one-message confidentiality
path and the human abuse exercise remains incomplete.

### 1. Threat model (STRIDE top three)

1. **Spoofing/information disclosure (low likelihood, medium impact):** theft of the
   owner capability can authorize one export. The capability has 256 random bits, is
   held in an HttpOnly, Secure-in-production, SameSite Strict cookie on the exact
   management path, is stored only as a purpose-separated HMAC, and export additionally
   requires the browser session CSRF plus a capability-bound CSRF value. The private
   page excludes analytics and does not render the message body.
2. **Repudiation (low likelihood, medium impact):** an export without evidence would
   obstruct investigation. A successful export appends a content-free
   `message.owner_export` audit row in the same locked transaction that authorizes the
   projection and emits a bounded security event. Rejected requests also emit fixed
   security state without the capability or content.
3. **Denial of service (medium likelihood, low impact):** guesses or floods against
   numeric management paths can create database/event load. Missing GET capabilities
   remain sessionless 404s; invalid POST capabilities share the persistent keyed owner
   limiter, bounded event retention and ten-failures-in-15-minutes health threshold
   with withdrawal.

### 2. Blast radius and annual-loss input

One valid capability exposes one future message's numeric ID, submitted name and text,
submission timestamp and current public/moderation state. It cannot enumerate or
export another message, network identifiers, content fingerprints, risk flags/scores,
moderation reasons, ownership material, reports, audit records or secrets. Production
contained three message records at review time. A responsible FAIR/ALE value cannot be
invented without capability-theft frequency, incident labour/rate, legal costs and the
affected-person notification cost.

### 3. Detection and MTTD

Success creates both a `message.owner_export` security event and append-only audit
event. Ten failed/blocked export or withdrawal actions in 15 minutes page through the
next five-minute probe and existing Telegram route using only
`component=security reason=owner-capability-threshold`. Unit, HTTP and Chromium CI
detect projection, CSRF, headers, attachment, audit and internal-field leakage
regressions. A single successful use of a stolen valid capability resembles legitimate
use, so its honest MTTD remains manual audit/user-report dependent.

### 4. Response, runbook and exercise

The incident runbook covers immutable rollback to disable the route, evidence
preservation, a verified snapshot before bulk digest invalidation, assessment of the
exported data and the fact that a downloaded device file cannot be recalled. Never log
or export capability values. Automated tests exercise rejection, success, audit and
post-withdrawal replay denial; no human owner-capability tabletop has recorded detection
or containment time, so this control is not green.

### 5. Regulatory window

The attachment may contain personal data. Suspected unauthorized export needs a
documented confidentiality-breach assessment, and a qualifying GDPR incident retains
the supervisory-authority notification window of up to 72 hours from awareness. This
one-message convenience copy is not represented as a complete authenticated Article 15
access or Article 20 portability response and does not close the outstanding statutory
rights workflow.

### 6. Vendors and supply chain

The feature adds no package, migration, external API, cookie, credential, vendor,
processor or outbound request. It reuses first-party PHP and SQLite. Cloudflare and the
VPS remain the existing transport/hosting boundary; the private page excludes
self-hosted analytics, and Telegram receives only the fixed owner-capability threshold
reason without message data.

## CISO review: public permalink navigation

**Date:** 2026-10-27

**Verdict:** 🟡 MITIGATE THEN SHIP — the feature links only already-public records
through a bounded indexed lookup and live status validation, but a human
crawler/cache-removal exercise and complete data-subject operations remain open.

### 1. Threat model (STRIDE top three)

1. **Information disclosure (low likelihood, medium impact):** a missing status
   predicate could reveal a moderated numeric ID. Both neighbour branches explicitly
   require `status = 'published'`, CI places a hidden record between public fixtures,
   and the target permalink independently returns 404 unless still published.
2. **Tampering/spoofing (low likelihood, medium impact):** visitor fields must not
   control link destinations or labels. The server derives positive numeric paths and
   fixed descriptive labels from database IDs, escapes the URL context and uses
   deterministic `(created_at, id)` ordering.
3. **Denial of service (low likelihood, low impact):** crawling every permalink adds
   neighbour lookups. Each page executes one prepared, index-backed CTE query that
   returns at most two IDs and introduces no recursive crawl fan-out beyond the finite
   published set.

### 2. Blast radius and annual-loss input

The feature can reveal at most two numeric IDs per page, each intended to point to an
already-public message. Its worst credible predicate bug exposes moderated IDs, not
their bodies, because the destination route retains its own published-only 404
boundary. Production contained three message records at review time. A responsible
FAIR/ALE estimate needs incident frequency, response labour/rate and legal costs;
those inputs are unavailable and no value is invented.

### 3. Detection and MTTD

Unit, HTTP and Chromium CI cover timestamp ties, hidden neighbours, boundary links,
mobile reflow, semantic navigation and Axe. The five-minute production health probe
checks the selected live permalink's archive breadcrumb and validates every rendered
neighbour ID as published in SQLite, then uses the existing fixed-reason pager and
Telegram route. It does not exhaustively crawl every permalink, so a record-specific
regression outside the selected path could retain a manual or unknown MTTD.

### 4. Response, runbook and exercise

The incident runbook already covers hiding the record, evidence preservation,
Cloudflare cache purge, search-console URL removal and immutable rollback. Automated
fixtures exercise the leak boundary, but no human crawler/cache-removal tabletop has
recorded containment time; the verdict therefore remains yellow.

### 5. Regulatory window

Public names and message text can be personal data. Unauthorized disclosure of a
hidden identifier requires a documented confidentiality assessment even when the body
remains unavailable; a qualifying GDPR incident retains the supervisory-authority
notification window of up to 72 hours from awareness. The feature collects no new
personal data.

### 6. Vendors and supply chain

The feature adds no migration, package, cookie, credential, external API, vendor or
processor. Cloudflare, the VPS and independent crawlers retain their existing roles;
the analytics boundary is unchanged, and Telegram receives only a fixed health reason
without a message ID or content.

## CISO review: public archive-month navigation

**Date:** 2026-10-27

**Verdict:** 🟡 MITIGATE THEN SHIP — the navigation exposes only validated keys for
already-public months and is continuously checked, but crawler/cache-removal response
still lacks a measured human tabletop.

### 1. Threat model (STRIDE top three)

1. **Information disclosure (low likelihood, medium impact):** a missing publication
   predicate could reveal that a moderated-only month exists. The current-month guard
   and both neighbour branches require `status = 'published'`; HTTP fixtures place a
   hidden-only month in the gap, and destination routes independently return 404.
2. **Tampering/spoofing (low likelihood, medium impact):** malformed stored timestamps
   could create deceptive or off-site paths. Only strict `YYYY-MM` values are accepted,
   paths are generated server-side as same-origin `/archive/YYYY/MM` URLs, and labels
   come from UTC date objects rather than visitor fields.
3. **Denial of service (low likelihood, low impact):** archive crawling adds one lookup
   per monthly page. The prepared CTE uses the status/timestamp index and returns at
   most two keys; it introduces no recursion, write or unbounded result set.

### 2. Blast radius and annual-loss input

Each response adds at most two month keys that should already be public. The worst
credible predicate defect reveals the existence of moderated-only months, not message
counts, authors, bodies, network identifiers or secrets, because their target pages
retain a published-only 404 boundary. Production contained three published messages
at review time. FAIR/ALE cannot be responsibly quantified without incident frequency,
response labour/rate and legal-cost inputs, so no monetary value is fabricated.

### 3. Detection and MTTD

Unit and HTTP tests cover hidden gaps, empty/current boundaries and both directions;
Chromium checks the real navigation at 320px with Axe. Every five minutes, production
health extracts at most two canonical month keys from the discovered archive page and
requires at least one published SQLite row for each before the existing fixed-reason
pager and Telegram route report success. The probe samples one discovered month rather
than every archive URL, so a record-specific regression elsewhere may have manual or
unknown MTTD.

### 4. Response, runbook and exercise

The incident runbook covers hiding the affected record, evidence preservation,
Cloudflare cache purge, search-console URL removal and immutable rollback. Automated
fixtures exercise the disclosure boundary, but a human crawler/cache-removal tabletop
has not recorded containment time; the verdict therefore remains yellow.

### 5. Regulatory window

The feature collects no new personal data. A leaked month key alone is low-detail
metadata, but if it enables inference about hidden personal content the controller must
document a confidentiality assessment; a qualifying GDPR incident retains the
supervisory-authority notification window of up to 72 hours from awareness.

### 6. Vendors and supply chain

No migration, package, cookie, credential, external API, vendor or processor is added.
Cloudflare, the VPS and independent crawlers retain their existing roles; analytics is
unchanged, and Telegram receives only a fixed health reason without a month key or
message data.

## Controls verified

- No secret, database or private key is tracked in the current tree or detected in
  repository history by the audit patterns.
- The live `.env` is not Git-tracked and is mode `0640`; the SQLite database passes
  `PRAGMA integrity_check`.
- SQL calls handling user input use prepared statements; rendered message content,
  author names and queries are HTML-escaped.
- POST actions use CSRF tokens and cookies are `Secure`, `HttpOnly`, and
  `SameSite=Strict`; strict mode rejects chosen IDs, admin privilege has 30-minute
  idle and 12-hour absolute bounds, and login/expiry/logout replace the session ID.
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
