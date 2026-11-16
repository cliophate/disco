# ADR 0002: Keep Plex read-only

## Status

Accepted. ADR 0006 removes the playback exception formerly permitted by the now-superseded ADR 0005.

## Decision

Disco reads the configured Plex Music library through a verified direct HTTPS endpoint. Sync and artwork ingestion fail closed on a mismatched server identity. Sync also checks the expected library UUID. Browser clients never receive the Plex token.

Plex supplies whole-album ownership and play evidence. Disco never streams audio, sends timelines or scrobbles, creates playlists, controls clients or changes library metadata. All listening stays in Plex.

Page requests read local PostgreSQL and artwork only. Hourly sync updates ownership and play state, then reconciles discovery picks. Availability describes the latest sync, not current server reachability.

## Consequences

Other Plex clients keep responsibility for listening and play reporting. Plex scrobbles may reach ListenBrainz later through external services, so Disco imports overlapping history and deduplicates it. Future Plex writes require a separate decision.
