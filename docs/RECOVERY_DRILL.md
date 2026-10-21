# Backup recovery and restore drills

## Objectives and current guarantees

The engineering targets are a maximum 24-hour recovery point and a two-hour
recovery time for the guestbook. They require owner acceptance before being treated
as business commitments. The daily SQLite online backup and 30-hour freshness alert
support the RPO. A weekly isolated restore drill proves that the newest snapshot can
be decompressed, opened and read by the current application schema.

The drill never opens or writes the live database. It runs without a network, cannot
see the production storage directory, restores into a private runtime directory and
deletes that copy on every exit. It verifies:

- gzip integrity and a maximum snapshot age of 30 hours;
- SQLite `integrity_check` and `foreign_key_check`;
- the minimum migration count and representative reads from every application,
  security, moderation and audit table;
- an exact mode `0600` backup artifact and a privacy-minimal success marker.

The weekly timer runs after the daily backup window. A failed job pages immediately
through the host pager; the five-minute health probe also fails when the last valid
marker is older than eight days. Logs contain ages, migration counts and fixed reason
codes, never rows, paths supplied by visitors, message content or identifiers.

## Operator verification

Run the same production control, not an ad-hoc database command:

```bash
sudo systemctl start php-guestbook-restore-drill.service
sudo systemctl status --no-pager php-guestbook-restore-drill.service
sudo -u php-guestbook /srv/canary/php.micutu.com/current/scripts/healthcheck.sh
```

The first command must finish successfully, the journal must contain exactly one
`guestbook-restore-drill: ok` line and health must include `restore-drill`. Do not
print the restored database, message counts or message bodies to prove success.

## Real recovery procedure

1. Stop state-changing traffic before touching production. Preserve the live SQLite
   database and any WAL/SHM sidecars as evidence; never decompress over them.
2. Copy the selected backup into a root-controlled staging directory and record its
   SHA-256 digest, modification time and incident identifier.
3. Decompress to a new mode `0600` file. Run SQLite integrity and foreign-key checks,
   then run the current restore drill against an isolated directory.
4. Test the candidate database with the exact immutable release that will serve it.
   Apply migrations only to the staged copy and rerun all checks.
5. With PHP-FPM still unable to write, install the candidate using correct
   `php-guestbook:php-guestbook` ownership and an atomic rename. Keep the original
   database outside the served path for rollback.
6. Start the pool, run CI-compatible public/protected-path and health probes, take a
   new online backup, and only then restore normal traffic.
7. Record detection, containment and recovery times plus the hashes of the release,
   original evidence and selected backup. Do not put visitor data in the ticket or
   Telegram alert.

If any integrity, schema or compatibility check fails, stop. A partial or manually
edited restore is not an acceptable fallback.

## Off-host gap and key recovery

Local snapshots and the live database remain on one VPS failure boundary. The next
control must upload ciphertext only to two already approved destinations, verify it
after transfer, never mirror local deletion and use a recovery key that survives loss
of this host. A key available only through a host-bound systemd credential cannot be
the sole recovery key. Provisioning the remote credentials, recovery recipients and
retention policy is a separate operator-approved step; no application secret should
be reused for backup encryption.

Once off-host copying is enabled, run a quarterly human drill from a downloaded
artifact using the escrowed recovery key on an isolated recovery system. Record only
artifact digests and timings. Do not weaken encryption or copy the private recovery
key onto the production VPS merely to automate that exercise.

## CISO review

**Date:** 2026-10-22  
**Verdict:** 🟡 MITIGATE THEN SHIP for the complete resilience program; the isolated
local restore control itself is safe to ship, while host-loss recovery remains open.

### STRIDE and blast radius

- **Tampering/denial of service:** a compromised `php-guestbook` identity can still
  delete the local database and local snapshots. The drill catches accidental
  corruption and incompatibility but does not create an independent trust boundary.
- **Information disclosure:** local snapshots contain all database fields. Mode
  `0600`, a private backup directory and sandboxed jobs reduce exposure; encrypted
  off-host copies remain required before data leaves the VPS.
- **Repudiation:** the success marker proves that the scheduled command completed,
  but a host-level or runtime-identity compromise can forge local evidence. Remote
  immutable storage and provider audit logs are required for stronger evidence.
- **Spoofing/elevation:** the drill accepts only fixed absolute paths, regular files,
  bounded integers and a narrow filename pattern; it has no network, capabilities or
  access to live storage.

The affected set is the entire guestbook database: public content plus pseudonymous
identifiers, security events, reports and audit records. A financial annual-loss
estimate needs incident labor, downtime value, legal cost, notification population
and event frequency; repository evidence cannot supply those figures responsibly.

### Detection and response

Backup creation failures page immediately and stale/corrupt artifacts are detected
within five minutes after the 30-hour limit. Restore incompatibility is detected by
the weekly job or within eight days if the timer itself stops. The recovery procedure
above is executable, but a human data-loss tabletop and measured two-hour RTO are not
yet evidenced. Alternate quarterly exercises with credential compromise as required
by the incident-response runbook.

### Regulatory and vendors

Guestbook backups can contain personal data, so GDPR purpose limitation, retention,
access control and breach assessment apply. A qualifying personal-data breach may
require supervisory-authority notification within 72 hours of awareness; the
controller makes that legal determination. This local drill adds no vendor. The
planned off-host stage will use existing Google Drive and Cloudflare R2 infrastructure
and therefore must confirm processor records, region/transfer basis, retention,
credential scope, immutability and deletion handling before activation.
