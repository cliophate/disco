# Disco UI direction

Disco is one weekly album selection for one owner. The page leads with unfamiliar artists, then new releases, then unheard albums by artists the owner plays. The design should make choosing an album easy.

## Layout

Route `/` is titled "This week". Navigation contains only This week and Admin, plus theme and sign-out controls. Login and minimal owner admin are the only other pages.

Use the existing wide centered canvas, capped at 1600px, with at least 32px desktop gutters. Mobile starts as a single column; cover grids gain columns as space permits. Keep section order and DOM order identical. Use thin rules and whitespace between sections. Do not add a dashboard, counters, carousel or infinite feed.

Each section shows its current batch first. Put earlier unresolved picks in a native, closed-by-default "Earlier" disclosure. Explain missing credentials, missing identified listening history, empty batches and overdue or partial refreshes in plain language. Never hide the last successful batch because a provider failed.

## Type and color

Keep the locally shipped Newsreader display serif for page headings, section headings and album titles. Use the system sans-serif stack for artists, reasons, metadata and controls. Desktop page headings use 64 to 80px, section headings 32 to 42px and card titles 18 to 24px. Metadata stays at least 12px. Long and unbroken titles wrap within their columns.

Retain the established tokens. Light mode uses paper `#fbfaf7`, ink `#17191f`, cobalt `#3158d5`, coral `#b7463b` and raised neutral `#edf1f5`. Dark mode uses paper `#15171d`, ink `#f2ecdf`, cobalt `#7895ff`, coral `#ff8172` and raised neutral `#292f3a`. Cobalt marks actions, while coral marks release timing and small active indicators. Preserve contrast and hierarchy in both themes.

## Pick cards

Show a square cover, album title, artist, year, Album or EP type, one reason line, and New, In Plex or Played state. New-release cards also say Released or Upcoming with the date. Use the local artwork cache with a deterministic generated fallback, including on image failure. Reserve square dimensions and lazy-load images.

"Open in Qobuz" is the primary link. Exact MusicBrainz URL relationships take precedence; the fallback says that it opens a Qobuz search. "Passed" is the only manual pick action. Show saving and failure states. Do not nest buttons in links or make the whole card a link.

Reasons name artists the owner plays, such as "Similar to Seed One and Seed Two". Do not show scoring internals or provider badges on cards. There are no playback, follow, wishlist or notification controls.

## Accessibility and requests

Controls have at least 44px touch targets, visible keyboard focus and descriptive names. Support native disclosure keyboard behavior, skip navigation and a clear heading hierarchy. Respect reduced motion. Avoid hover-only actions, layout shifts and inert controls.

Page rendering, passing and theme changes make no provider requests. Jobs prepare artwork and Qobuz links. Admin token tests are the explicit exception for checking credentials.

Review light and dark themes, narrow and wide layouts, long titles, failed artwork, empty and stale states, keyboard order and mutation errors. Tests use synthetic albums only.
