# Universal Owner Lifecycle Separation Design

**Status:** Design approved in conversation on 2026-09-24; implementation plan pending review.

## Goal

Correct the shared NHK V3 architecture so canonical owner existence, enrichment/editorial readiness, and publication/projection readiness remain independent for Article, Video, Media, generic owners, and future registered owners.

The correction must use the existing Universal Core, EnrichmentPack, Owner Capability Registry, SemanticNeed, Graph, Governance, Composer, Quality Gate, Living Knowledge, and CompletionCoordinator boundaries. It must not create a Video-specific lifecycle, a second enrichment engine, a second graph, a Brain 3, or a schema migration without repository evidence.

## Current first-broken boundary

`CompletionCoordinator::finalize()` currently contains an owner-class branch that derives Video `content_state` from editorial quality and adds `CONTENT_NEEDS_REVIEW` to the same blocker list used for canonical completion. Its aggregate `complete` result therefore conflates canonical owner read-back, enrichment/content quality, and public/frontend readiness. `OwnerCapability` also declares only presentation surfaces, canonical-readback presence, and dependency participation, so it cannot describe a generic minimum-safe representation or owner-specific completion/publication requirements. `SemanticNeed` carries one canonical subject but does not explicitly preserve a separate target scope for one owner involving multiple semantic subjects.

## Architectural model

The shared lifecycle returns independent dimensions:

1. `canonical_existence`: whether the owner identity, required Governance, controlled write, and canonical read-back establish a valid owner.
2. `enrichment_readiness`: the safe availability of reusable Knowledge/source-grounded material for the owner or its semantic needs.
3. `publication_readiness`: whether the current projection is safe and contract-compliant for a declared presentation surface.

The canonical owner invariant is:

`VALID OWNER IDENTITY + REQUIRED GOVERNANCE + SUCCESSFUL CONTROLLED WRITE + CANONICAL READ-BACK = CANONICAL OWNER EXISTS`.

Sparse, absent, or unavailable optional enrichment cannot invalidate canonical existence unless a registered owner capability explicitly declares that enrichment as part of identity. Publication and frontend read-back remain downstream dimensions and do not rewrite canonical existence.

## Components and interfaces

### 1. CompletionCoordinator

Keep `CompletionCoordinator` as the policy owner for derived completion packets. Replace owner-type branches with capability-driven policy and return explicit dimension packets. The packet must preserve compatibility fields where existing consumers require them, but the authoritative fields are:

```text
canonical_existence: {status, blockers, readback_verified}
enrichment_readiness: {status, blockers, warnings, gaps}
publication_readiness: {status, blockers, warnings, surface}
status: COMPLETE | PARTIAL | BLOCKED
complete: canonical_existence.status == COMPLETE
```

`complete` means canonical owner completion, not editorial or publication readiness. Existing aggregate/capture reconciliation continues to reconcile by `(owner_type, owner_id)` and must retain outcome-unknown behavior: an empty response is neither success nor failure until same-identity read-back/reconciliation resolves it.

Owner blockers include invalid identity, identity conflict, missing mandatory owner data, Governance denial, controlled-write failure, canonical read-back failure, outcome-unknown mutation, and capability-declared mandatory subject failure. Editorial and publication findings remain attached to their own dimensions.

### 2. OwnerCapability and registry

Extend the existing capability value object minimally with declarative metadata for:

- identity requirements;
- canonical completion requirements;
- minimum safe representation fields/producer;
- supported presentation surfaces;
- publication requirements;
- canonical read-back strategy;
- dependency registration policy.

The registry remains the only admission point for future owner behavior. The Universal Core, Graph traversal, applicability evaluation, selector, and Brain 2 factual laws must not branch on Article, Video, Media, or fixture names. Missing capability metadata fails closed for the affected stage; it does not create a generic writer or silently invent a safe representation.

### 3. EnrichmentPack and universal degradation

Reuse `EnrichmentPack` as the transient shared result. Its branch readiness must distinguish at least the existing equivalent states, normalized to the conceptual model `RICH`, `PARTIAL`, `SPARSE`, `UNAVAILABLE`, `NEEDS_REVIEW`, and `REGENERATION_AVAILABLE` where applicable. A branch may be sparse or unavailable while the owner remains canonically complete.

The minimum-safe representation is resolved from the registered owner capability. Universal Core never hardcodes Video, Media, or Article fields. Consumers receive only capability-approved owner/source/context fields when reusable Knowledge is absent. No missing Knowledge is padded with invented facts.

