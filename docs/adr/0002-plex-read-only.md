# ADR 0002: Keep Plex read-only

## Status

Accepted, with the narrow playback exception defined by ADR 0005.

## Decision

The new Disco connector reads the configured Plex Music library over a verified direct HTTPS endpoint. It fails closed when an expected machine identifier differs. Browser clients never receive the Plex token.

The default playback boundary is a token-free **Open in Plex** link. ADR 0005 additionally permits same-origin original-file playback and the bounded Plex timeline/scrobble writes required to record that playback. Disco does not create Plex playlists, control other clients, or mutate library metadata.

Disco may poll the verified server's read-only `/status/sessions` endpoint once per minute. It retains only canonical album identifiers, player state, observation time, and a two-minute expiry in Redis. Plex user, device, address, session, and raw response data are discarded. Because the connector is not bound to one Plex account, the interface says that activity was observed on the configured server rather than claiming that a particular person is listening.

Recent playback uses synchronized Plex `lastViewedAt` evidence with an explicit 90-day window. Availability means that an active holding existed at the last successful library sync; it does not claim that Plex is reachable now. Page rendering reads PostgreSQL and Redis only and never contacts Plex.

## Consequences

- Other Plex applications retain their independent responsibilities.
- The application can operate with a minimally shared Plex account when practical.
- Artwork is served through an authenticated, allowlisted backend endpoint.
- Any Plex write outside ADR 0005's bounded playback timeline requires a separate ADR and explicit plan/apply safety model.
- Track progress, album completion, transport controls, dynamic hub parsing, and durable session-history ingestion remain unsupported until Plex exposes a sufficiently bounded and identity-safe contract.
