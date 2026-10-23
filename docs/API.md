# Public API v1

## Contract

`GET /api/v1/messages` returns published guestbook messages in stable descending
order. `HEAD` returns the same representation headers without a body. The endpoint is
read-only and deliberately supports no credentials, sessions or cross-origin grant.
The machine-readable contract is published at `/openapi.json`.

Query parameters:

- `limit`: integer from 1 to 50; default 20.
- `cursor`: opaque `next_cursor` returned by the preceding response. It is signed with
  a purpose-separated HMAC and must not be inspected or modified.

Unknown parameters, nested inputs, out-of-range limits and invalid/tampered cursors
return a stable `400` JSON error. Methods other than GET/HEAD return `405` with
`Allow: GET, HEAD`.

Example:

```bash
curl --fail-with-body 'https://php.micutu.com/api/v1/messages?limit=10'
```

Successful document shape:

```json
{
  "api_version": "1.0",
  "data": [
    {
      "id": 3,
      "author": "Example",
      "content": "A public message",
      "created_at": "2026-10-21T12:00:00Z",
      "url": "https://php.micutu.com/messages/3"
    }
  ],
  "pagination": {
    "limit": 10,
    "has_more": false,
    "next_cursor": null
  }
}
```

The older `/?format=json` response remains temporarily available with private,
no-store caching and sends `Deprecation: true` plus a `successor-version` link. New
integrations must use v1.

## Cache and consistency

Each exact response document receives a strong SHA-256 `ETag` and
`Cache-Control: public, max-age=30, stale-while-revalidate=60`. A matching
`If-None-Match` receives `304` without a body. Only already-public `published` rows are
cacheable; pending, hidden, spam and deleted rows are excluded in the indexed SQL
query.

Cursor pagination uses `(created_at DESC, id DESC)` and fetches at most `limit + 1`
rows, avoiding a full count. A cursor can become invalid after `APP_SECRET` rotation;
clients should discard it and restart at the first page after a `400 invalid_cursor`.
No cache may infer authorization because this endpoint has no authenticated variant.

## Privacy and browser boundary

The API does not call `session_start`, does not create a session file and does not emit
`Set-Cookie`. Unknown/non-interactive paths are stateless as well. No
`Access-Control-Allow-Origin` header is sent, and the nginx
`Cross-Origin-Resource-Policy: same-origin` policy remains in force. Public author,
content, timestamp, identifier and canonical URL are the only returned fields; IP
hashes, moderation reasons, reports and audit/security events are never selected.

## Operations and detection

The five-minute health probe validates the v1 schema with `limit=1`, requires its
public cache policy and ETag, and fails if a session cookie appears. The existing
nginx request limit still applies when the route internally reaches the sole PHP front
controller. HTTP smoke tests prove the empty and populated response, ETag changes,
304, HEAD, stable errors, no cookie and no cross-origin grant.

## CISO review

**Date:** 2026-10-22  
**Verdict:** 🟡 MITIGATE THEN SHIP

### Threat model

1. **Denial of service — medium likelihood/medium impact:** aggressive pagination can
   create repeated SQLite reads. Controls: 50-row hard limit, signed cursor, indexed
   seek pagination, short public caching and the existing nginx per-client limit.
2. **Information disclosure — low likelihood/high impact:** a query could accidentally
   include moderated content or cache an authenticated response. Controls: the route
   has no authenticated variant, selects explicit columns and `published` only, and
   runs before every session-backed UI path.
3. **Tampering/cache confusion — low likelihood/medium impact:** modified cursors or
   inconsistent validators could skip boundaries or poison caches. Controls: HMAC,
   strict scalar/parameter allow-lists, deterministic JSON and strong ETags.

### Blast radius and FAIR inputs

An API-specific flaw can expose the same names/messages already public in HTML and
RSS; at review time production contains three published messages. It cannot select
reports, network hashes, admin audit or secrets. A defensible annual-loss estimate
still needs request/incident frequency, response hours, downtime value and legal cost;
no monetary figure is invented from source code.

### Detection and response

Availability, schema, cache headers and cookie regression have an approximately
five-minute MTTD through the health timer, while edge 5xx/traffic anomalies use the
existing Cloudflare bot. Follow [the incident-response runbook](INCIDENT_RESPONSE.md)
for containment and rollback. The formal human tabletop and a dedicated API edge
rate/SLO exercise remain outstanding.

### Regulatory and supply chain

Public user-supplied content and online traffic identifiers can remain within GDPR
scope even though this API adds no new personal field. A qualifying breach retains the
72-hour controller assessment window. No package, SaaS, processor, credential or
cross-origin recipient was added.
