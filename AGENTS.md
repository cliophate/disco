# Disco

This repository is public. Hebe's private fonts and settings overlay stays in the hebe repository, never here. Read README.md and `docs/`.

Run `npm run typecheck && npm test` before pushing.

The live service runs from `/home/clio/Docker/DiscoNext/build-context`, exported from an exact commit plus Hebe's overlay, a pinned export recorded in
`/home/clio/Docker/maintenance/source-releases.json`. A push does not
deploy. Follow `/home/clio/Docker/AGENTS.md` for rollouts, and restart
nothing without the user's go-ahead.
