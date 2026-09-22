# P0 Capture → Subject → Article Media → Visual Support → Publication Design

## Status

Design approved conversationally on 2026-09-22. This document is the architectural handoff for implementation planning; it does not authorize live or staging mutation.

## Goal

Make the canonical Capture-to-publication workflow generic and fail-closed for every registered Authority subject and Article Media selection. An explicit canonical subject remains primary over incidental body entities; explicit current Article Media remains primary over stale or global reuse; optional Visual Support cannot invalidate a valid Article; and native WordPress route readiness is separated from semantic Public Identity.

## Non-goals

- No Odo-, Westminster-, post-, attachment-, or fixture-specific production branch.
- No migration, import, backfill, merge, deletion, direct SQL writer, Governance bypass, or weakening of staging scope verification.
- No new Authority type, Graph predicate, relation, Media role, facet, feature key, or public identity vocabulary.
- No automatic promotion of Article Media to semantic representative, evidence, Graph relation, or Knowledge fact.
- No live deployment, canary retry, or staging/production mutation in the local implementation phase.

## Governing constraints

- `wp_posts` remains the sole owner of native Article title, body, editorial metadata and editorial URL.
- Authority owns canonical semantic subjects; Media, MediaAsset and MediaUsage remain distinct boundaries.
- Governance remains the only owner of durable semantic mutation and controlled apply.
- Every durable operation preserves canonical UUID/stable key, revision, idempotency, provenance, readiness, public identity and fail-closed behavior.
- Existing Constitution and active contracts outrank this design. Any conflict is `CONSTITUTION_CONFLICT` and stops at the applicable human gate.

## Current findings

The repository already contains several required primitives: `SubjectResolutionPacket`, Capture persistence/read-back, explicit Article Media precedence work, Capture-bound MediaUsage staging admission, Visual Support requirement persistence, and canonical publication-context reconstruction. The remaining system risk is convergence: some paths still construct resolution inputs from multiple sources without an explicit source rank, Visual Support calls are not fully guarded at the coordinator boundary, and the end-to-end contract needs tests proving packet identity, Media read-back, route phases and fail-soft enrichment together.

## Design

### 1. One source-ranked subject resolution policy

Introduce one application-level resolution entry point used by Capture and every downstream consumer. The resolver accepts typed locator input and source buckets rather than one flattened hint list:

1. explicit canonical UUID;
2. explicit stable key;
3. explicit `subject_hints`, preserving caller order;
4. explicit title/topic subject;
5. extracted body/entity mentions as fallback only.

Within a bucket, exact UUID/stable-key identity wins, then exact canonical name or alias. Fuzzy or substring discovery is never allowed to outrank an explicit source. If an explicit hint bucket is non-empty and no hint resolves, the result is `unresolved` or `review_required`; body mentions cannot promote themselves to primary. Multiple resolved explicit hints retain deterministic caller order unless a higher-precedence UUID or stable key selects one identity. Ambiguity and contradiction remain explicit diagnostics.

`SubjectResolutionPacket` is the immutable handoff. It stores status, canonical subject UUID, entity type, stable key, canonical name, revision, match reason, source rank and bounded diagnostics. Capture persists and rehydrates it on retry/continuation. Article reconciliation, Media reconciliation, Visual Support, Knowledge/Graph planning, publication review and final read-back consume the packet and must not independently rerun discovery. Server-owned enrichment may replace the packet only through an explicit, validated handoff that is persisted before downstream work resumes.

### 2. Explicit Article Media wins without semantic promotion

Article Media reconciliation consumes a normalized current-Capture selection packet. Selection provenance is one of `USER_EXPLICIT`, `SYSTEM_AUTO` or `HISTORICAL_REUSABLE`; the effective precedence is exactly that order. A current explicit selection is retained even when semantic subject suitability is unavailable, while an incompatible selection remains visible as a bounded diagnostic and cannot be silently replaced by history.

For a new Article, the coordinator binds the explicit Media to requested Article slots through the existing MediaUsage role registry. It must not import stale featured/inline placements or global reusable Media when current explicit selection is present. With no current explicit Media, only exact eligible scoped reuse may be considered; otherwise the required slot remains missing or uses the existing governed placeholder policy.

Article Media read-back must prove both:

- canonical MediaUsage endpoint/role/placement and revision;
- native WordPress attachment state, with `featured_primary` attachment equal to the selected Media attachment.

A mismatch is a non-success and cannot be reported as a completed Capture. These operations continue through Proposal, approval/policy, eligibility, Controlled Apply and read-back; standalone internal MediaUsage writes remain fail-closed without a valid scope.

### 3. Capture-bound MediaUsage admission

When Capture owns the exact Article Media request, it issues a server-owned immutable child admission packet. The packet binds Capture ID and request fingerprint, Article owner endpoint, Media UUID and revision, registered role, placement key, selection provenance, operation/payload fingerprint, expected Article owner revision/state, idempotency key, expiry and current documentation/build checkpoint.

