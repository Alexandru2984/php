# Monitoring and alerting

## Architecture

```text
browser ──page/performance──> self-hosted Umami
   │
   └──HTTP──> Cloudflare ──5-minute anomaly check──> existing Telegram bot

systemd timer ──5 minutes──> guestbook health probe
backup timer ──> backup ──> age encrypt ──> check Google Drive + R2 ┐
restore timer ──────────────> isolated restore drill                ├─failure/recovery─> host pager ─> Telegram
maintenance timer ─────────> retention service                     ┘
```

The application never calls Telegram. The host pager owns delivery, encrypted
credentials, throttling and recovery state. The guestbook emits only bounded,
privacy-minimal component results to the journal. The existing Cloudflare bot checks
traffic spikes and recent 5xx responses every five minutes and already covers the
`php.micutu.com` hostname within the `micutu.com` zone.

The guestbook probe checks:

- HTML marker, sessionless API v1 schema/cache/cookie guarantees, RSS and JSON Feed
  shapes, sitemap XML, the canonical/cache/schema/cookie contract of the Privacy
  page, a discovered calendar archive and robots discovery through the public
  Cloudflare route;
- the self-hosted analytics script and all privacy attributes in rendered HTML;
- SQLite `quick_check`, the minimum migration count, recent admin-login failures and
  privacy-minimal counts of open abuse reports and pending messages;
- compressed backup integrity and maximum backup age of 30 hours;
- a two-provider off-host verification marker no older than 30 hours;
- a validated restore marker no older than eight days, produced only after an
  isolated SQLite integrity, foreign-key, migration, FTS5 and schema-contract drill;
- exact owner/group/mode for configuration, storage, database, sessions and backups;
- an atomic `current` symlink whose target is inside `releases` and not writable by
  the runtime identity;
- storage filesystem use below 90 percent.

## Alert thresholds and MTTD

| Signal | Threshold | Detection target |
| --- | --- | --- |
| Public HTML/API/feed or analytics unavailable | One failed probe | 5 minutes + timer jitter |
| SQLite integrity/schema failure | One failed probe | 5 minutes + timer jitter |
| Admin authentication abuse | 5 failed/blocked attempts in 15 minutes | Next five-minute probe |
| Unreviewed abuse-report backlog | 10 open reports | Next five-minute probe |
| Automated-review backlog | 10 pending messages | Next five-minute probe |
| Backup missing, invalid or old | Older than 30 hours | Next five-minute probe |
| Off-host copies failed or stopped | Either provider fails, or marker older than 30 hours | Immediate dispatch plus next five-minute probe; a verified surviving copy is still recorded |
| Restore drill failed or stopped | Failed job or marker older than 8 days | Immediate dispatch or next five-minute probe |
| Permission or immutable-release drift | Any mismatch | Next five-minute probe |
| Disk pressure | 90% used | Next five-minute probe |
| Edge 5xx or traffic spike | Existing Cloudflare bot policy | About 5 minutes |
| Backup/off-host/restore/maintenance process failure | Non-zero systemd result | Immediate systemd dispatch |

The same incident fingerprint is throttled by the host pager. A different failure is
sent immediately. Recovery generates one resolved notification only when a matching
incident was active. Journald remains the record of record when Telegram is down.
On 2026-10-22 the first live off-host run verified 24 R2 artifacts but detected a full
Google Drive. The resulting `redundancy-degraded` state is intentionally not silenced;
R2 remains recoverable while provider capacity is remediated.

## Privacy-safe payload contract

Allowed fields are host, UTC time, unit, systemd result, exit status, component and a
fixed reason code. Never emit message bodies, author names, raw or hashed IP values,
query strings, CSRF/session values, database rows, credentials or arbitrary exception
objects. Alert messages contain no direct links and no visitor-controlled strings.

Umami is self-hosted. A reviewed tracker copy is served from the guestbook release,
has a SHA-256 digest pinned in unit and production-health checks, is domain-bound,
respects Do Not Track and excludes URL search/hash values. The analytics origin keeps
`connect-src` access for aggregate page/Core Web Vitals delivery but no longer has
`script-src` permission. It does not receive a guestbook user identifier or form field
from this application.

## CISO review

**Date:** 2026-10-22
**Verdict:** 🟡 MITIGATE THEN SHIP — technical mitigations and a runbook are present;
an operator tabletop and Telegram credential-rotation drill remain follow-up work.

### Threat model

1. **Information disclosure (medium likelihood/high impact):** logs or URLs could send
   visitor data to Telegram. Mitigation: fixed reason codes, no query/hash analytics,
   bounded output, no application-to-Telegram integration.
2. **Spoofing/tampering (low likelihood/high impact):** stolen bot credentials could
   forge or suppress alerts. Mitigation: credentials remain outside Git, root-owned or
   systemd-encrypted, never placed in argv, and are independently rotatable.
3. **Denial of service/repudiation (medium likelihood/medium impact):** an attacker
   could cause alert floods or rely on Telegram failure to hide an incident.
   Mitigation: fingerprint throttling, local journals, bounded timeouts/retries and
   recovery state.

### Blast radius

Compromise of the alert channel reveals operational host/unit names, states and
timestamps, not guestbook content or identifiers. Compromise of self-hosted analytics
can reveal aggregate paths without query/hash, referrer, language, screen dimensions
and performance metrics. The database had three public message records when this
integration was designed; the number of analytics visitors is not assumed.

A FAIR annual-loss estimate cannot be responsibly calculated from repository data.
Required inputs are event frequency, response hours and rate, downtime value, legal
cost and affected-person notification cost.

### Detection and response

The target MTTD is five minutes for application availability, integrity, permission
drift and login abuse. Immediate systemd failures page without waiting for the timer.
The operator follows [the incident-response runbook](INCIDENT_RESPONSE.md). The
runbook has not yet completed a formal tabletop; record the date and findings here
after the first exercise.

### Regulatory and vendors

GDPR is plausibly in scope. If an incident is a qualifying personal-data breach, the
controller assesses supervisory-authority notification within 72 hours of awareness.
Telegram receives only operational metadata, Cloudflare processes edge traffic and
R2 ciphertext, Google Drive stores a second ciphertext copy, and Umami remains
self-hosted on the VPS. Confirm all processor records, regions/transfer bases and
retention outside this repository. The current provider credential scope and single
recovery recipient remain explicit follow-up risks.

## CI controls

CI runs the health probe against a disposable HTTP server, migrated SQLite database
and real compressed backup. It proves healthy behaviour plus login-abuse, pending and
report backlogs, stale-backup, stale-offsite, stale-restore and writable-release failures. A separate
restore suite proves a valid recovery plus corrupt and stale snapshot rejection. The
off-host suite proves age recoverability, recipient-digest pinning and destination
validation. The operations smoke test verifies critical systemd hardening/alert
directives and rejects Telegram credentials or direct Bot API integration anywhere in
tracked operational/application sources.
