# Technical SEO and public discovery

## Indexable surface

The canonical site name is **Micuțu Guestbook**. The homepage, each published
message permalink, each populated UTC calendar archive and each non-search stream
page are indexable. Search-result and authenticated-admin views emit `noindex, follow`.
A missing, empty or fully moderated archive and a missing, hidden, pending, spam or
deleted permalink return `404`, avoiding thin/soft-404 pages and moderation-state
disclosure.

Canonical URLs are absolute and deterministic:

- homepage: `https://php.micutu.com/`;
- published message: `https://php.micutu.com/messages/ID`;
- recent stream page: `https://php.micutu.com/?page=N`;
- monthly archive: `https://php.micutu.com/archive/YYYY/MM[?page=N]`;
- public information: `https://php.micutu.com/about`, `/privacy` and `/moderation`;
- search results preserve their query in a self-canonical URL but remain `noindex`.

The server-rendered head includes a unique title and description, canonical URL,
robots policy, Open Graph fields, a large-image social card, a crawlable SVG favicon
and RSS/JSON Feed discovery. The 1200×630 PNG has explicit MIME type, dimensions and alternative
text in Open Graph and X metadata; message permalinks additionally identify themselves
as articles and expose their publication time. The card is a first-party illustration
with no visitor content, remote font, tracker or request-time image generation. Its
source SVG and rendered PNG are versioned together, while unit and production health
checks pin the output digest and dimensions.

## Discovery documents

- `/sitemap.xml` is sessionless, conditionally cacheable and contains the homepage,
  the three public information pages, populated monthly archives and published
  message permalinks. Pending or
  moderated records are excluded at the SQL boundary. The message budget dynamically
  reserves space for at most 120 archive URLs and keeps the total at or below the
  protocol's 50,000-URL ceiling; shard it before the site approaches that size.
- `/feed.xml` is a deterministic, sessionless RSS 2.0 feed containing at most 20
  published records. It supports strong ETags and has a short public cache lifetime.
- `/feed.json` exposes the same bounded, published-only collection as JSON Feed 1.1.
  It is sessionless, plain-text-only, conditionally cacheable and advertised from
  every HTML page with an `application/feed+json` alternate link.
- `robots.txt` is versioned with the release and points to this subdomain's sitemap,
  correcting the former `micutu.com/sitemap.xml` reference.
- Legacy `/?format=rss` requests permanently redirect to `/feed.xml`.

The homepage exposes the newest 12 populated UTC months with public-only counts.
Monthly pages use indexed half-open timestamp ranges, chronological ordering, unique
titles/descriptions, self-canonicals, sequential pagination links and visible
`BreadcrumbList` markup. Arbitrary query mixing fails closed and an empty month is a
real 404. Moderating its final public message removes the archive URL from both the UI
and sitemap immediately.

The homepage exposes `WebSite` microdata with a visible matching site name. A message
permalink exposes `DiscussionForumPosting`, `Person`, full visible text and an ISO
publication time. Its visible two-level breadcrumb also exposes `BreadcrumbList`.
Legacy `/?message=ID` links redirect permanently without creating a session, while
malformed or mixed canonical parameters fail closed. Microdata avoids weakening the
CSP for inline JSON-LD and is emitted only when the same published content is visible
on the page.

The standalone About, Privacy and Moderation routes are sessionless HTML documents
with origin-generated ETags, short public caching and no cookie. Each has a unique
title, description and self-canonical, a visible `BreadcrumbList`, and explicit
`AboutPage` or `WebPage` microdata whose fields match visible text. The policy
navigation is available from every UI page. Privacy copy explicitly distinguishes
application removal from crawler/cache copies and records the current absence of a
dedicated data-subject channel instead of claiming a control that does not exist.

