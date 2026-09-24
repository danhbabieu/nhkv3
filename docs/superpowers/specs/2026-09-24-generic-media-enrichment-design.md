# Generic NHK V3 Media Enrichment Design

**Date:** 2026-09-24
**Status:** Approved for implementation by the task owner
**Scope:** Generic Capture `MEDIA_ENRICHMENT` binding, canonical readback,
projection invalidation/readback, public/frontend completion and retry.

## Goal

When a user asks NHK to attach an image to any supported canonical/public owner,
the same governed Capture pipeline must resolve the owner, reconcile the
requested MediaUsage role, verify the canonical result, refresh required
projections and verify a required public surface before reporting `COMPLETE`.

The implementation must discover owner types and endpoint existence from the
active runtime registries. It must not encode a fixed list of Brand, Model,
Variant, Article, Knowledge or other owner names in the enrichment pipeline.

## Root cause

The current code has two incompatible MediaEnrichment paths. Typed bindings use
`MediaBindingService`, which resolves exact Authority targets, enforces
representative-slot cardinality, idempotency and MediaUsage readback. The
generic Capture path instead selects `subject_resolution.primary`, defaults to
`featured_primary`, and calls `MediaService::addUsage` directly. This bypasses
the binding operation lifecycle and does not preserve the requested role or
selection metadata.

The binding service itself only resolves Authority entities even though the
endpoint registry also supports `wp_post`, `media`, `video`, `knowledge`,
`source` and `evidence`. Consequently the generic pipeline cannot express all
canonical owners supported by the runtime.

The Capture final callback currently treats MediaUsage verification as a
sufficient generic result, while `CompletionCoordinator` independently
hard-codes public-capable owner types. Projection invalidation is emitted as an
event but is not represented as a required, verified completion gate. This
allows a canonical usage receipt and a stale or absent public projection to
diverge, and causes final readback to fail or the public surface to remain
unchanged.

## Architecture

### Capability model

Add a runtime capability contract built from registered endpoint/entity owners.
Each owner capability declares:

- exact endpoint resolver and canonical existence/readback strategy;
- supported MediaUsage roles and placement/slot policy;
- whether representative cardinality is enforced and its allowed active count;
- whether projection invalidation/rebuild is required and how it is read back;
- whether a public identity/surface exists and whether frontend verification is
  required;
- retry phase and idempotency behavior.

The capability registry is populated from the same endpoint/entity registry
used for canonical resolution. Owner-specific route/public checks are injected
as policies; the enrichment pipeline does not enumerate owner types. Unsupported
registry entries fail closed with an explicit capability diagnostic.

### Canonical binding

`MediaBindingService` becomes the single application boundary for Capture media
binding. It accepts exact target references for any registered endpoint that has
the MediaUsage capability. Authority targets continue to resolve through the
Authority repository; non-Authority targets resolve through their registered
endpoint resolver/repository. `wp_post` retains its native `<blog_id>:<post_id>`
identity and Article role policy.

The service accepts all roles registered by `MediaUsageRoleRegistry` when the
owner capability permits them. It preserves `selection_source` and
`selection_policy`; `USER_EXPLICIT` remains `PINNED`, and automatic selection
cannot replace a pinned representative. Representative replacement logically
retires/demotes the prior active usage and verifies the configured active-slot
cardinality after apply.

The operation remains idempotent by request fingerprint and Capture-bound
idempotency key. Replays return the existing completed operation, while failed
operations resume from their durable stage without creating duplicate Media,
MediaUsage or relation records.

### Completion contract

`MEDIA_ENRICHMENT` completion is calculated from capability-required evidence:

1. target canonical existence and Media canonical existence;
2. MediaUsage readback with exact target, role, usage and media identity;
3. canonical final readback for the target and usage;
4. projection invalidation/rebuild and projection readback when required;
5. public/frontend readback when the owner capability requires a public
   surface.

Canonical-only owners complete at step 3. Projection owners require step 4.
Public owners require step 5. An owner without a public surface is never
blocked by frontend verification. `FINAL_READBACK` remains mandatory and
returns typed fail-closed diagnostics such as missing canonical owner,
unverified MediaUsage, projection failure or stale public media.

Capture aggregation receives required owners from the resolved capability
packet, not from a fixed intent-specific owner list. Semantic owners unrelated
to MediaEnrichment, especially Article/Knowledge owners, are not required by a
pure media binding.

### Projection and public surface

All public entity, Article, Video, Knowledge and Media-facing read models that
display contextual media consume canonical `MediaUsage` through the shared
projection boundary. Binding completion invalidates the relevant projection and
records the invalidation/readback receipt. Legacy media fields are read-only
compatibility inputs where still required; no dual-write source of truth is
introduced.

Projection/readback verification uses the owner capability's role mapping. A
representative target verifies the active representative slot; Article and
other owners verify the role/placement they requested. Public verification
must prove that the expected canonical Media ID is present in the public read
model, not merely that a route exists.

### Retry and resume

Retry reuses the existing Capture ID, request fingerprint and staged Media IDs.
It resumes at the persisted failed phase, reuses completed binding operation
receipts, and re-runs only missing apply, projection or readback steps. A
changed target, role, Media or selection packet is an idempotency conflict.
Transient projection/frontend failures remain `FAILED_RETRYABLE`; successful
replay reaches `COMPLETE` only after all capability-required gates pass.

## Error handling and safety

- Invalid or ambiguous target references fail closed before mutation.
- Unsupported roles fail with a registered role/capability diagnostic.
- Missing canonical Media/owner/readback never promotes completion.
- Governance/Controlled Apply remains the only durable semantic mutation path.
- No direct WordPress writer, direct SQL semantic write, production mutation,
  hard delete, or fixture-specific exception is introduced.

## Test strategy

Regression tests are written before implementation and cover:

- Brand, Model, Variant, Classification, Knowledge, Article and every
  additional endpoint with registered MediaUsage capability;
- representative, featured_primary, inline_primary, inline_supporting,
  evidence and technical_detail where each capability permits them;
- successful canonical-only, projection-required and public/frontend-required
  completion;
- canonical readback failure, projection failure and stale public readback;
- representative replacement/cardinality and USER_EXPLICIT/PINNED protection;
- retry with the same Capture and no duplicate Media/Usage/relation;
- invalid target and unsupported role rejection;
- non-regression for IMAGE_ARTICLE, TEXT_ARTICLE, VIDEO, KNOWLEDGE_DELTA,
  KNOWLEDGE_REPAIR, Authority and relationship operations.

The Odo 30 attachment/Capture is an external acceptance fixture only. Its UUID,
URL and name are not referenced by implementation code or unit tests.

## Acceptance

Acceptance requires focused Media/Capture/Projection tests, the owner matrix,
unit and contract suites, guarded integration tests when `nhk_v3_test` is
available, PHP lint, `git diff --check`, secret review and a read-only public
fixture verification. No production deployment is part of this change.
