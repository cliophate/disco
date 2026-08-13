# ADR 0005: Proposed embedded Plex audio gateway

## Status

Accepted. This amends ADR 0002 only for authenticated playback and the bounded Plex timeline writes required to record that playback.

## Context

Disco currently exposes exact links that open music in Plex but deliberately performs no playback and never sends a Plex token to the browser. Embedded audio is technically possible for original media formats supported by the browser, but a browser-facing Plex URL would disclose a broad credential and bypass Disco's authorization boundary.

No maintained open-source component provides a safe drop-in Plex audio player for this architecture. Media Chrome and Vidstack provide accessible controls, while PlexJS and Python-PlexAPI are protocol references rather than an appropriate browser runtime. The Plex token and upstream media path must remain server-side.

Browser playback also cannot promise bit-perfect output. The browser, operating-system mixer, Bluetooth path, or audio device may resample or alter the signal. Disco can accurately report that the original file was direct-played, but it cannot claim exclusive-device output.

## Decision

An initial player should use native `<audio>` with optional Media Chrome controls and a local queue. React creates an opaque, short-lived playback session and receives only a same-origin stream URL:

```text
React -> POST /api/playback/sessions
      -> /api/playback/sessions/{opaque-id}/stream
      -> authenticated Disco gateway
      -> pinned Plex server with token in an upstream header
```

The gateway must accept a Disco entity or Plex-item identifier, never an arbitrary upstream URL. It resolves a stored, validated media part, verifies the configured Plex machine and library, disables redirects, adds the Plex token only to the upstream request, and streams the response without buffering it into application memory.

The first implementation supports:

- Direct playback of the original FLAC, ALAC, or WAV media part only when browser capabilities confirm support.
- No Plex transcoder endpoints and no lossy or lossless fallback conversion.
- A disabled playback action with an **Open in Plex** fallback when the original format is unsupported.
- Accurate labeling of original-file direct play.
- HTTP byte ranges, seeking, cancellation, a local queue, and Media Session lock-screen metadata.
- Bounded Plex timeline and scrobble writes so playback is counted in Plex history.

DSD and any other browser-incompatible source remain unavailable in Disco. Gapless playback and uninterrupted mobile background playback are not MVP guarantees.

## Security Requirements

- Plex tokens never appear in HTML, JSON, JavaScript, browser-visible URLs, exceptions, analytics, or access logs.
- Playback sessions are opaque, short-lived, bound to the authenticated user, and limited to authorized active-library items.
- Upstream origins, machine identity, library identity, media-part IDs, and paths are validated and pinned.
- Redirects, encoded traversal, arbitrary ranges, and unbounded concurrent streams fail closed.
- Session creation is CSRF-protected and rate-limited; concurrent streams are bounded per user.
- Timeline writes are derived from a server-side session, clamped to the synchronized track duration, rate-limited, and limited to start, progress, pause, stop, and one scrobble.
- Playlist, library, metadata, rating, deletion, and arbitrary Plex writes remain forbidden.
- Responses use `Cache-Control: private, no-store`; CSP explicitly permits only same-origin media.
- The gateway forwards valid single byte ranges and preserves `200`, `206`, `416`, `Content-Range`, `Accept-Ranges`, `Content-Length`, `Content-Type`, `ETag`, and `Last-Modified`.

## Storage Changes

Plex synchronization needs a typed media-parts projection rather than trusting free-form metadata at playback time. At minimum it must store the media/part ID and key, container, audio codec, channels, bit depth, sample rate, bitrate, size, and media version. Authorization and upstream path construction must use these typed records.

## Delivery Plan

1. Capture representative Plex media-part metadata for FLAC, ALAC, WAV, and DSD from the deployed server.
2. Add typed media-part ingestion and validation.
3. Implement same-origin original-file streaming with byte-range tests.
4. Add native audio controls, local queue, and Media Session integration.
5. Add bounded timeline/scrobble reporting and verify Plex play history.
6. Move the byte path from PHP-FPM to an internal Nginx `auth_request` gateway or dedicated streamer if load testing shows worker exhaustion.
7. Consider Plex play queues and playlist changes separately because they remain outside the accepted write boundary.

## Acceptance Gates

- Current Chrome, Firefox, Edge, desktop Safari, and iOS Safari pass play, pause, seek, resume, and range tests.
- Hostile media keys, redirects, traversal, unauthorized items, and machine/library mismatches are rejected.
- Direct-play labels match the synchronized original media part and no request reaches a Plex transcode endpoint.
- Completed Disco playback appears once in Plex history with bounded progress updates.
- Disconnecting a client cancels upstream transfer.
- Concurrent stream testing does not exhaust PHP or proxy workers.
- A credential scan confirms that no Plex token reaches browser-visible surfaces or logs.

## Open-source Components

- [Media Chrome](https://github.com/muxinc/media-chrome), MIT: preferred optional control layer.
- [Vidstack](https://github.com/vidstack/player), MIT: credible alternative, larger than the MVP requires.
- [hls.js](https://github.com/video-dev/hls.js), Apache-2.0: only if a future HLS fallback is needed.
- [PlexJS](https://github.com/LukasParke/plexjs), MIT, and [Python-PlexAPI](https://github.com/pushingkarmaorg/python-plexapi), BSD-3-Clause: protocol references only.

Native `<audio>` remains the preferred playback engine for the first phase. Howler.js and a Web Audio pipeline are not recommended for long lossless media because they add buffering, memory, and background-playback risk without solving the Plex trust boundary.