Those three read-only documents send `Cache-Control: no-transform`. Cloudflare's
zone-wide JavaScript Detection otherwise rewrites HTML and deliberately strips its
ETag; preventing transformation here preserves the end-to-end validator and avoids a
challenge script on informational pages. Interactive guestbook HTML remains eligible
for the existing bot signal. The edge can legitimately weaken the strong origin ETag
when compression changes the byte representation; health accepts either valid form
and verifies conditional requests instead of asserting byte identity across encodings.
This boundary follows Cloudflare's documented
[JavaScript Detection behavior](https://developers.cloudflare.com/cloudflare-challenges/challenge-types/javascript-detections/),
[`no-transform` semantics](https://developers.cloudflare.com/cache/concepts/cache-control/),
and [ETag compression behavior](https://developers.cloudflare.com/cache/reference/etag-headers/).

Each visible message also has a progressively enhanced Share control. It sends only
the canonical permalink and a generic numeric title to the browser's native share
sheet after an explicit click. Browsers without that API copy the same URL locally;
without JavaScript, the ordinary permalink remains available. Visitor names and
message text never enter share metadata, and no social SDK or outbound API is loaded.

This implementation follows current primary guidance from
[Google Search Central on sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap),
[canonical URLs](https://developers.google.com/search/docs/crawling-indexing/canonicalization),
[pagination and incremental page loading](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading),
[site names](https://developers.google.com/search/docs/appearance/site-names),
[robots metadata](https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag),
[image discovery](https://developers.google.com/search/docs/appearance/google-images),
[Open Graph image properties](https://ogp.me/),
[JSON Feed 1.1](https://www.jsonfeed.org/version/1.1/),
[Schema.org AboutPage](https://schema.org/AboutPage),
[Schema.org WebPage](https://schema.org/WebPage), and
[discussion-forum structured data](https://developers.google.com/search/docs/appearance/structured-data/discussion-forum).

## CISO review

**Date:** 2026-10-25

### Threat model

1. **Information disclosure — medium likelihood/high impact:** a sitemap, calendar
   archive, feed or structured-data projection could expose pending or moderated
   content. Mitigation: every generator and archive query selects
   `status = 'published'`; HTTP tests hide a live record and prove immediate removal
   from every discovery surface and a 404 for its now-empty month.
2. **Tampering/spoofing — medium likelihood/medium impact:** visitor text could inject
   markup, misleading metadata or a hostile canonical URL. Mitigation: canonical URLs
   are application-generated from strictly positive numeric identifiers, clean paths
   reject query mixing, XML and HTML contexts are independently escaped, titles are
   length-bounded and structured fields exactly mirror visible content.
3. **Denial of service — low likelihood/medium impact:** crawler bursts could repeatedly
   generate large XML documents or archive queries. Mitigation: a strict 50,000-URL
   sitemap budget, 120-month sitemap/12-month UI bounds, indexed status/timestamp
   ranges, normal page-size limits and the existing edge request limit.
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

The five-minute health probe validates RSS, JSON Feed, sitemap XML with all three
public information URLs, the sessionless Privacy page contract, a discovered
calendar archive, the exact robots sitemap directive and a current clean permalink
through Cloudflare. CI separately proves the
legacy redirect is sessionless, canonical paths reject mixed parameters, hidden
records return 404, and validates cache headers, XML, browser-visible microdata and
explicit share invocation. On leakage, hide the record, purge the affected Cloudflare cache object,
remove the URL through the relevant search console when necessary, preserve logs and
follow the [incident-response runbook](INCIDENT_RESPONSE.md). The current MTTD target
is five minutes plus timer jitter. A search-console review and crawler-leak tabletop
have not yet been completed and remain manual follow-ups.

### Regulatory and vendors

Search engines can redistribute public author names and messages, which strengthens
the need for clear publication notice and erasure handling under GDPR. No new vendor,
processor, cookie, package or outbound submission API is introduced. Google, Bing and
other crawlers may independently discover public URLs; Search Console ownership and
processor/legal-basis records remain operator responsibilities. A qualifying personal
data breach still requires controller assessment against GDPR's 72-hour window. The
calendar archive adds zero vendors, subprocessors, cookies, packages or outbound APIs.

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
