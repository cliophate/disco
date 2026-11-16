# Privacy

Disco is self-hosted and has no project-operated analytics or telemetry. The operator controls access, backups and provider configuration.

## Local data

Disco stores the owner's account and sessions, encrypted provider tokens, administration audit entries, Plex catalog and play metadata, imported ListenBrainz history, derived identities, discovery candidates, batches, picks and passes. MusicBrainz source evidence and normalized Plex and Cover Art Archive artwork remain local.

Listening events are immutable. Full imports update separate derived identities and source-presence evidence. Discovery history persists to prevent repeats. Logs, dumps, artwork storage and backups are private data.

## External requests

Background jobs contact Plex, ListenBrainz, MusicBrainz and Cover Art Archive. Artist radio and popularity requests disclose seed and candidate IDs to ListenBrainz. Metadata and artwork lookups disclose catalog IDs to their providers. Requests also disclose the server's network address.

Pages and pass actions use local state. Owner credential tests contact the selected provider. Opening a Qobuz link makes a normal browser request to Qobuz, whose privacy and cookie policies apply.

There are no notifications, push delivery, publisher images or browser playback. The browser receives no Plex token.

## Credentials and retention

Environment secrets remain available to application processes. Admin tokens use Laravel encryption and require the same `APP_KEY` to decrypt after restore. Back up that key separately. Never submit credentials, provider usernames, private hostnames, real listening history or live payloads to the public repository.

Removing a saved token falls back to an environment token if present. Removing all credentials stops future authenticated requests but does not erase imported history. There is no general-purpose export or erasure workflow. Decommissioning requires stopping processes, revoking credentials, deleting private database and storage data, clearing Redis and expiring backups under the operator's retention policy.
