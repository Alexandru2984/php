# Guestbook incident-response runbook

## Triage

1. Acknowledge the alert and note its UTC timestamp, unit, component and reason code.
2. On the VPS, inspect `systemctl status` and the local journal for the named unit.
   Treat Telegram as a pager, not as the evidence store.
3. Run the health probe manually as `php-guestbook`. Do not paste database rows,
   configuration or visitor-controlled log lines into chat.
4. Verify SQLite integrity, current release target, file ownership, newest backup age,
   nginx/FPM status and direct-origin denial.

## Containment

- Availability failure: keep the last known-good release and nginx configuration;
  atomically repoint `current` if the new release is the cause.
- Database/integrity failure: stop state-changing traffic before repair. Never restore
  over a database while FPM can write it. Preserve the database and WAL/SHM sidecars.
- Credential suspicion: rotate the guestbook secret/admin credential and invalidate
  sessions. If the alert channel is affected, rotate both the isolated bot credential
  and the encrypted pager credential, then restart their services.
- Analytics concern: remove the analytics environment entry from the FPM pool, test
  the pool configuration, reload FPM and verify that rendered HTML has no tracker.
- Abuse: keep the persistent rate limit in place, preserve security-event rows and
  apply temporary edge controls only after checking shared-NAT impact.

## Evidence and recovery

Preserve relevant journal ranges, nginx/Cloudflare event timestamps, release SHA,
database integrity output, backup filename/hash and security-event aggregates. Do not
export message bodies unless incident scope requires it. Restore into a disposable
path first, validate integrity/data count, and use the documented atomic rollout.

After recovery, run the full CI suite, public/protected-path probes and a manual
one-shot backup. A successful watched service emits a single resolved notification
when a matching incident marker exists.

## Notification decision

Record when the controller became aware, categories of data and people, likely harm,
containment and legal decision owner. If GDPR Article 33 applies, the supervisory
authority window is 72 hours from awareness. Preserve the reasoning when notification
is not required.

External communication template:

> We identified an incident affecting the guestbook on [UTC time]. We contained it
> at [UTC time]. The data categories under assessment are [categories]; current
> evidence indicates [scope]. We have [rotated/restored/blocked] the affected
> component and will provide material updates through [channel].

Do not claim that no data was accessed until logs, database integrity, release state
and relevant vendor evidence support that conclusion.

## Exercise cadence

Run quarterly tabletops alternating between database corruption, leaked Telegram
credentials, admin takeover and a silent/stale backup. Record participants, detection
time, containment time, gaps and owners for every corrective action.
