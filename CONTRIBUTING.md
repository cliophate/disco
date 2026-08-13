# Contributing

Contributions are welcome when they preserve Disco's single-owner, self-hosted scope and documented security boundaries.

## Before You Start

- Search existing issues and pull requests.
- Open a proposal before large features, schema changes, new external providers, or changes to Plex read/write behavior.
- Do not submit secrets, private service addresses, personal listening data, copyrighted media, live provider payloads, or unsanitized logs.
- Use synthetic, minimal fixtures for provider integrations.
- Review third-party terms, licensing, attribution, retention, and privacy before proposing a new data source.

## Development

Follow the local setup in [`README.md`](README.md). Keep changes focused and follow the existing architecture and UI decisions in [`docs/adr/`](docs/adr/) and [`docs/UI_DIRECTION.md`](docs/UI_DIRECTION.md).

Run the relevant checks before opening a pull request:

```bash
php artisan test
./vendor/bin/pint --test
npm test
npm run build
npm run typecheck
```

Database and sync changes should also pass:

```bash
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from test
docker compose -f compose.test.yaml down --volumes --remove-orphans
```

## Pull Requests

- Explain the problem, approach, security/privacy impact, and verification performed.
- Add or update tests for behavior changes.
- Update public documentation when configuration or operator steps change.
- Keep unrelated refactoring out of the pull request.
- Preserve the Plex token boundary: browser responses and logs must never expose credentials or arbitrary upstream URLs.
- Keep normal page rendering independent of live provider requests.

By participating, you agree to follow the [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md).
