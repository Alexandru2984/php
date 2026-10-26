# Indexed search

## Contract

Public HTML and the deprecated `/?format=json` feed use the same SQLite FTS5 index
over message author and content. API v1 is unchanged and does not accept a search
parameter. Results always join back to `messages` and require `status = 'published'`;
an indexed hidden, deleted, spam or pending row is never returned.

Search is intentionally plain text, not an FTS query language. The parser:

- accepts Unicode letters and numbers and performs case-insensitive, diacritic-free
  tokenization through SQLite `unicode61`;
- discards quotes, punctuation, column selectors and operators such as `OR`/`NEAR`
  as syntax, treating their alphabetic text as ordinary words;
- keeps at most eight distinct terms of 48 characters from a 100-character input;
- generates quoted prefix terms joined with `AND`, then binds the expression as a
  prepared-statement parameter;
- returns no results for punctuation-only input instead of falling back to an
  unbounded table scan.

Author matches have higher BM25 weight than content matches. Ties use newest-first
ordering. Existing bounded page size and request validation remain in force.

## Private moderation search

An authenticated admin can reuse the index to review all moderation states without
turning private content into a public search surface. The workspace supports a
100-character plain-text query, exact state filters, newest/oldest/risk ordering and
ten-row pages. Every parameter must be scalar; status and sort use allow-lists. FTS
receives only the same generated, quoted terms used by public search, while sort and
state SQL fragments are selected from fixed application maps. Invalid and
out-of-range pages fail closed.

The result query deliberately projects only the message number, author, content,
state, controlled moderation metadata, creation time and explainable risk fields. It
does not select network hashes, capability digests or content fingerprints. Admin
responses are private/no-store, noindex/nofollow/noarchive and same-origin referrer
only. The analytics script is absent from every authenticated admin response, so
search terms and non-public message text do not enter first-party analytics. Search
terms are also excluded from security events and Telegram alerts.

Anonymous use of any `admin_*` discovery parameter returns `400`; public routes and
their cache/search-engine contracts are otherwise unchanged.

## Index lifecycle and recovery

Migration `005_full_text_search.sql` creates an external-content FTS5 table, initial
backfill and insert/update/delete triggers. It is additive: the preceding release can
continue using `messages` after rollback, so no destructive down migration is needed.

The weekly restore drill runs FTS5's rank-1 `integrity-check`, which cross-checks the
index against the external content table. If a repair is ever required, stop writers,
preserve the database/WAL/SHM as evidence, restore to a disposable location and run:

```sql
INSERT INTO messages_fts(messages_fts) VALUES ('rebuild');
INSERT INTO messages_fts(messages_fts, rank) VALUES ('integrity-check', 1);
```

Never rebuild over the live database while PHP-FPM can write. Test the repaired copy,
then follow the atomic recovery procedure in [`RECOVERY_DRILL.md`](RECOVERY_DRILL.md).

## CISO review

**Date:** 2026-10-23
**Verdict:** 🟢 SHIP WITH FOLLOW-UP — the indexed implementation removes routine
full scans and raw FTS syntax; a dedicated edge search limit is still recommended.

### STRIDE, blast radius and detection

- **Denial of service:** prefix work is limited to eight bounded tokens, FTS prefix
  indexes and normal pagination. A distributed attacker can still create query load;
  add a Cloudflare search-specific rate limit and monitor latency/SQLite busy time.
- **Injection/tampering:** user input never becomes SQL or raw FTS grammar. Prepared
  statements plus generated quoted terms prevent SQL/FTS operator injection. CI tests
  quotes, column selectors, `NEAR`, punctuation, Unicode and malformed arrays.
- **Information disclosure:** the source-table status filter is applied to every
  search result. Query strings can still exist in browser history and edge/access
  logs, so users should not search for sensitive data; Umami explicitly excludes
  query strings and hashes.
- **Repudiation/spoofing/elevation:** search is anonymous and read-only and creates no
  new identity or privilege boundary. The new tables remain inside the same protected
  SQLite file.

Compromise impact does not add a new dataset: the index derives author/content already
stored in `messages`. It does add another representation of that data inside backups,
so the existing database/off-host encryption and retention controls apply unchanged.
Health detects a missing fifth migration within five minutes; the restore drill
detects FTS schema or content-index divergence weekly. No external package, service or
subprocessor was added.

## Production verification — 2026-10-23

Migration 005 was first applied to a restored production snapshot, then to the live
database before the atomic release switch. Both runs reported SQLite integrity `ok`,
zero foreign-key violations, five applied migrations and three indexed rows for three
source messages. The rank-1 FTS integrity command passed before and after cutover.

Public probes returned `200` for normal and deliberately hostile FTS-like input,
without exposing errors; API v1 and OpenAPI remained `200`, protected paths remained
`404`, and the live query plan reported an FTS5 virtual-table index. A post-migration
snapshot then passed the isolated restore drill with five migrations and FTS content
integrity before its ciphertext was verified in R2.
