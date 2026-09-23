# Video Intake Intelligent Constraints Design

## Goal

Refactor Video Intake so short user input is interpreted against bounded canonical context, claims are classified and treated independently, and the artifact is completed through bounded self-repair before review or blocking. The implementation must remain generic: no Golden Capture, UUID, YouTube ID, Odo, brand, model, or video-specific exception is allowed.

## Decision pipeline

`input → interpret → resolve candidates → retrieve bounded context → compare facts → classify statements → choose editorial treatment → compose → critique → bounded repair → quality decision → publish/review/block`.

The engine is a pure application decision boundary. It reads canonical/Knowledge/Evidence/visual context through existing ports, does not create a semantic owner, does not write live canonical data, and emits a serializable decision trace for Capture continuation and retries.

## Statement classifications and treatment

Every interpreted statement receives exactly one classification:

- `CANONICAL_SUPPORTED`: use normally within the canonical subject scope.
- `USER_OBSERVATION`: use only for the depicted specimen/video and with attribution/scope.
- `SOURCE_SUPPORTED`: use with source attribution and bounded scope.
- `INFERABLE_WITHIN_SCOPE`: use as an explicitly scoped inference; never promote to canonical fact.
- `UNCERTAIN`: narrow wording or omit the detail.
- `CONFLICTING`: prefer canonical/evidence, record diagnostics, and require review only when identity or core content is affected.
- `UNSUPPORTED_EXPANSION`: remove from public copy and retain trace reason.

`USER_HINT` is contextual input, not Knowledge or Evidence. It can inform subject resolution and public copy only through correct scope/attribution. Knowledge proposals remain a separate governed authority pipeline.

## Subject resolution

Candidate scores combine canonical name/alias, parent/model, configuration facets, music, movement, user observations and bounded context. Semantic discrimination removes candidates that do not explain the combined facets. A clear winner with no conflict returns `AUTO_RESOLVE` and a trace. Two or more materially competitive candidates return `HUMAN_REVIEW`; no Odo/brand/model thresholds or fixture identity is embedded.

## Constraint severities

Constraints evaluate claims/decisions independently:

- `INFO`: diagnostic only.
- `REPAIRABLE`: a registered bounded repair may clear it.
- `REVIEW_REQUIRED`: human decision is needed for the affected claim/artifact.
- `HARD_BLOCK`: only canonical identity unresolved, unreconciled core factual conflict, governance/authority approval requirement, an indispensable Evidence/Visual Support claim that cannot be removed/narrowed, or integrity/idempotency/canonical ownership violation.

Missing non-essential detail, sparse Knowledge, unsupported secondary observations, SEO wording and enrichment gaps cannot hard-block the whole Video.

## Quality and self-repair

Quality becomes `evaluate → explain failures → repairable? → repair → re-evaluate`, with at most three bounded rounds. Registered repairs are scope normalization, attribution, removal of unsupported sentences, universal-to-scoped wording, title narrowing, alternate eligible Knowledge retrieval, copy restructuring, specificity reduction and section regeneration. A failure remains claim-local until aggregation; only unresolved `REVIEW_REQUIRED`/`HARD_BLOCK` findings determine artifact outcome.

## Trace and idempotency

Each trace entry contains `statement`, `classification`, supporting canonical/evidence references, `scope`, `confidence`, `action` and `reason`, plus repair rounds and aggregate findings. The trace is transient/durable Capture preparation data, excludes raw bodies/secrets, and is bound to the existing Capture/idempotency fingerprint. Retry rehydrates the same trace and reuses the same Video UUID; it never creates a duplicate.

## Acceptance matrix

The generic suite covers short input; specimen-level observation; facet-resolvable ambiguity; real ambiguity; mixed canonical and user observation; unsupported and conflicting details; secondary versus core visual-support gaps; sparse and rich Knowledge; persisted Capture retry; duplicate/idempotency; and multiple unrelated brand/model/variant fixtures. Golden is one ordinary regression row and has no special branch.

## Non-goals

No MCP/live canonical mutation, no direct database writer, no new semantic owner, no legacy article migration, no relaxation of governed Video relation Evidence, and no replacement of canonical ownership with generated copy.
