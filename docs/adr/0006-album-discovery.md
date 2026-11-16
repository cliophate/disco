# ADR 0006: Discover albums beyond the owned library

## Status

Accepted. Supersedes the editorial integrations in ADR 0003, artist imagery scope in ADR 0004 and embedded playback in ADR 0005. ADR 0002 keeps Plex read-only.

## Decision

Disco is one owner and one discovery page. Priorities are new artists, new releases and unheard albums by familiar artists. Qobuz is the preview and purchase destination. Plex remains the library and listening application. There are no push notifications.

### Taste and identity

Use active ListenBrainz accounts and source-present events. A listen scores 0.1 all-time, plus 1 in the last 365 days and 2 in the last 90 days. Plex album play counts receive the same recency weighting. For each artist, use the larger Plex or ListenBrainz contribution because Plex plays normally reach ListenBrainz too. This limits double counting but does not claim exact cross-provider deduplication.

Only seeds with MusicBrainz artist IDs qualify. Submitted and mapped ListenBrainz artist IDs contribute directly. Name-only history can use a unique local catalog artist ID. Ambiguous local names cannot become seeds. ArtistPreferencePolicy excludes special artists by name and ID.

Store derived listen identities separately from immutable listening events. Full imports backfill mapped artist and release-group IDs and per-artist credit names from `mbid_mapping.artists`. Each artist MBID uses its mapped name, then its catalog canonical name. The supplied credit string is a fallback only for single-artist listens. Unnamed collaborators count toward history exclusions but cannot seed recommendations. History exclusions use artist IDs, with normalized-name fallback for unidentified events. Album heard sets use release-group IDs and normalized artist and title. Every section excludes heard albums. Names use Unicode NFC, lowercase, whitespace normalization and straight apostrophes. They do not use fuzzy matching.

Storage uses UTC in PHP and the PostgreSQL connection. Older timestamps with an unknown original offset cannot be repaired safely from code and remain untouched. The default incremental overlap is 48 hours, configurable in seconds. Fingerprints deduplicate overlapping pages. Weekly full scans reconcile upstream removals.

### Weekly new artists

Expand about thirty seeds through ListenBrainz artist radio in medium mode. Drop the seed itself. A candidate scores the sum of `log1p(seed taste score)` across distinct referring seeds. Exclude artists above `discovery.max_listener_count`, default 50000. Apply the listener-count penalty to known counts with `discovery.popular_listener_threshold`, default 5000:

`score / sqrt(1 + listeners / threshold)`

Artist popularity comes from `POST /1/popularity/artist`, with batches of at most 100 IDs. Missing counts or endpoint failure preserve the unpenalized score. Radio's `pop_begin` and `pop_end` select recording popularity, so radio requests keep the full 0 to 100 range.

Exclude owned artists by ID or name, artists above five lifetime listens by default, passed artists, artists picked in the last twelve weeks and special artists. Candidate scores persist with their seed evidence for the daily release intersection.

Choose the highest-listen-count eligible release group from ListenBrainz top release groups, validating type and primary artist credit against MusicBrainz. Allow Album and EP. Exclude compilation, live, remix, DJ-mix, demo, interview, spokenword, audiobook, audio drama and mixtape/street secondary types. Soundtracks qualify. Standalone singles never qualify. There is one pick per artist, and each named reason seed gets credit for at most two picks. Reasons name at most two qualifying seeds. Recording CF recommendations are removed.

### Releases and familiar artists

Daily releases combine the user's fresh-release endpoint with the sitewide endpoint intersected with the saved candidate artist IDs. Personal rows win duplicates. Personal `days` accepts 1 to 90; the sitewide client accepts 30 or 60. MusicBrainz verifies the same Album/EP and credit rules. Owned, heard, passed and previously picked albums are excluded. Daily runs add previously unpicked releases. The page combines completed batches into one list of unpassed, unplayed picks dated from 30 days ago into the future. Released albums appear newest first, followed by upcoming albums soonest first. This section has no Earlier list. Personal reasons use per-artist listen counts by MBID with a normalized-name fallback. Candidate reasons name up to two referring seeds.

The weekly familiar-artist batch browses high-taste artists for unowned, unheard eligible groups, up to ten picks with one per artist and no repeated normalized artist name. Albums precede EPs, newest first within each type. Browse rows exclude ineligible types and heard albums before detail lookups. A separate MusicBrainz budget defaults to 150 actual HTTP calls, including browse pages, edition lookups, artwork fallback requests and retries. There is no genre input.

### Picks and jobs

Batches are unique per section and UTC period. Completed batches are idempotent; failed batches retry. Empty batches with no identified seeds can retry after imports. Picks and passes retain history. For weekly sections, current completed picks come first and earlier unresolved picks use a collapsed disclosure. Failed runs preserve the last successful batch and candidate pool.

Generation uses a separate one-hour lock per section. Scheduled overlap locks also expire after one hour. Reconciliation runs after the generation lock releases. Batch summaries retain provider failure totals and counts by endpoint plus HTTP status or exception class, without request URLs or payloads.

Plex sync matches ownership by release-group ID or normalized artist and title. Album `view_count` or `last_viewed_at`, or matching listens, marks a pick Played. States only advance. A matching listen can mark a pick Played before the next Plex sync. Passing a new-artist pick suppresses that artist; other passes suppress the album.

Only jobs fetch MusicBrainz, ListenBrainz and Cover Art Archive metadata. MusicBrainz uses its existing shared rate limit. Exact release-group or release URL relationships feed QobuzDestinationResolver, with `open.qobuz.com` preferred and a search fallback. Cached artwork remains authenticated and local. Credential tests are the explicit provider-call exception in owner admin.

## Provider contracts reviewed

- [ListenBrainz popularity documentation](https://listenbrainz.readthedocs.io/en/latest/users/api/popularity.html) documents POST artist popularity and the list-shaped top-release-group response.
- [Personal fresh-release implementation](https://github.com/metabrainz/listenbrainz-server/blob/master/listenbrainz/webserver/views/fresh_releases.py) documents the 1 to 90 day range, `release_group_primary_type` and singular `release_group_secondary_type` fields.
- [MetaBrainz radio client](https://github.com/metabrainz/troi-recommendation-playground/blob/main/troi/recording_search_service.py) defines recording popularity bounds. The application does not interpret them as artist popularity.

Tests fake every provider and use synthetic fixtures. The implementation does not request live owner data during verification.

## Migration boundary

Keep existing migrations unchanged. New migrations add batches, picks, passes, artist candidates and derived listen identities, then drop retired feature tables. Keep the catalog, source and import plumbing used by Plex, MusicBrainz and artwork. The original exact-recording projection remains importer bookkeeping; discovery uses the name and MBID heard sets instead.

The teardown is irreversible through `migrate:rollback`. Restore a pre-upgrade database and storage backup to recover removed feature data. No migration guesses offsets for old wall-clock timestamps.
