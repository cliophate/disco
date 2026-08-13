# Disco UI Direction

## Design Thesis

Disco is an editorial music atlas, not an administration dashboard, a generic streaming client, or a pixel-for-pixel copy of another product. The interface should make a personal collection feel explorable through music identity, artwork, typography, relationships, and attributed context.

The hierarchy is:

1. Music identity: title, artist, artwork, date, and collection status.
2. A small number of real actions and essential facts.
3. Editorial context and explicit discovery rationale.
4. Related people, releases, credits, and catalog detail.
5. Provider provenance and diagnostics when they help establish trust.

The July 2026 visual references use Roon as directional research. Adopt the information hierarchy and editorial confidence, not its exact typeface, colors, icons, copy, imagery, or proprietary behavior.

## Product Character

- Album-first and image-led.
- Editorial rather than dashboard-like.
- Spacious, calm, and information-dense only where detail warrants it.
- Factual and source-aware without covering browse surfaces in provider badges.
- Distinctively Disco through cobalt interactive elements, coral editorial accents, strong generated artwork, and restrained motion.
- Equally intentional in light and dark themes.

## Foundations

### Typography

- Use a real, locally shipped, redistribution-safe display serif for large music titles and editorial headings.
- Use the system-oriented sans-serif stack for navigation, controls, metadata, and body copy.
- Display type may be expressive; body copy must remain highly readable.
- Long-form text should normally stay between 55 and 75 characters per line with generous leading.
- Long or unbroken titles must wrap without clipping or widening the page.
- Desktop page titles normally use 64-80px display type; section headings use 32-42px; card titles use 18-24px.
- Body copy uses 16-18px where space permits, interface controls use 14-16px, and rendered metadata must not fall below 12px.

### Color

- Use a warm white or quiet neutral canvas in light mode and a deep neutral canvas in dark mode.
- Keep cobalt as the primary interaction color.
- Use coral primarily for active indicators, editorial eyebrows, and small emphasis.
- Use pale blue-gray or neutral raised panels for contextual cards and facts.
- Preserve semantic hierarchy in dark mode rather than mechanically inverting light mode.

### Spacing And Structure

- Prefer generous whitespace, thin rules, and typographic grouping over boxed panels around every section.
- Use a consistent wide page grid with readable inner columns.
- Detail prose and fact sidebars should align to shared columns.
- Avoid filling every viewport with a full-bleed color block.
- Use the existing centered page canvas at a 1480-1600px content maximum. Normal desktop gutters are at least 32px and grow toward 48-72px on large displays.
- Build major desktop compositions on the shared 12-column grid, then assign nested 5/7, 4/8, or full-width regions without widening reading prose.

### Navigation And Controls

- Keep global navigation compact and subordinate to page identity.
- Separate destinations such as Artists from cross-catalog actions such as Search.
- Use thin active-tab underlines for major views.
- Use outlined filter chips with counts for actual filters; do not use pills as generic decoration.
- Use explicit `Previous`, `Next`, and `More` controls for finite rails when they improve discoverability.
- Never display inert playback, rating, bookmark, add, or overflow controls.

## Reusable Component Families

Build shared primitives before one-off page treatments:

- `EditorialHeader`: eyebrow, display title, supporting identity, optional artwork or media field, real actions.
- `DetailTabs`: accessible route-backed or state-backed tabs with an underline indicating the active state.
- `FactList`: compact definition list for dates, formats, labels, runtimes, locations, and status.
- `FilterBar`: tabs, outlined count chips, sort, and filter disclosure.
- `CoverCard`: square artwork with date, title, artist, and optional compact action.
- `EditorialTile`: text-led card with a smaller image and one useful fact or attributed excerpt.
- `EntityPortraitLink`: related artist/person portrait with a canonical link.
- `ActivityRail`: finite mixed album/artist activity with explicit controls and mobile-safe scrolling.
- `CollectionStat`: large count, label, and restrained icon.
- `MasonryFeed`: deliberately varied card spans with stable DOM order and a linear mobile presentation.

Components should expose semantic variants rather than accumulating page-specific class strings.

## Album Detail

Compose album detail as three layers:

1. Identity header: cover, date, large title, artist, collection state, real actions, and a concise genre set.
2. Editorial body: attributed description in a wide reading column with essential release facts in a compact sidebar.
3. Catalog body: track list, grouped credits, holdings/editions, recommendation evidence, and source provenance.

Guidelines:

