# Mobile scrolling profile

Profiled against the authenticated production dataset on 2026-07-25 with Chromium, a 390 x 844 viewport, 3x device scale factor, and 4x CPU throttling. Each route was allowed five seconds to settle, then scrolled vertically in 140px animation-frame steps; every horizontal rail was subsequently swiped in 60px animation-frame steps.

The baseline replayed the pre-fix rail handler, mobile header blur, off-screen painting, and coarse-pointer reveal animation against the same deployed Home DOM. This avoids comparing different collection data. Trace JSON remains local because it contains production URLs and timing metadata.

## Home comparison

| Measure | Before | After | Change |
| --- | ---: | ---: | ---: |
| Animation-frame intervals over 25ms | 14 | 10 | -29% |
| Function execution | 38.5ms | 31.5ms | -18% |
| Event dispatch | 31.8ms | 20.7ms | -35% |
| Layout | 14.2ms | 12.5ms | -12% |
| Paint | 213.9ms | 177.8ms | -17% |
| Worst function call | 3.01ms | 2.03ms | -33% |
| Worst event dispatch | 3.12ms | 1.07ms | -66% |
| Main-thread calls over 50ms | 0 | 0 | unchanged |

The roughly one-second maximum animation-frame interval in both Home traces was an idle/network interval while finite automatic paging settled, not a main-thread long task.

## Route review

Post-change traces completed without an animation-frame interval over 25ms:

| Route | Document height | Rails | Worst interval |
| --- | ---: | ---: | ---: |
| Discover | 6,747px | 0 | 9.9ms |
| Collection | 5,827px | 1 | 9.6ms |
| Beyond | 4,383px | 0 | 9.2ms |
| Artists | 3,769px | 1 | 8.8ms |
| Artist detail | 2,615px | 0 | 8.9ms |
| Album detail | 4,062px | 0 | 9.6ms |

## Changes

- Coalesce rail measurements to one animation frame and observe only the rail container.
- Contain horizontal overscroll while retaining both horizontal and vertical touch panning.
- Skip off-screen grid paint with intrinsic fallback sizing; DOM and accessibility order are unchanged.
- Remove sticky-header backdrop filtering on mobile and nonessential card reveals on coarse pointers.
- Retain reserved artwork ratios, lazy image loading, async decode, image fallbacks, and the existing reduced-motion override.

Desktop layout breakpoints and effects remain unchanged. Frontend tests, strict TypeScript, and the production Vite build provide the desktop regression gate.
