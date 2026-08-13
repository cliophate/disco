# ADR 0004: Defer cached landscape artist artwork

## Status

Accepted.

## Context

Disco's artist hero accepts only an explicit landscape raster that is at least 1200 pixels wide, at least 500 pixels high, and has an aspect ratio of at least 1.6:1. Square and portrait Plex artwork remains in the existing two-column treatment and is never stretched into a banner.

Landscape artist imagery is usually copyrighted photography. API availability, a user-uploaded image URL, or a provider's ability to respond to takedown requests does not by itself grant Disco or a public self-hoster redistribution rights. A safe pipeline must establish exact artist identity, permission for cached redistribution, the applicable per-asset licence and attribution, suitable dimensions, and a bounded refresh and removal policy.

## Decision

Disco will **defer** a landscape artist artwork pipeline. No evaluated source currently provides both reliable landscape coverage and machine-readable rights evidence sufficient for an unattended, publicly distributed self-hosted application.

TheAudioDB is the only credible candidate for a future implementation. Its current terms expressly allow artwork lookup through official API endpoints, copying and modification of API content, and use of custom artwork with source credit. It documents 1280x720 artist fanart and exact MusicBrainz artist lookup. However, the same terms prohibit third-party content unless separately permitted and direct consumers to a `strCreativeCommons` tag to confirm that artist artwork is CC-licensed. A live exact-MBID response reviewed for this decision returned fanart URLs but no `strCreativeCommons` field or equivalent per-image rights metadata. Disco therefore cannot distinguish reusable custom or CC artwork from restricted third-party photography.

No landscape binary, remote URL, or provider response is approved for storage under this decision. The current square/portrait treatment and generated fallback remain the product behavior.

## Source Decisions

| Source | Decision | Rationale |
| --- | --- | --- |
| Plex | Keep existing fallback | Plex supplies exact owned-library artwork through the configured server, but production artist images are square. Disco may continue caching and serving them as portraits under the read-only Plex boundary; it will not crop, upscale, or stretch them into landscape heroes. |
| TheAudioDB | Defer pending rights evidence | Exact MusicBrainz lookup and documented fanart dimensions are suitable. The terms permit official-API artwork use and require source credit, but also distinguish third-party content and refer to a CC marker that was absent from the reviewed API response. Provider-wide permission is not treated as per-asset evidence where the provider's own terms make that distinction. |
| Wikimedia Commons via Wikidata | Defer as unreliable for this use | Wikidata can provide an exact CC0 identity bridge and Commons exposes per-file creator, licence, source, dimensions, and URLs. Requirements vary by file, including any attribution and share-alike obligations; files may also be subject to personality rights or other non-copyright restrictions. Wikidata's representative-image property is not a landscape-art endpoint and normally offers no reliable landscape choice. This remains a possible portrait source, not a dependable artist-hero pipeline. |
| fanart.tv | Reject without a verifiable contract | The service is oriented toward fan-created artwork, but its official terms and API documentation were not retrievable during review, and no machine-readable per-asset licence contract was established. An API key or technical access alone would not cure that gap. |
| Discogs | Reject | Images are Restricted Data rather than the CC0 catalog fields approved by ADR 0003. Discogs grants a revocable, non-sublicensable restricted-data licence, prohibits transfer, requires attribution, forbids displaying content more than six hours out of date, and limits storage to what the service needs. Disco continues to exclude all Discogs images. |
| Cover Art Archive | Reject for artist heroes | Its documented API supplies release and release-group cover art, not artist photography. Album art must not be repurposed as artist identity or a landscape banner. |
| Official sites, social networks, Qobuz, Apple, and Spotify | Reject | Disco has no approved image-redistribution contract for these sources and will not scrape pages, use undocumented application endpoints, or infer permission from public display. |

## Reopening Requirements

Implementation may be reconsidered only when one provider supplies all of the following:

