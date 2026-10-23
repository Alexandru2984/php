# Technical SEO and public discovery

## Indexable surface

The canonical site name is **Micuțu Guestbook**. The homepage, each published
message permalink and each non-search archive page are indexable. Search-result and
authenticated-admin views emit `noindex, follow`. A missing, hidden, pending, spam or
deleted permalink returns the same `404` response, avoiding both soft-404 pages and
moderation-state disclosure.

Canonical URLs are absolute and deterministic:

- homepage: `https://php.micutu.com/`;
- published message: `https://php.micutu.com/messages/ID`;
- archive page: `https://php.micutu.com/?page=N`;
- search results preserve their query in a self-canonical URL but remain `noindex`.

The server-rendered head includes a unique title and description, canonical URL,
robots policy, Open Graph fields, Twitter summary fields, a crawlable SVG favicon and
RSS discovery. It uses no client-side rendering and no new third-party script.

## Discovery documents

- `/sitemap.xml` is sessionless, conditionally cacheable and contains the homepage
  plus at most 49,999 published message permalinks. Pending or moderated records are
  excluded at the SQL boundary. The limit keeps the document below the protocol's
  50,000-URL ceiling; shard it before the site approaches that size.
- `/feed.xml` is a deterministic, sessionless RSS 2.0 feed containing at most 20
  published records. It supports strong ETags and has a short public cache lifetime.
- `robots.txt` is versioned with the release and points to this subdomain's sitemap,
  correcting the former `micutu.com/sitemap.xml` reference.
- Legacy `/?format=rss` requests permanently redirect to `/feed.xml`.

The homepage exposes `WebSite` microdata with a visible matching site name. A message
permalink exposes `DiscussionForumPosting`, `Person`, full visible text and an ISO
publication time. Its visible two-level breadcrumb also exposes `BreadcrumbList`.
Legacy `/?message=ID` links redirect permanently without creating a session, while
malformed or mixed canonical parameters fail closed. Microdata avoids weakening the
CSP for inline JSON-LD and is emitted only when the same published content is visible
on the page.

Each visible message also has a progressively enhanced Share control. It sends only
the canonical permalink and a generic numeric title to the browser's native share
sheet after an explicit click. Browsers without that API copy the same URL locally;
without JavaScript, the ordinary permalink remains available. Visitor names and
message text never enter share metadata, and no social SDK or outbound API is loaded.

This implementation follows current primary guidance from
[Google Search Central on sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap),
[canonical URLs](https://developers.google.com/search/docs/crawling-indexing/canonicalization),
[site names](https://developers.google.com/search/docs/appearance/site-names),
[robots metadata](https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag),
and [discussion-forum structured data](https://developers.google.com/search/docs/appearance/structured-data/discussion-forum).

## CISO review

**Date:** 2026-10-24

### Threat model

1. **Information disclosure — medium likelihood/high impact:** a sitemap, feed or
   structured-data projection could expose pending or moderated content. Mitigation:
   every generator selects `status = 'published'`; HTTP tests hide a live record and
   prove immediate removal from both discovery documents.
2. **Tampering/spoofing — medium likelihood/medium impact:** visitor text could inject
   markup, misleading metadata or a hostile canonical URL. Mitigation: canonical URLs
   are application-generated from strictly positive numeric identifiers, clean paths
   reject query mixing, XML and HTML contexts are independently escaped, titles are
   length-bounded and structured fields exactly mirror visible content.
3. **Denial of service — low likelihood/medium impact:** crawler bursts could repeatedly
   generate large XML documents. Mitigation: a 49,999-row upper bound, indexed status
   queries, strong ETags, short shared caching and the existing edge request limit.
4. **Accidental disclosure — low likelihood/medium impact:** convenience sharing could
   silently leak visitor text or navigation state. Mitigation: no automatic action,
   generic titles, canonical paths only, and a local clipboard fallback.

### Blast radius

Compromise of this read-only surface could reveal content already marked public and
increase crawler load. A query bug could expose all non-public message bodies in the
database, making that the worst credible data event; it cannot expose admin hashes or
raw network addresses because those fields are never selected. At rollout the live
database has three published messages. A FAIR annual-loss estimate requires crawl
volume, incident frequency, response hours/rate and affected-person costs; repository
evidence does not support inventing a dollar value.

### Detection and response

The five-minute health probe validates RSS, sitemap XML, the exact robots sitemap
directive and a current clean permalink through Cloudflare. CI separately proves the
legacy redirect is sessionless, canonical paths reject mixed parameters, hidden
records return 404, and validates cache headers, XML, browser-visible microdata and
explicit share invocation. On leakage, hide the record, purge the affected Cloudflare cache object,
remove the URL through the relevant search console when necessary, preserve logs and
follow the [incident-response runbook](INCIDENT_RESPONSE.md). A search-console review
and crawler-leak tabletop remain manual follow-ups.

### Regulatory and vendors

Search engines can redistribute public author names and messages, which strengthens
the need for clear publication notice and erasure handling under GDPR. No new vendor,
processor, cookie, package or outbound submission API is introduced. Google, Bing and
other crawlers may independently discover public URLs; Search Console ownership and
processor/legal-basis records remain operator responsibilities. A qualifying personal
data breach still requires controller assessment against GDPR's 72-hour window.

## Verdict and rollback

**Verdict:** 🟡 MITIGATE THEN SHIP. The technical leakage controls and rollback are
present; data-subject operations and post-deploy Search Console validation remain.

The change adds no schema migration, package or vendor. Worst-case blast radius
remains every non-public message if the publication predicate regresses; the route
does not select network hashes, audit data or secrets. A defensible FAIR annual-loss
estimate still lacks incident frequency and response-cost inputs, so no dollar value
is invented. The incident runbook exists, but the crawler-leak tabletop remains due;
a qualifying GDPR breach keeps the 72-hour controller assessment window.

Rollback is the normal atomic release symlink switch. Cached discovery documents and
legacy redirects should be purged if rollback responds to a content-disclosure or
canonicalization defect.
