# ADR 0003: Use direct, rights-compatible editorial sources

## Status

Accepted.

## Context

Disco is intended for public distribution as self-hosted software. A core feature cannot require every operator to deploy a separate feed reader or scraper, rely on an unaccountable public intermediary, or assume that technically accessible prose can be republished.

Editorial integrations must preserve exact catalog identity, original provenance, attribution, storage limits, and the source's terms. An intermediary does not expand the original publisher's licence.

## Decision

Disco may consume documented publisher-operated APIs and official RSS or Atom feeds directly. Core functionality must not require FreshRSS, RSSHub, or another operator-hosted intermediary. Disco does not scrape editorial pages or use undocumented application APIs.

The content boundary is:

- **Metadata:** factual identifiers, URLs, dates, categories, creator and publisher names, and catalog fields may be stored when the source permits the intended use.
- **Feed excerpt:** only a summary explicitly supplied by an approved official feed may be stored. It must retain prominent source and author attribution and an outbound canonical link.
- **Full text:** reviews, articles, editorial notes, and HTML recovered by scraping are excluded unless an explicit licence or written permission permits Disco's use.

### Source decisions

| Source | Decision | Rationale |
| --- | --- | --- |
| Qobuz editorial content | Link-only | Qobuz reserves rights in its editorial, graphic, photographic, audio, and video content and prohibits reproduction and commercial use. Disco may store a lawfully obtained exact Qobuz identifier, destination URL, and matching evidence, but not Qobuz descriptions. Generic storefront search remains the fallback. |
| Apple Music editorial notes | Defer | MusicKit is credential-gated, and its agreement restricts music-related text to music playback or playlist management. Disco's standalone editorial presentation is outside that purpose. API availability is not republication permission. |
| Apple Music Feed | Defer | Access to the feed is credential-gated, and its use is limited to publicly promoting Apple Music with required links and badges. It is not an editorial-note workaround, and Disco has no approved Apple Music promotional feature. |
| Pitchfork official RSS | Optional adoption | The live album-review and news feeds supply GUID, canonical link, headline, excerpt, author, publisher, date, category, and thumbnail. An adapter may store only approved feed fields, never article bodies. It must be disabled by default in a neutral public configuration. Private noncommercial operators may enable it; public or commercial hosting requires publisher permission. The browser may load the exact feed-supplied `media:thumbnail` URL from `media.pitchfork.com` with no referrer; Disco does not scrape Open Graph metadata, proxy the image, or store image binaries. |
| Discogs API | Optional CC0 catalog metadata | Authenticated database reads may attach an artist, master, or release only through one exact MusicBrainz Discogs URL. Disco stores a sanitized whitelist of CC0 catalog fields for at most six hours of display freshness. Images, profile prose, videos, community statistics, marketplace and pricing data, user data, collections, and wantlists are excluded. Displayed fields link directly to Discogs with required attribution and non-affiliation wording. |
| FreshRSS | Defer | FreshRSS is an AGPL self-hosted aggregator with operator credentials and aggregator-local identities. Requiring it would burden public operators, while its scraping features do not grant rights to recovered content. Disco should fetch approved official feeds itself. |
| RSSHub | Defer | RSSHub routes and public instances vary in provenance, credentials, behaviour, and reliability. Many routes scrape sources, and route availability grants no republication right. Disco will not provision, require, or default to RSSHub. |

Pitchfork implementation remains tracked in issue #22. Exact authorised Qobuz destinations remain tracked in issue #17. No Apple Music, FreshRSS, or RSSHub implementation issue is created by this decision.

## Operational requirements

- Feed retrieval uses HTTPS only and is scheduled, bounded, cached, and subject to timeouts; no provider calls occur during page rendering.
- XML parsing disables external entities and rejects oversized or malformed responses.
- Stored records retain feed URL, item GUID, canonical article URL, publisher, author, retrieval time, and expiry.
- Catalog attachment requires strong exact evidence; URL slugs and title-only matches are insufficient.
- Full bodies and scraped HTML are never requested or stored.
- Publisher-supplied excerpts are not bundled in Disco releases or fixtures beyond minimal synthetic test data.
- Source-specific functionality fails closed when credentials, permission, or an official feed are unavailable.
- Discogs responses are sanitized before storage, refreshed before five hours where capacity permits, and hidden after six hours; no Discogs request occurs while rendering a page.

## Consequences

- Disco remains installable and useful without another feed service.
- Editorial breadth is lower than a scraping-based system, but provenance and redistribution risk remain explicit.
- Qobuz continues as a destination rather than a prose source.
- Apple Music integration requires a future feature with a permitted Apple-specific purpose.
- Pitchfork cards can proceed only within the narrow official-feed boundary and deployment constraints above.

## Evidence reviewed

Reviewed on 2026-07-25:

- [Qobuz General Conditions of Use and Sale](https://www.qobuz.com/gb-en/legal/terms), especially sections 7 and 17.
- [Apple Developer Program License Agreement](https://developer.apple.com/support/terms/apple-developer-program-license-agreement/#entertainment-tech), especially sections 3.3.6(D) and 3.3.6(F).
- [Apple Music API](https://developer.apple.com/documentation/applemusicapi/) and [Apple Music Feed](https://developer.apple.com/documentation/applemusicfeed).
- [Pitchfork album-review RSS](https://pitchfork.com/feed/feed-album-reviews/rss) and [Pitchfork news RSS](https://pitchfork.com/feed/feed-news/rss).
- [Condé Nast User Agreement](https://www.condenast.com/user-agreement#rules-of-usage).
- [FreshRSS documentation and AGPL project](https://github.com/FreshRSS/FreshRSS).
- [RSSHub documentation](https://docs.rsshub.app/guide/) and [AGPL project](https://github.com/DIYgod/RSSHub).
- [Discogs API authentication and rate-limit documentation](https://www.discogs.com/developers/#page:authentication).
- [Discogs API Terms of Use](https://support.discogs.com/hc/en-us/articles/360009334593-API-Terms-of-Use), last updated May 27, 2025.
