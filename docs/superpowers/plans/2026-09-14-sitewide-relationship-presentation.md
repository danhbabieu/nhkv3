# Site-wide relationship-driven presentation — implementation plan

## Boundary

This checkpoint is additive and read-only with respect to semantic stores. It
reuses the existing Entity Profile, dossier, Graph relation and Media/Video
projection seams. It does not create Authority entities, Graph edges, Public
Identities or governed proposals, and it does not alter existing routes.

## Vertical slice

1. Add one application-level newest-first ordering helper with explicit
   published/created/tie-break precedence; use it for Media, Video, Authority
   profile archives and Knowledge archive projections.
2. Extend canonical Media and Video read models with persisted timestamps so
   archive ordering does not infer dates from names, UUIDs or array position.
3. Expose Clock Type as the public “Nhóm đồng hồ” profile label while keeping
   `clock_type`, `classification` and `/loai-dong-ho/` unchanged.
4. Add the existing profile-driven Clock Group archive to global fallback
   navigation and the homepage as a real visual card module. Cards come from
   the public collection query; no entity is hard-coded.
5. Preserve existing generic dossier, hierarchy, direct/derived provenance,
   Article, Media and Video behavior; add only the presentation labels and
   tests needed for the shared seam.
6. Run focused/unit/contract/lint/documentation gates, update the execution
   state and migration ledger, then deploy/live-read only if the repository’s
   canonical deployment preflight is available and all required gates pass.

## Explicit non-goals

No data backfill, relation creation, route allocation, semantic mutation,
public-url reproject, Article/Media/Video write, database reset, or replacement
of existing page-family contracts. Entity families without an existing
profile/query contract remain reported as partial/deferred rather than being
represented by guessed data.
