# Westminster music/audio unblock

## Goal

Make the existing universal Music dossier fail closed and Media-owner-backed
for public audio, while preserving the current research blocker for the
Westminster score and historical bell tuning.

## Constraints

- No new Music entity, template, semantic writer, Media owner, migration,
  canonical mutation, production deployment or push.
- Do not promote the Grove candidate or Parliament pitch disagreement into a
  verified historical score.
- Use only the existing `PublicMediaAssetDelivery` and governed MediaAsset
  identity for playable audio.
- Keep direct/derived Graph provenance reader-facing without exposing graph
  diagnostics.

## Implementation slices

1. Add RED tests for partial reference status, MediaAsset-backed playable
   audio, invalid/private/non-audio delivery, and reader-facing relation
   provenance labels.
2. Harden the Music reference aggregate so mixed valid/invalid packets are
   `PARTIAL`, not an unconditional `AVAILABLE`, while retaining valid
   components.
3. Extend the existing Media delivery boundary with validated audio bytes and
   an opaque governed public audio route; wire the projection to resolve an
   internal asset id without leaking it.
4. Wire the existing frontend bootstrap through a read-only reference packet
   filter and preserve generic rendering for all Music entities.
5. Render public relation context and direct/derived labels; keep raw
   predicates, hop counts and internal ids out of the public packet.
6. Run focused tests, PHP/JS lint, contract tests, Full Unit, available
   Integration tests, diff/secret checks, and read-only browser QA.

## Verification boundaries

- Score remains `SCORE_UNVERIFIED` unless an authoritative notation witness
  proves notes, order, rests, durations, octave and edition provenance.
- Piano and Bell outputs remain blocked without a verified score and governed
  MediaAsset; no historical Parliament audio is reused.
- TEST runtime authorization is required for integration mutation paths; no
  such path is invoked here.
