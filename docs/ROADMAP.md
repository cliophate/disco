# Disco roadmap

The product has one job: find albums worth previewing on Qobuz, using listening history and the Plex collection.

## Current scope

1. Find new artists through taste-weighted ListenBrainz artist radio. Exclude familiar and owned artists, enforce a seed diversity cap and explain each pick.
2. Show personal new releases and releases by the current candidate artists.
3. Offer unheard albums by artists the owner already plays.

Keep weekly batches, pass feedback and pick-state history. Prioritize import completeness and identity matching over more recommendation sources. Genre coverage is too sparse to rank with.

## Follow-up evidence

Assess whether the ten-album weekly batch has enough unfamiliar artists after several synthetic-tested iterations and private owner use. Tune the lifetime-listen threshold, cooldown, popularity penalty and seed cap through environment settings. Provider failures should remain visible in admin and preserve the last successful batch.

A future secondary recommendation source must satisfy the same artist exclusions, Album/EP rules, primary credit checks and repeat policy. Collaborative-filtering recording recommendations are currently removed.

## Boundaries

No embedded player, Plex writes, library browsing, search, discography pages, biographies, narratives, credits, editorial feeds, lists, follows or notifications. Do not rebuild these as discovery prerequisites. No live-provider calls during page rendering and no live payloads in tests or source control.

GitHub issues track follow-up work. The [UI direction](UI_DIRECTION.md) is the visual contract; [ADR 0006](adr/0006-album-discovery.md) records the discovery pipeline.
