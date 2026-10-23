# Explainable submission review

## Behaviour

Every validated post receives an explainable score from fixed, versioned signals.
A score of 40 or more creates the message as `pending`; lower scores publish it.
Nothing is deleted, hidden after publication or reported to a vendor automatically.
Only an authenticated moderator can publish or move queued content.

| Signal | Points |
| --- | ---: |
| Same normalized content within 24 hours | 50 |
| Invisible Unicode formatting controls removed | 45 |
| Three or more links | 45 |
| Two links | 25 |
| Links occupy at least 55% of the message | 25 |
| URL/domain-like author name | 20 |
| One character repeated at least 12 times | 20 |
| Form submitted within two seconds of issue | 10 |

Scores are capped at 100. The timing signal cannot queue a normal message by itself.
Signals are deterministic reason codes visible to the moderator; they contain no
visitor text. The system intentionally has no opaque model, domain reputation feed,
allow/block list or automatic permanent action.

Duplicate detection uses a purpose-separated HMAC of normalized message content.
The fingerprint and the existing keyed network identifier are detached from the
message after 24 hours. The score and fixed reason codes remain with the moderation
record, while public HTML, search, RSS and API v1 select only published messages and
never expose review metadata. Records created before migration 006 have no fingerprint,
so duplicate detection warms up from the first post made by the new release.

## Failure modes and safeguards

- A legitimate repeated post, link collection or Unicode control can be a false
  positive. The content remains recoverable and the visitor receives an honest queue
  confirmation; the moderator sees the reasons and can publish it.
- Distributed, semantically varied spam can remain below the score. Per-client post
  limits, Cloudflare controls and human reports remain separate layers. A dedicated
  edge post/search/API policy is still required.
- Duplicate check and insert run under one SQLite `BEGIN IMMEDIATE` transaction, so
  concurrent identical posts cannot both bypass the check.
- Ten pending messages fail the five-minute health probe with the fixed reason
  `pending-message-threshold`. Alerts contain no name, message, fingerprint or
  network identifier.
- A health-alert failure does not alter content. The journal remains authoritative
  and the moderator can inspect the queue directly.

## CISO review

**Date:** 2026-10-23
**Verdict:** 🟡 MITIGATE THEN SHIP

### Threat model

1. **Denial of service / moderation exhaustion (medium likelihood and impact):** an
   attacker can generate pending records or vary content to evade duplicate checks.
   Controls are bounded input, existing hourly/cooldown limits, indexed queue reads,
   a ten-item alert and reversible review. Distributed edge limits remain open.
2. **Tampering / censorship (low likelihood, high impact):** aggressive rules could
   silently suppress legitimate speech. Controls are a documented threshold,
   fixed reasons, explicit visitor feedback, no automatic delete and admin audit.
3. **Information disclosure (low likelihood, medium impact):** identifiers or message
   fragments could leak through alerts or public APIs. Controls are keyed purpose
   separation, 24-hour detachment, fixed-code alerts and explicit published-only
   projections.

### Blast radius and economics

A heuristic mistake affects new posts that cross the threshold until a moderator
reviews them; it cannot remove existing public messages or deploy code. Compromise of
the admin credential can change reversible moderation states but cannot permanently
purge records. A defensible annual-loss estimate needs measured spam frequency,
moderator hours and rate, delay cost and incident-response cost; these values are not
invented from the repository.

### Detection and response

Pending backlog has a target MTTD of five minutes plus timer jitter. On alert, inspect
fixed reasons, preserve suspicious records, publish false positives, and use the
[incident-response runbook](INCIDENT_RESPONSE.md) if abuse is coordinated or an admin
credential may be compromised. Tune weights only in a tested release; do not manually
rewrite production scores. A formal abuse/tabletop exercise remains outstanding.

### Regulatory and vendors

Message content, display names, online identifiers and moderation decisions may be
personal data under GDPR. The public notice describes the review and 24-hour keyed
identifiers; the controller still needs a complete legal-basis and data-subject
workflow. This feature adds no processor, API, model or runtime package. Cloudflare,
the VPS, self-hosted analytics and privacy-minimal Telegram operations remain the
existing vendor boundary.

## Verification and rollback

Unit tests cover each score family, the threshold, Unicode normalization, retention
and privacy-safe events. HTTP and real-browser tests prove that a duplicate is queued,
absent from public surfaces, accessible to an authenticated moderator and publishable.
The health smoke test proves pending-backlog alerting without production content.

Migration `006_submission_risk.sql` only adds nullable/defaulted columns and an index.
The preceding application release ignores them, so an atomic symlink rollback remains
compatible. Do not remove the migration record or restore an older database over a
live writer.