### 4. SemanticNeed, UniversalInputEnvelope, and multi-subject retrieval

Extend the existing transient semantic context so each need can preserve:

- the owner identity/context;
- the original subject identity;
- the target subject identity when distinct;
- concept/facet and requested scope;
- applicability, specificity, evidence, graph path, and treatment metadata after retrieval/qualification.

Backward-compatible single-subject inputs continue to map target to the canonical subject. Multi-subject input is represented as target-scoped needs, not as a weakened graph relation. Retrieval allocates opportunities per need and Brain 1 returns qualified KnowledgeUnits without changing authority. `GRAPH_REACHABLE != FACTUALLY_APPLICABLE` and `PUBLIC ASSERTION SPECIFICITY <= SUPPORT SPECIFICITY` remain enforced.

Owner identity and subject identity are distinct keys throughout idempotency, reconciliation, and completion. Multiple owners may reference one subject; one owner may target multiple subjects. Neither direction may deduplicate the other.

### 5. Quality and information authority

Adapt existing `EditorialQualityReport`, `EditorialQualityGate`, and completion result objects rather than adding a parallel status hierarchy. Findings are classified into:

- `OWNER_VALIDITY` — canonical blockers only;
- `FACTUAL_SAFETY` — unsupported or unsafe assertions and repair/removal decisions;
- `EDITORIAL_QUALITY` — redundancy, information gain, sparse coverage, weak description, and similar warnings/repair states;
- `PUBLICATION_QUALITY` — unsafe projection, SEO/public-route requirements, mandatory asset requirements, and policy/compliance blockers.

Canonical Knowledge, source-grounded information, user observation/context, and editorial narrative remain separate information classes. Brain 1 determines what is safe to say; Brain 2 determines composition and depth. Neither may create Knowledge from narrative, promote observations into canonical truth, or control canonical owner existence.

### 6. Living Knowledge and dependencies

Canonical owners with sparse or unavailable enrichment remain valid regeneration candidates. Dependencies are registered only for Knowledge actually consumed. Uncovered needs/gaps are preserved through existing bounded diagnostics or lifecycle fields where available; rejected Knowledge is never recorded as a dependency. Regeneration follows the existing governed path: dependency/gap detection → regeneration available → preview → Quality → Governance → controlled update → canonical read-back. Failed regeneration does not mark the owner current and does not silently rewrite public content.

## Owner coverage

Article, Video, and Media use existing adapters and capability registrations. A generic synthetic future owner must pass through the same capability and lifecycle interfaces without a new production special case. Existing content-specific composition/presentation rules may remain downstream of shared canonical existence and enrichment results, but they cannot change owner completion semantics.

## Acceptance criteria

The implementation must prove, with generic and owner-profile tests:

- rich, sparse, zero-Knowledge, unavailable-enrichment, write-failure, read-back-failure, outcome-unknown, and Governance-denied owner flows;
- two or more Articles, Videos, Media records, and a synthetic future owner referencing one subject remain distinct and retry by owner identity;
- one owner can express two target-scoped needs, retrieve target-specific Knowledge, preserve gaps, and retain comparison without weakening applicability;
- canonical Knowledge, source-grounded material, user observations, and editorial prose retain separate authority;
- duplicate Knowledge, low information gain, sparse enrichment, and repairable prose do not destroy canonical existence;
- unsafe public assertions block or repair publication only when no safe projection remains;
- sparse owners remain regeneration candidates and failed regeneration preserves stale/current truth correctly;
- no production branch references Odo, brand/model names, dates, YouTube IDs, fixture IDs, WordPress IDs, or fixture titles.

The requested final report must use exactly the user-specified field names and end with either `UNIVERSAL_OWNER_LIFECYCLE_READY` or `UNIVERSAL_OWNER_LIFECYCLE_BLOCKED`.

## Safety and non-goals

No migration, schema change, staging or production semantic mutation, deployment, push, publication, V2 mutation, or direct database writer is authorized by this design. If implementation evidence requires a migration, stop and report before creating it. Existing WordPress/native domain persistence, Governance, Graph, and public route contracts remain authoritative.

## Verification strategy

Use TDD for each slice: write a failing focused regression, observe the expected failure, implement the smallest change, then run the focused suite and full Unit suite. Before any completion claim, run PHP lint, Composer validation/lint as applicable, `git diff --check`, special-case scan, and guarded Integration only when the exact documented environment is available. Integration/environment failures must remain distinguishable from product failures.
