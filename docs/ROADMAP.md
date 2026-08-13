# Disco Roadmap

GitHub Issues are the source of truth for actionable work. This document records direction and workflow only; issue labels and milestones carry current status.

Substantial interface work must also follow the shared [UI direction](UI_DIRECTION.md). Foundation primitives should land before page-specific redesigns, and visual references are for directional research, not assets or layouts to copy.

## Working Agreement

When asked to work on the next ready issue:

1. Select an open issue labelled `status:ready`.
2. Prefer `priority:high`, then `priority:medium`, then `priority:low`, then `priority:nice-to-have`.
3. Use the lowest issue number to break ties.
4. Never select an issue labelled `sequence:final` while any other issue remains open.
5. Replace `status:ready` with `status:in-progress` before implementation.
6. Keep the issue open if work is incomplete or blocked, and record the blocker.
7. Close the issue only after its acceptance criteria and verification steps pass.

Status labels are mutually exclusive. An issue with unresolved dependencies must use `status:blocked`, not `status:ready`.

## Current Phase

The current phase is public self-hosting readiness: neutral configuration, hardened images, documented deployment and recovery, automated verification, and a tested release process.

## Later Direction

After editorial coverage is reliable:

- Add persisted, selectable year and decade discovery lenses.
- Model typed MusicBrainz credit relationships for credit trails and collection gaps.
- Reassess Last.fm as a secondary similarity and tag signal after the lens pipeline exists.

Discogs follows the explicit source policy in the architecture decisions. Acoustic-analysis sources and Tautulli remain deferred until a concrete issue documents the need and source policy. Copied third-party reviews remain prohibited.

## UI Program

The editorial interface program applies shared foundations to album and artist detail, Home activity, catalog relationships, browse controls, Discover, Artists, lenses, provenance, actions, credits, and performance. See the shared [UI direction](UI_DIRECTION.md).

## Final Release Gate

A stable self-hosted release requires passing application and image tests, a clean secret scan, documented backup and rollback, and a successful upgrade rehearsal against a representative private installation.