- `Open in Plex` is the primary owned-album action when its target is exact.
- Qobuz remains a clearly labeled external destination, not a playback promise.
- Move technical metadata status and provider lists away from the primary identity header.
- Link artists and credited people only when canonical identities exist.
- Keep provider attribution next to provider-derived prose and consolidate technical provenance in a Sources section.
- Track rows should remain compact, scan-friendly, and readable with zero, one, or multiple featured artists.

## Artist Detail

Use an adaptive editorial header:

- Use a cinematic banner only when a suitable, rights-compatible, sufficiently large image exists.
- Otherwise use the current portrait or deterministic generated fallback without stretching it.
- Keep the artist name dominant and actions minimal.
- Separate Overview and Discography when both have enough content; do not add empty tabs.
- Present biography in the main reading column and dates, area, type, and curated links in a fact sidebar.
- Show genres as navigable/filterable vocabulary only when the destination exists.
- Present owned and outside-library albums in one coherent visual language, with clear collection states.
- Place secondary external links in a labeled disclosure or grouped section, not an unbounded header cloud.

## Home

Home is a concise personal dashboard, not the full discovery feed.

- Lead with one deterministic daily feature.
- Use collection counts as calm orientation, not administration metrics.
- Add finite recent activity and selected lens modules where the data is trustworthy.
- Mix albums and artists only when the section label explains the relationship.
- Make section headings actionable when a larger bounded view exists.
- Prefer a few strong modules over many nearly identical horizontal rails.

## Discover

Discover is a finite editorial feed distinct from Home.

- Mix owned recommendations, Beyond albums, artists, attributed editorial cards, genres, and later upcoming-release modules.
- Use deliberate card-size variation: small identity cards, medium editorial tiles, wide image-led features, and occasional tall context cards.
- Every card needs a clear content type and one useful reason, fact, or excerpt.
- Preserve a logical DOM and keyboard order even when desktop columns appear masonry-like.
- Collapse to a simple ordered feed on mobile; do not reproduce desktop masonry through absolute positioning.
- Avoid copied third-party prose, unlicensed imagery, infinite-scroll engagement patterns, and provider-pill clutter.

## Indexes And Lens Pages

- Album and artist indexes should support stable ordering, bounded pagination, and context-preserving back navigation.
- Use cover/portrait grids for browsing and editorial tiles only when explanatory copy adds value.
- Filters must be real, count-aware, keyboard accessible, URL-addressable where practical, and removable.
- Tabs should represent meaningful datasets such as Albums and Singles, not empty placeholders.

## Data And Action Integrity

- No live provider call may occur during normal page rendering.
- Do not infer credits, relationships, playback, availability, ratings, or collection state for visual completeness.
- Do not reproduce editorial text or imagery merely because it appears in a visual reference.
- Provider-derived content must meet the attribution, license, freshness, and source-link requirements defined by its source policy.
- Hide unavailable actions instead of rendering disabled imitations of another product.

## Responsive, Accessible, And Performant Behavior

- Start with a linear mobile reading order, then enhance into desktop grids.
- Keep touch targets at least 44 CSS pixels where practical.
- Tabs and rails must support keyboard operation and visible focus.
- Avoid nested interactive elements and card-wide links containing secondary buttons.
- Reserve media dimensions to prevent layout shifts.
- Lazy-render and lazy-decode off-screen feed media while preserving semantic order.
- Profile vertical scrolling and horizontal gestures before adding blur, parallax, reveal, sticky, or masonry effects.
- Respect `prefers-reduced-motion`; motion must never be required to understand state.

## Review Checklist

Verification for every substantial UI issue should cover:

- light and dark themes;
- mobile, tablet, and desktop widths;
- short, long, and unbroken titles;
- real artwork, generated fallbacks, and artwork failure states;
- sparse, complete, and missing metadata;
- keyboard order, focus, headings, landmarks, and link purpose;
- no inert controls, nested links, or provider calls;
- source attribution and license requirements;
- scrolling, image decoding, layout shift, and reduced motion.

## Delivery Order

1. Establish the typography, spacing, controls, and reusable editorial primitives.
2. Recompose album and artist detail pages with those primitives.
3. Apply the same card and filter language to indexes and full lens pages.
4. Build Discover as the mixed editorial feed.
5. Add activity and relationship modules only after their data contracts are trustworthy.

GitHub Issues remain the source of truth for execution and dependencies. This document is the durable visual contract those issues should reference.

## Execution Map

New interface work should establish shared typography, spacing, and primitives before applying them to detail pages, indexes, discovery feeds, activity modules, and catalog relationships. GitHub Issues carry the current implementation order.
