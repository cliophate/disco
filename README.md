# Disco

> [!CAUTION]
> **This project is vibe-coded to the gills.** It works for me, but it may eat your data, bully your Plex server, steal your Linux ISOs, or otherwise explode without warning. Read the code, understand the code, be one with the code (I did neither). Also, use it at your own risk. Create backups. Generally, don't be an idiot and then blame me.

Disco is a small, single-owner music discovery tool. It uses listening history from ListenBrainz and a read-only Plex library to suggest albums to preview and buy on Qobuz. Listening stays in Plex.

## Features

One page, in priority order:

- **New artists.** About ten albums each week, drawn from ListenBrainz artist radio around the artists you play. Owned, familiar, passed and recently picked artists are excluded.
- **New releases.** Personal ListenBrainz releases plus releases by artists in the current discovery pool. Refreshed daily, with released and upcoming dates.
- **More from artists you play.** Unheard albums by high-taste artists, refreshed weekly.

Each pick has local cover art when available, a plain reason, an exact Qobuz album link or search fallback, a Passed action, and New, In Plex or Played status. Earlier unresolved weekly picks stay in a collapsed list. New releases stay in one list across daily batches, from 30 days ago into the future. Passing a new-artist pick suppresses the artist; other passes suppress the album. History prevents album repeats.

Plex sync retains server and library identity checks. MusicBrainz supplies exact identities and album types. Cover Art Archive and Plex artwork use the existing local cache. Owner admin contains provider credentials and pipeline status.

## Boundaries

- One owner, no registration, social features or additional accounts.
- Whole albums and EPs. Standalone singles, compilations, live albums and the other excluded secondary types are skipped. Soundtracks are allowed.
- Plex is read-only. There is no player, stream gateway, playback reporting, library browser, search, artist follow, listening list, notification or push delivery.
- No genre-based recommendations or copied editorial prose.
- Page requests read local state only. Scheduled jobs fetch metadata and prepare Qobuz destinations and artwork.
- No Qobuz API credentials or playback integration. Qobuz links open its site or app.

See [ADR 0006](docs/adr/0006-album-discovery.md) for ranking, matching, provider contracts and storage decisions. [ADR 0002](docs/adr/0002-plex-read-only.md) keeps Plex read-only.

## Requirements

- PHP 8.4 with `pdo_pgsql`, `mbstring`, `gd`, `openssl`, `xml`, and `zip`
- PostgreSQL 18
- Redis 8
- Node.js 24 or newer
- Composer 2
- A reachable Plex Media Server and music library

## Local Development

```bash
cp .env.example .env
docker compose -f compose.dev.yaml up -d
composer install
npm ci
php artisan key:generate
php artisan migrate
php artisan disco:owner you@example.test --name="Your Name"
npm run build
php artisan serve
```

The owner command prompts for a password of at least 12 characters and refuses to create a second account. The development Compose file exposes PostgreSQL and Redis on loopback only and stores disposable state in `.runtime/`.

Configure Plex only in the ignored `.env` file:

```dotenv
PLEX_URL=https://verified-direct-endpoint.example:32400
PLEX_TOKEN=replace-me
PLEX_LIBRARY_NAME=Music
PLEX_EXPECTED_MACHINE_IDENTIFIER=replace-me
PLEX_EXPECTED_LIBRARY_UUID=replace-me
PLEX_ALLOW_INSECURE_HTTP=false
```

Never commit credentials, private service URLs, machine identifiers, library UUIDs, or live provider payloads. Preview the Plex connection before writing catalog data:

```bash
php artisan disco:plex-sync --dry-run
php artisan disco:plex-sync
php artisan disco:plex-artwork --type=album
php artisan disco:plex-artwork --type=artist
php artisan disco:musicbrainz-enrich
```

Configure ListenBrainz with `LISTENBRAINZ_USERNAME` and `LISTENBRAINZ_TOKEN`, then import history. The full import backfills derived MusicBrainz artist names, artist IDs and release-group identities without changing immutable listening events.