- Exact lookup by the canonical MusicBrainz artist MBID, with the returned MBID verified before any image request.
- A documented official API and an operator-configured credential where required.
- Machine-readable per-asset rights evidence, or written provider terms unambiguously covering every accepted image field for cached redistribution by public self-hosters.
- Creator or provider attribution, source page, licence name and URL, and any modification/share-alike notice required for each displayed image.
- A genuine landscape original meeting the hero threshold without upscaling; portraits, logos, banners below 1200x500, and images that require destructive cropping are rejected.
- Bounded HTTPS retrieval, strict host and redirect allowlists, raster decoding, MIME and size checks, a 25-megapixel ceiling, WebP normalization, content hashing, local authenticated serving, and no render-time provider calls.
- Persisted provider object ID, exact artist MBID, source URL, original URL, content hash, dimensions, retrieval time, expiry, licence evidence, attribution text, and rejection reason.
- A maximum 30-day refresh interval, immediate suppression when rights evidence disappears, a deletion/takedown path, and provider disconnect cleanup.
- Coverage testing showing that the source is useful beyond a small set of popular artists.

For TheAudioDB specifically, a future review must confirm that `strArtistFanart` through `strArtistFanart4` are covered and must obtain a machine-readable CC/custom-art indicator for each accepted URL. `strArtistWideThumb` is documented at only 1000x562 and does not meet the current hero width; `strArtistBanner` is too shallow. Disco will not upscale either asset.

## Attribution and Redistribution Requirements

If a future source passes the reopening requirements, the artist page must display a visible credit adjacent to the image, linking to the provider or original file page and the applicable licence. API responses and exports must retain the same provenance. Cached images are runtime content and must not be bundled into source archives, container images, fixtures, screenshots, or release assets.

Share-alike media may be normalized only after confirming whether the transformation creates a derivative work and recording any notice and licence required for that derivative work. Public-domain claims still require provenance and are subject to review. Operators remain responsible for non-copyright restrictions such as personality, privacy, trademark, and moral rights.

## Consequences

- Artist pages remain visually consistent with square/portrait imagery and generated fallback art, without introducing unapproved landscape imagery.
- Disco avoids building a cache that cannot explain why each photograph may be redistributed.
- A new issue for a TheAudioDB landscape implementation may be opened only after TheAudioDB's rights metadata or a written contract satisfies this ADR.
- Wikimedia Commons may be evaluated separately for attributed portraits, but it is not used as a substitute landscape search engine.
- Public-release licensing review in issue #38 can treat landscape artist artwork as excluded runtime content.

## Evidence Reviewed

Reviewed on 2026-07-27:

- [TheAudioDB Terms of Use](https://www.theaudiodb.com/docs_terms_of_use.php), last updated 2025-07-01.
- [TheAudioDB artwork types and dimensions](https://www.theaudiodb.com/docs_artwork).
- [TheAudioDB API documentation](https://www.theaudiodb.com/free_music_api), including exact MusicBrainz artist lookup, image delivery, and rate limits.
- [TheAudioDB API pricing](https://www.theaudiodb.com/pricing), including premium MusicBrainz-ID lookup terms.
- Live TheAudioDB `artist-mb.php` response for MusicBrainz artist `cc197bad-dc9c-440d-a5b5-d52ba2e14234`; landscape URLs were present and per-asset licence fields were absent.
- [Wikimedia Foundation Terms of Use](https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use), especially non-text media and reuse requirements.
- [Wikimedia Commons reuse guidance](https://commons.wikimedia.org/wiki/Commons:Reusing_content_outside_Wikimedia), including per-file licensing, attribution, share-alike, and non-copyright restrictions.
- [MediaWiki Imageinfo API](https://www.mediawiki.org/wiki/API:Imageinfo), including dimensions, URLs, hashes, and extended metadata.
- [Wikidata data access guidance](https://www.wikidata.org/wiki/Wikidata:Data_access), including CC0 data and API etiquette.
- [Discogs API Terms of Use](https://support.discogs.com/hc/en-us/articles/360009334593-API-Terms-of-Use), last updated 2025-05-27.
- [Cover Art Archive API](https://musicbrainz.org/doc/Cover_Art_Archive/API).
- Requests for fanart.tv's official terms, API documentation, and key pages each returned an HTTP 403 response during review, so no rights contract was inferred from secondary sources.

This ADR records Disco's engineering policy and is not legal advice.