The packet is created only by the canonical Capture flow and is verified by the existing staging guard. Client-authored tokens are never accepted as authority. The normal lifecycle remains Proposal → approval/policy → eligibility → Controlled Apply → canonical MediaUsage read-back → native WordPress projection read-back. Scope absence, stale revision, mismatched owner, mismatched Media, changed payload, expired packet or changed runtime state fails closed with a precise diagnostic.

### 4. Visual Support is optional enrichment

The Visual Opportunity detector may produce candidates, but the coordinator validates each candidate against the registered subject, scope, facet, feature key and visual intent before creating a `VisualSupportRequirement`. Invalid or unregistered candidates are recorded as bounded diagnostics or `REVIEW_REQUIRED` and skipped. A missing optional requirement does not block Article ownership, semantic read-back, MediaUsage or publication unless the active Article policy explicitly marks that exact slot required.

Article Media remains editorial Media independently of Visual Support. It is never automatically converted into a representative, evidence item or Graph relation.

### 5. Native WordPress route lifecycle

Publication context construction distinguishes native Article route state from semantic Authority route identity. For a native Article, the application asks the WordPress adapter to allocate or resolve a deterministic unique slug from the title, persists it on the draft, and reads it back before publication. The gate exposes separate states:

- `PRE_PUBLIC_ROUTE_READY`: draft slug/permalink exists and collision checks passed;
- `POST_PUBLISH_ROUTE_VERIFIED`: published native permalink read-back succeeded and rendered verification is available.

`CANONICAL_PUBLIC_IDENTITY_INVALID` remains strict for semantic Authority routes but is not applied to a native Article merely because a semantic Public Identity row does not exist. WordPress collision checks and native permalink read-back remain mandatory.

### 6. Composition-root convergence

`GovernanceRuntimeFactory` and `Plugin` must construct one resolver, one packet-aware Capture path, one MediaUsage staging verifier and one publication context provider. Any materially different resolver construction is removed or made an adapter over the same policy. Child services receive packet/context objects, not independently reconstructed hints. Read-only compatibility boundaries remain internal and cannot become alternate new-submission writers.

## Failure semantics

- Explicit unresolved subject: `REVIEW_REQUIRED`/unresolved; never body-entity promotion.
- Explicit subject contradiction: fail closed with subject conflict diagnostics.
- Media scope or read-back mismatch: non-success; never optimistic completion.
- Unknown Visual Support candidate: diagnostic/review only; Article continues.
- Missing optional Visual Support: warning/enrichment debt unless policy requires the slot.
- Missing native Article slug before publication: `PRE_PUBLIC_ROUTE_READY` failure, not semantic Public Identity failure.
- Native publication write without verified native read-back: uncertain/non-success.

## Test strategy

Add system-level tests at the Capture/Article/publication boundary, with focused unit coverage for each policy component. Required scenarios are:

1. explicit canonical subject beats repeated incidental body entity;
2. explicit unresolved subject does not become a body entity;
3. multiple explicit hints preserve deterministic caller order;
4. current explicit Media replaces stale featured/inline state;
5. no current Media does not reuse unrelated global Media;
6. Article featured Media does not become subject representative/evidence;
7. Capture issues valid MediaUsage admission without caller scope fabrication;
8. unknown Visual Support candidate is diagnostic-only;
9. native WP draft receives and reads back a unique slug without semantic Public Identity;
10. generic full E2E proves packet identity, selected featured attachment, no stale inline image, semantic read-back, route readiness and clean publication diagnostics.

Tests must use generic registered fixture subjects and Media; no historical production IDs or names are permitted. Existing guarded integration tests remain environment-gated and must not be weakened or hidden.

## Rollout and acceptance boundary

Local implementation must finish with PHP lint, focused tests, full Unit suite, guarded integration/contract checks where infrastructure is available, migration checks if applicable, `git diff --check`, and secret review. Update `docs/architecture/V3_EXECUTION_STATE.md` with evidence and preserve `NO_LIVE_MUTATION` until an approved deployment path and fresh runtime verification exist.

Deployment and canary Article 636 are separate human-gated operations. The canary may be attempted only after source revision/build identity verification, fresh documentation bootstrap, duplicate/read-only audit, exact server-issued scope, canonical Capture retry, and canonical read-back through MCP. No production conditional may be introduced.

## Acceptance evidence format

The eventual implementation/deployment report must provide:

```text
ROOT_CAUSE_SUBJECT=
ROOT_CAUSE_STALE_MEDIA=
ROOT_CAUSE_STAGING_SCOPE=
ROOT_CAUSE_VISUAL_SUPPORT=
ROOT_CAUSE_PUBLIC_IDENTITY=
FILES_CHANGED=
TESTS_ADDED=
FULL_TEST_SUITE=
NO_DOMAIN_HARDCODE=YES/NO
COMMIT=
DEPLOY=
RUNTIME_SOURCE_REVISION=
CANARY_636_SUBJECT=
CANARY_636_FEATURED_ATTACHMENT=
CANARY_636_STALE_MEDIA_PRESENT=
CANARY_636_SLUG=
CANARY_636_PUBLISH_REVIEW=
CANARY_636_PUBLIC_STATUS=
CANARY_636_PUBLIC_URL=
```