```bash
php artisan disco:listenbrainz-sync --full
php artisan disco:discovery-new-artists
php artisan disco:discovery-new-releases
php artisan disco:discovery-more
php artisan disco:discovery-reconcile
php artisan disco:discovery-artwork
```

Completed batches are idempotent for their UTC week or day. Failed runs can be retried with the same command. Empty batches with no identified seeds can be retried after importing history or enriching owned artists. Weekly batches replace the current view, while earlier unresolved picks remain available. Daily new-release batches add to the rolling release list.

The UTC schedule runs Plex sync hourly, ListenBrainz import every 15 minutes and a full import Monday at 01:37, MusicBrainz enrichment daily at 03:00, new artists Monday at 05:00, new releases daily at 07:00, and more albums Monday at 08:00. Plex sync and ListenBrainz import reconcile pick states on completion. Plex artwork retries run daily at 02:30 and discovery cover retries at 07:45. Newly released albums retry covers even if an earlier upcoming lookup was missing.

The incremental overlap defaults to 172800 seconds, or 48 hours. Fingerprints deduplicate repeated listens. Increase `LISTENBRAINZ_OVERLAP_SECONDS` if upstream batches arrive later. All application and database timestamps use UTC; `APP_TIMEZONE` no longer overrides storage time.

[`.env.example`](.env.example) documents batch size, seed count, lifetime-listen threshold, repeat cooldown, seed diversity cap, artist listener cap, popularity threshold and the separate MusicBrainz call budget for familiar artists. Unknown popularity is neutral. Known counts above 25000 are excluded by default, and remaining scores divide by `sqrt(1 + listeners / 5000)`. Seeds receive 0.1 points per listen, plus 1 within 365 days and 2 within 90 days. Plex play contributions use the larger of Plex and ListenBrainz scores to limit double counting.

`DISCOVERY_SOUNDTRACK_CAP` defaults to 2 soundtrack-lane picks per batch for new artists and candidate new releases. Personal new releases and More are exempt. A pick enters this lane if its release group is Soundtrack-typed, its artist has a soundtrack-related MusicBrainz genre or tag, or at least half its seed weight comes from soundtrack-leaning seeds. `DISCOVERY_SOUNDTRACK_SEED_THRESHOLD` defaults to 0.5, the share of a seed's last two years of listens on Soundtrack-typed releases or titles matching soundtrack and game patterns. Cap skips leave room for other ranked candidates without using their album lookup allowance. Set the cap to 0 to skip all picks in this lane. Batch summaries record soundtrack picks, skips and hits for each rule.

## Production

This repository includes production application and web-server images plus a [`compose.production.example.yaml`](compose.production.example.yaml) reference stack. Operators must still provide TLS termination, secrets, durable permissions, monitoring, and backups.

Never publish the application origin directly. Bind it to a private network and place it behind a correctly configured HTTPS reverse proxy or access gateway. The proxy is part of the security boundary and must replace untrusted forwarded headers. See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for an operational checklist.

Before every upgrade, back up PostgreSQL, the complete Laravel `storage/` directory, and the application secrets required to decrypt stored credentials. See [`docs/UPGRADING.md`](docs/UPGRADING.md).

## Verification

```bash
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from test
docker compose -f compose.test.yaml down --volumes --remove-orphans
./vendor/bin/pint --test
npm test
npm run typecheck
npm run build
```

The Compose command runs `php artisan test` against a disposable PostgreSQL database. If PHP is unavailable on the host, run Pint with `docker compose -f compose.test.yaml run --rm --no-deps test ./vendor/bin/pint --test`.

Tests use synthetic fixtures and do not contact live providers. PostgreSQL integration tests refuse to reset a database not named `disco_test` in the testing environment.

## Project Policies

- [Deployment](docs/DEPLOYMENT.md)
- [Upgrading](docs/UPGRADING.md)
- [Privacy](docs/PRIVACY.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Support](SUPPORT.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)

## Fonts

The public source uses Newsreader under the SIL Open Font License and a system sans-serif stack. Operators may replace `resources/css/operator.css` and add webfont files in their private deployment only when their font license permits that use; do not submit restricted font binaries to this repository.

Disco is licensed under the [MIT License](LICENSE).
