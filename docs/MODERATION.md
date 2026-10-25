# Moderation and abuse reporting

## Data flow and privacy boundary

A public visitor can report a currently published message by selecting one controlled
category: spam, harassment, privacy or another policy concern. The report stores only
the message identifier, category, workflow status and timestamps. There is no
free-text field and no reporter IP, hash, cookie or account identifier in the report.

The existing purpose-separated rate-limit table temporarily holds a keyed network
identifier to cap one client at five reports per hour. That identifier follows the
seven-day limiter retention policy. Closed reports are deleted after 90 days by the
maintenance job. The append-only admin audit uses a stable logical `local-admin`
actor derived independently of any network address.

New submissions also receive fixed, explainable risk reasons. A purpose-separated
content HMAC supports 24-hour duplicate detection; it is detached with the posting
identifier after that window. Scores and fixed reasons contain no submitted text.
See [explainable submission review](ANTI_ABUSE.md) for weights and limitations.

## State model

```text
                 ┌─────────┐
new low-risk ───>│published│<──────────────┐
                 └────┬────┘               │ restore
                      │ admin + reason      │
          ┌───────────┼───────────┬────────┴─┐
          v           v           v          v
       pending      hidden      spam      deleted
          ^
          └──────── new score >= 40
```

Only `published` messages appear in HTML, JSON and RSS. Every successful state change
atomically records the previous state, new state, controlled reason, message number,
logical actor and timestamp. Database triggers reject updates and deletes against the
audit table. Moving a message away from `published` immediately detaches its posting
identifier. Permanent purge remains intentionally deferred.

Public reports never hide content automatically. The admin reviews the queue and can
resolve/dismiss a report separately from changing the message state, preventing a
small number of reports from becoming a censorship primitive.

New submissions also receive a separate owner capability. It is 256 random bits,
stored raw only in an HttpOnly/SameSite Strict cookie scoped to
`/messages/{id}/manage`; SQLite stores a purpose-separated HMAC. The private page is
`no-store`/`noindex`, excludes analytics and message text, and requires same-origin,
session CSRF and capability-bound CSRF checks for withdrawal. The posting browser can
move its one message from any state to `deleted/requested`; the transaction clears the
capability, temporary identifiers and featured state and appends a logical owner audit
event. A database trigger and the moderation helper prevent republication, including
after an application rollback. Losing the cookie means losing self-service control;
the public Report route remains the fallback.
Unused ownership digests are detached after 366 days by scheduled maintenance, one
day after the browser cookie's maximum lifetime.

## Featured-message curation

The admin can highlight one published message at a time. Featuring another message
atomically removes the previous selection and appends both actions to the immutable
admin audit. Removing the feature is reversible, while hiding, deleting, marking as
spam or returning the message to review clears it in the same database transaction;
restoring the message does not silently feature it again.

The database enforces both invariants: a partial unique index permits at most one
selection, and cleanup triggers remove the timestamp from any non-`published` row.
The triggers deliberately allow the preceding release to moderate a featured row
after rollback instead of failing its older update statement.
The homepage places that row before the chronological feed and gives it a text badge;
search and API cursor order remain stable. API clients receive only the boolean
`featured` projection, never the internal timestamps or admin audit record. The health
probe also checks that no invalid or duplicate selection exists.

This control uses the existing expiring admin session, same-origin validation and
CSRF token. Anonymous attempts are recorded as fixed-code security events. Compromise
of the admin credential can influence which already-public message is promoted but
cannot publish hidden content through this action.

## Access and abuse controls

- All report and moderation mutations require same-origin POST plus a session CSRF
  token; moderation additionally requires the expiring admin session.
- IDs, states, categories and reasons are strict scalar allow-lists. Message content
  remains escaped in both the public feed and admin workspace.
- The public form is functional without JavaScript and keeps 44-pixel touch targets
  and a single-column layout at narrow widths.
- Five reports per client per hour starts a one-hour lockout. Ten simultaneous open
  reports fail the five-minute health probe and page the existing Telegram channel
  using only `component=moderation reason=open-report-threshold`.
- Ten simultaneous pending messages likewise page with only
  `component=moderation reason=pending-message-threshold`.
- Ten failed/blocked owner-withdrawal attempts in 15 minutes page on the next
  five-minute probe using only `component=security reason=owner-remove-threshold`.
- Health and restore drills reject malformed capability digests or a withdrawn row
  that is public, tokenized, identifiable, featured or carries the wrong reason.
- Login, blocked actions and review outcomes remain visible in bounded security logs;
  alert payloads never contain the author, message, report category or identifiers.

## CISO review

**Date:** 2026-10-21  
**Verdict:** 🟡 MITIGATE THEN SHIP

### Threat model

1. **Spoofing/elevation (low likelihood, high impact):** a stolen admin credential
   could suppress public content. Controls: persistent login throttling, hardened
   sessions, CSRF/origin validation, reversible states and append-only audit.
2. **Denial of service (medium likelihood, medium impact):** distributed clients can
   create a review backlog. Controls: per-client limits, no auto-hide, indexed queue,
   bounded UI query and a ten-report alert. Edge-wide distributed limiting remains a
   follow-up.
3. **Information disclosure (low likelihood, medium impact):** report or alert data
   could reveal a reporter. Controls: no reporter field, no free text, temporary
   purpose-separated limiter and fixed Telegram reason codes.

### Blast radius

Compromise of the moderation credential permits reversible state changes and report
review, but not code deployment or permanent purge. It exposes the same public message
content plus category-only reports and the logical admin audit. At design time the
production database contains three public messages. A financial annual-loss estimate
requires operator response cost, downtime value and incident frequency and is not
invented from source data.

### Detection and response

Login abuse and a ten-report or ten-pending-message backlog are detected within the
five-minute health-probe target. Systemd failure/recovery uses the existing pager. Follow
[the incident-response runbook](INCIDENT_RESPONSE.md) for credential containment,
session invalidation, evidence preservation and recovery. A formal human tabletop and
credential-rotation drill are still outstanding.

### Regulatory and supply chain

Author names/content and online identifiers can place the service in GDPR scope; a
qualifying personal-data breach requires controller assessment against the 72-hour
notification window. This feature adds no new vendor or package. Cloudflare remains
the edge processor, Telegram receives fixed operational state only, and analytics
remains self-hosted.

## Verification and rollback

Unit tests prove state transitions, identifier detachment, report lifecycle,
retention, risk scoring and append-only triggers. HTTP and browser tests exercise the
anonymous-to-admin flow through real cookies, CSRF and redirects. The health smoke test proves backlog alert
generation without using production data.

Migrations `004_moderation_audit.sql`, `006_submission_risk.sql`,
`007_featured_message.sql` and `008_message_ownership.sql` only add columns, tables,
indexes and triggers;
the preceding release continues to read the database if the application symlink is
rolled back. Migration 008's trigger intentionally rejects an older release's attempt
to republish an owner-withdrawn row. Do not remove the migration record or restore an
older database over a live SQLite writer.
