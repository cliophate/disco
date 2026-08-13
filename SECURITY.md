# Security Policy

## Supported Versions

Security fixes are provided for the latest release or the current default branch. Older releases may not receive patches. This project is maintained on a best-effort basis and does not offer a service-level agreement.

## Reporting a Vulnerability

Do not open a public issue for a suspected vulnerability or include secrets, private URLs, library data, or exploit details in public discussions.

Use GitHub's private vulnerability reporting for this repository. Include:

- the affected version or commit;
- a concise description and impact;
- reproduction steps or a proof of concept;
- relevant configuration with all secrets and personal data removed; and
- any suggested mitigation.

If private vulnerability reporting is unavailable, contact the maintainer through the private contact method on their GitHub profile and ask for a secure reporting channel. You can expect an initial acknowledgement when maintainers are available, but no fixed response or disclosure timeline is guaranteed.

## Deployment Security Boundary

Disco is designed for one trusted owner. It is not reviewed for untrusted multi-user or multi-tenant hosting.

- Never expose the application origin directly to the internet. Keep it on a private network behind a hardened HTTPS reverse proxy or access gateway.
- Configure the edge to replace, not append to, client-supplied forwarded headers. The application trusts proxy headers, so accepting forged headers at the origin breaks the deployment boundary.
- Restrict direct access to PostgreSQL, Redis, PHP-FPM, queue workers, and the scheduler.
- Keep `APP_DEBUG=false`, use secure cookies, and set `APP_URL` to the canonical HTTPS URL in production.
- Use a unique `APP_KEY`, database password, Redis password where applicable, Plex token, and dedicated least-privilege credentials for optional providers.
- Never commit `.env` files, tokens, machine identifiers, library UUIDs, logs, database dumps, artwork caches, or provider snapshots.
- Back up `APP_KEY` securely. Database-managed provider credentials are encrypted with it and cannot be recovered without it.
- Keep Plex identity pinning configured and leave insecure HTTP disabled. If private infrastructure requires HTTP, treat that network as trusted and understand that Plex credentials and media are not protected in transit.
- Apply operating-system, runtime, proxy, database, Redis, PHP, Node, and dependency security updates promptly.

See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for the full production checklist.
