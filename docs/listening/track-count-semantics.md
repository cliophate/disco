# Track listening count semantics

Disco projects track evidence from existing read-only Plex synchronization and immutable ListenBrainz imports. Album and track pages never contact either provider.

## Identity

Counts attach only to an active canonical recording with a confirmed Plex recording match or an exact ListenBrainz recording projection. Supplied title and artist text never create a match. Tracks without that identity report `unmatched_identity` and expose no count.

## Plex

Plex `viewCount` is provider-owned coarse evidence. Disco does not claim that it represents a complete listen, and a Plex reset or item replacement can reset its history.

- A positive integer is `counted`.
- An explicit zero is `known_zero`.
- An absent value is `unavailable`, not zero.
- Multiple active exact copies are coalesced by canonical recording. Disco displays the maximum copy count and latest recency rather than summing potentially overlapping evidence.
- Removed copies are excluded. The source freshness is the latest participating Plex synchronization time.

## ListenBrainz

ListenBrainz counts are the number of currently present immutable events projected exactly to the canonical recording. First and last timestamps come from those events.

- One or more exact events are `counted`.
- Zero is `known_zero` only after a completed full import establishes source coverage.
- Without complete coverage, no event is `unavailable`, not zero.
- Provider events are deduplicated by the importer fingerprint and are not combined with Plex counts.

Last.fm is not enabled: adding it requires a separately authorized account-bound adapter and a documented overlap policy. Disco does not silently sum provider histories.

## Operations and privacy

`php artisan disco:track-listening-coverage` reports aggregate exact, counted, zero, unknown, unmatched, duplicate-copy, and freshness totals without logging usernames, tokens, track titles, or listening payloads.

Track projections contain only provider-specific counts, timestamps, source states, and freshness. Export and deletion follow the underlying owner account, immutable event, and Plex library lifecycle; removing source data removes it from subsequent projections.
