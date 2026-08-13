# ADR 0001: Use a modular monolith

## Status

Accepted for the initial implementation.

## Decision

Disco is one Laravel application with explicit Catalog, Providers, Resolution, Library, Activity, Personal, Discovery, Search, and Operations module boundaries. React consumes a versioned same-origin REST API.

PostgreSQL is authoritative. Redis is limited to queues, caches, rate limits, and short locks. A graph projection may be derived in PostgreSQL; a graph database and microservices are not justified by the collection size or query depth.

## Consequences

- Catalog writes and provenance can remain transactional.
- Deployment and backup remain suitable for one self-hosted stack.
- Module boundaries must be enforced in code review and tests rather than network calls.
- Expensive page views may receive rebuildable projections without changing the source of truth.
