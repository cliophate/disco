# Privacy

Disco is self-hosted and does not operate a hosted service. The operator is responsible for access control, backups, retention, provider terms, and compliance applicable to their installation.

## Data Stored Locally

Depending on enabled features, Disco stores:

- the owner's name, email address, password hash, sessions, preferences, and administration audit entries;
- Plex library metadata, stable provider identifiers, media characteristics and paths needed for authorized playback, source snapshots, normalized cached artwork, and recent library/playback state;
- ListenBrainz listening events, matching evidence, aggregates, and recommendations;
- MusicBrainz, Cover Art Archive, Discogs, TheAudioDB, Wikimedia, and publisher-feed metadata or source evidence;
- upcoming-release notifications, recommendation feedback, lists, follows, and operational job state; and
- encrypted provider credentials entered through the administration interface.

Listening history and catalog data can reveal sensitive personal interests. Database dumps, artwork storage, logs, and backups must be treated as private data.

## External Requests

Disco contacts Plex and enabled metadata or notification providers from the server during synchronization, enrichment, playback, and scheduled work. Requests may disclose the server's IP address, provider-specific identifiers, search terms, catalog identifiers, or notification content as required by that integration. Credentials are sent only to their configured provider endpoints.

The browser normally talks only to the Disco origin. Exact external destination links, including Plex Web and Qobuz links, navigate the browser to that provider; the destination then receives a normal browser request and applies its own privacy and cookie policies. If the optional publisher feed is enabled, the browser may load the feed-supplied image from the publisher domain with a no-referrer policy.

Disco does not include a project-operated analytics or telemetry service. Infrastructure added by an operator, such as a reverse proxy, access gateway, DNS provider, CDN, error tracker, or monitoring agent, may collect additional data.

## Credentials

- Environment credentials are available to processes that can read the application environment.
- Credentials saved through the administration interface are encrypted in PostgreSQL using Laravel's application encryption and `APP_KEY`.
- Plex tokens remain server-side and are not intentionally returned in browser responses, artwork URLs, snapshots, or logs.
- `APP_KEY` is required to decrypt stored credentials and must be backed up separately and securely.

Use dedicated credentials, grant the minimum practical access, rotate exposed secrets, and never submit real credentials or private payloads in issues.

## Retention and Deletion

Retention varies by data type. Some caches and recommendations have scheduled expiry or pruning, while catalog records, source evidence, audit entries, owner-generated state, and imported listening history may persist. ListenBrainz listening events are intentionally immutable in the application schema.

There is no general-purpose privacy export or erase workflow. Before enabling an integration, operators should inspect its retention behavior and decide whether it fits their requirements. To fully decommission an installation, stop all processes, revoke provider credentials, delete PostgreSQL data, delete the complete persistent `storage/` tree, clear Redis, and expire all backups according to the operator's retention policy.

Removing a provider credential stops future authenticated use after processes reload configuration, but does not necessarily delete previously imported data. Review local data separately before disposal.
