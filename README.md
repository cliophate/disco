# Disco

> [!CAUTION]
> **This project is vibe-coded to the gills.** It works for me, but it may eat your data, bully your Plex server, steal your Linux ISOs, or otherwise explode without warning. Read the code, understand the code, be one with the code (I did neither). Also, use it at your own risk. Create backups. Generally, don't be an idiot and then blame me.

Disco is a self-hosted, album-first interface for exploring one person's Plex music library. It builds a local catalog, enriches it with source-attributed metadata, and provides discovery, search, listening-history, upcoming-release, and direct-play views.

Disco is intentionally a **single-owner** application. It has no registration, additional accounts, social features, or multi-tenant isolation. A Plex Media Server with a dedicated music library is required; Disco is not a replacement for Plex or a general-purpose Plex client.

## Features

- Synchronizes a Plex music library into PostgreSQL with dry-run support and server/library identity checks.
- Enriches local catalog data through MusicBrainz and Cover Art Archive, with optional ListenBrainz, Discogs, TheAudioDB, Wikimedia, Gotify, and publisher-feed integrations.
- Provides album, artist, library, discovery, recommendation, search, metadata, and administration views.
- Caches normalized artwork locally instead of exposing a live Plex artwork proxy.
- Plays synchronized original FLAC, ALAC, or WAV media through authenticated same-origin sessions when the browser supports the format.
- Generates token-free **Open in Plex** links when direct play is unavailable.

Normal page requests use local PostgreSQL, Redis, and artwork state rather than making live metadata-provider requests.

## Boundaries

- One owner account only. Registration and second accounts are not supported.
- Plex remains the media server and source of library truth.
- No Plex transcoding, autoplay, Companion control, playlist writes, or library-metadata mutation.
- Plex writes are limited to timeline updates and one scrobble needed to record playback initiated in Disco.
- No guarantee of gapless playback, uninterrupted mobile background playback, or support for browser-incompatible source formats.
- Optional integrations remain subject to their providers' terms, rate limits, availability, and data policies.

See the accepted architecture decisions in [`docs/adr/`](docs/adr/) for detailed connector and playback constraints.

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

Optional integrations are documented by their variables in [`.env.example`](.env.example). Keep optional providers disabled unless you have reviewed their current terms and have appropriate credentials.

## Production

This repository includes production application and web-server images plus a [`compose.production.example.yaml`](compose.production.example.yaml) reference stack. Operators must still provide TLS termination, secrets, durable permissions, monitoring, and backups.

Never publish the application origin directly. Bind it to a private network and place it behind a correctly configured HTTPS reverse proxy or access gateway. The proxy is part of the security boundary and must replace untrusted forwarded headers. See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for an operational checklist.

Before every upgrade, back up PostgreSQL, the complete Laravel `storage/` directory, and the application secrets required to decrypt stored credentials. See [`docs/UPGRADING.md`](docs/UPGRADING.md).

## Verification

```bash
php artisan test
./vendor/bin/pint --test
npm test
npm run build
npm run typecheck
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from test
docker compose -f compose.test.yaml down --volumes --remove-orphans
```

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
