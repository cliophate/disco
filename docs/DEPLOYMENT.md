# Deployment

Disco is a single-owner Laravel application. This repository supplies production PHP-FPM and Nginx image definitions and a reference [`compose.production.example.yaml`](../compose.production.example.yaml). Adapt it to your platform rather than treating it as a complete security boundary.

## Required Services

- the Disco PHP-FPM application and bundled web-server image, or equivalent PHP/Nginx services;
- PostgreSQL 18;
- Redis 8 for cache, locks, and queues;
- a default queue worker;
- an `admin` queue worker with a timeout of at least 3,600 seconds;
- one scheduler process running `php artisan schedule:work`, or cron invoking `php artisan schedule:run` every minute;
- persistent storage for the complete Laravel `storage/` directory; and
- a reachable Plex Media Server with a dedicated music library.

Run only one scheduler. Laravel's distributed locks require all application, worker, and scheduler processes to share PostgreSQL and Redis.

## Network Boundary

Disco does not support exposing its origin directly. Put the origin, PostgreSQL, Redis, PHP-FPM, workers, and scheduler on private networks. Publish only a hardened HTTPS reverse proxy or access gateway.

The edge must:

- terminate TLS with a valid certificate and redirect HTTP to HTTPS;
- optionally enforce an additional identity-aware access policy or private-network/VPN access;
- replace client-supplied `Forwarded` and `X-Forwarded-*` headers with values derived from the trusted connection;
- forward the canonical host and HTTPS scheme;
- limit request sizes and apply reasonable connection and request timeouts; and
- preserve streaming and byte-range responses for original-file playback.

Disco trusts proxy headers from its immediate network. Firewalling the origin and sanitizing forwarded headers are therefore mandatory, not optional hardening.

## Configuration

Start from `.env.production.example`, but supply deployment-specific values through a secret manager or a root-readable environment file outside the release directory.

At minimum:

- set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` to the canonical HTTPS URL;
- generate a unique `APP_KEY` with `php artisan key:generate --show` and store it as a durable secret;
- set `SESSION_SECURE_COOKIE=true` and `SESSION_SAME_SITE=strict`;
- use unique PostgreSQL and Redis credentials and restrict them to private networks;
- configure a verified HTTPS `PLEX_URL`, token, expected machine identifier, expected library UUID, and library name;
- leave `PLEX_ALLOW_INSECURE_HTTP=false`; and
- set `ARTWORK_STORAGE_PATH` inside the persistent `storage/` tree unless an equally durable private path is used.

Use dedicated, least-privilege provider credentials. Never bake secrets into images or commit them. Provider credentials entered through the administration interface are encrypted in PostgreSQL using `APP_KEY`; environment credentials remain the fallback.

Optional providers are disabled by leaving their credentials or enable flags empty. Review their current terms and privacy implications before enabling them.

## Initial Release

1. Build immutable application and web images from a reviewed commit.
2. Provision PostgreSQL, Redis, private persistent storage, workers, scheduler, and HTTPS proxy.
3. Install configuration and secrets without placing them in image layers or source control.
4. Run `php artisan migrate --force` once from the application image.
5. Run `php artisan disco:owner owner@example.com --name="Owner"` interactively. The command prompts for the password and refuses a second account.
6. Run `php artisan disco:plex-sync --dry-run` and verify the expected server and library.
7. Run `php artisan disco:plex-sync`, then start the web, worker, and scheduler processes.
8. Verify `GET /up`, login, a library page, queued work, scheduled work, and an artwork request through the public proxy.
9. Test a backup and restore before treating the installation as durable.

For the reference Compose stack, create bind-mount directories under `data/`, make `data/storage` writable by container UID 33, and validate before starting:

```bash
cp .env.production.example .env.production
mkdir -p data/storage data/postgres data/redis
sudo chown -R 33:33 data/storage
set -a; . ./.env.production; set +a
docker compose -f compose.production.example.yaml --env-file .env.production config --quiet
docker compose -f compose.production.example.yaml --env-file .env.production build --pull
docker compose -f compose.production.example.yaml --env-file .env.production up -d postgres redis
docker compose -f compose.production.example.yaml --env-file .env.production run --rm app php artisan migrate --force
docker compose -f compose.production.example.yaml --env-file .env.production up -d
```

For an initial metadata baseline, the normal scheduler can fill data gradually. Operators may run the bounded commands shown by `php artisan list disco`; avoid unbounded provider requests unless their impact is understood.

## Process Examples

Run the normal and long-running queues as separate supervised processes:

```bash
php artisan queue:work redis --queue=default --sleep=3 --tries=3 --timeout=120
php artisan queue:work redis-admin --queue=admin --sleep=3 --tries=1 --timeout=3600
php artisan schedule:work
```

Restart workers after every deployment so they load the new code. Tune retry and process-manager shutdown values so jobs are not killed before their worker timeout.

## Backups

Back up these as one recoverable set:

- a consistent PostgreSQL dump or snapshot;
- the complete Laravel `storage/` directory, including private artwork and framework state;
- `APP_KEY` and all environment/secret-manager values;
- deployment configuration needed to reconstruct the proxy, workers, and scheduler; and
- Redis only if preserving queued work and transient cache state is operationally important.

PostgreSQL and `storage/` are the primary data set. Without `APP_KEY`, encrypted database credentials are unreadable. Store backups encrypted, restrict access, define retention, and test restoration regularly. Do not rely on container-local filesystems.

## Operations

- Monitor `/up`, application and proxy errors, queue failures, worker restarts, scheduler execution, PostgreSQL capacity, Redis health, and persistent-storage usage.
- Keep logs private; they may contain catalog names, paths, provider errors, and network details even though application code avoids logging credentials.
- Rotate a credential immediately if it may have reached logs, shell history, an issue, or source control.
- Run `php artisan schedule:clear-cache` only when a terminated scheduler left a stale mutex and no corresponding job is still running.
- Follow [`UPGRADING.md`](UPGRADING.md) for releases and rollback planning.
