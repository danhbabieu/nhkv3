# Universal MCP Intake-to-Outcome Completion Law — Proposed Subordinate Contract

**Status:** PROPOSED / NON-NORMATIVE until architecture approval and implementation.
**Date:** 2026-10-09
**Scope:** all user-originated NHK V3 MCP submissions and their registered domain adapters: Dictionary, Media/Image, Video, Article/News, Authority (Brand, Model, Variant, Movement, Clock Type, Classification, Component), Knowledge, Source/Evidence, Graph relations and future registered owners.
**Supremacy:** `docs/constitution/NHK_V3_CONSTITUTION.md` controls. This proposal does not authorize new owners, schema, predicates, writers, routes, or governance bypass.

## 1. Verified findings

The current Constitution and Universal Structured Semantic Intake Contract already require `nhk.capture.ingest` as the normal submission entry point, documentation checkpoint, intent before owner creation, structured interpretation, canonical resolution, reuse, scope/provenance/Evidence evaluation, governed mutations, and owner read-back. The MCP Control Plane requires post-ingest reconciliation and completion; specialized Video Workflow requires distinct canonical and frontend read-back.

Observed on staging source revision `f5c6a755b3497cb06ca33ee381ff443771e0923b`: Video `iyIT4nx8-mA`, Capture `01a11e92-41a8-7e45-8437-271b8cc8df50`, Video owner `01a11e92-47a0-7b5d-9faf-1d90c488ffec`, canonical subject `01a09e44-539a-7f1a-938a-d7d91bb689a3`. Capture returned `COMPLETE`, `COMPLETED_CONVERGED`, source/knowledge/evidence/video owner COMPLETE, canonical read-back VERIFIED, but `projection_state.publication=null` and `frontend_public_state.public=NOT_APPLICABLE, frontend=NOT_APPLICABLE` for a `publish=true` request. This is a cross-domain completion-intent mismatch to investigate, not proof of public publication. The registered `nhk.video.frontend.reconcile` action was not callable from the current client (`Unknown tool`); distinguish client exposure from server capability. Video editorial `CONTENT_COMPLETE` still included tangential railway-history text in a nine-second gear-focused video, motivating topic relevance tests.

## 2. Single shared law: Intent → Required Outcomes → Verified Completion

At Capture admission, compile the user request into an immutable, versioned **outcome obligation plan** (conceptual DTO; no new persisted entity authorized). Resolve:
- purpose, content intent, registered domain owners, explicit user scope, source and rights, requested output surfaces (canonical-only, private review, public detail, archive, home when applicable), language, publication intent, expected quality and safe defaults;
- target canonical identity or bounded candidate discovery; exact ID/revision and dedupe/reuse strategy; any user-confirmed subject as a hint, never Evidence;
- per-owner **required**, **conditional** and **optional** dependencies and relationships, with provenance, evidence and graph applicability; optional enrichment must not become a universal publication gate;
- domain-specific readiness and delivery obligations; a private Evidence/Claim need not become public, an Article requires Post/public route only if requested, a Video publication request requires Video Public Identity and verified detail/archive read paths, and homepage inclusion only when requested or policy-required;
- permissions, documentation checkpoint, deployment/runtime identity, idempotency and failure/recovery policy.

Every downstream stage must retain the same intent/outcome obligations. No child can silently downgrade a requested public result to canonical-only success. A revised plan requires explicit reason, audit and applicable authorization.

## 3. Shared stage model (conceptual, not new registry enums)

1. **ADMIT:** authenticate, authorize, checkpoint, validate source and content intent, inspect rights and payload; reject invalid/unsafe inputs without partial mutation.
2. **INTERPRET:** use the single StructuredInterpretationPacket shared across source adapters; preserve user words, observed facts, source claims and generated copy as distinct trust classes.
3. **RESOLVE / REUSE:** canonical identity, aliases, subject compatibility, duplicate detection, revisions and reuse; ambiguous subjects enter guided review, never arbitrary first-match.
4. **DEPENDENCY PLAN:** identify mandatory owner fields, canonical Source/Claim/Evidence, registered relations, MediaUsage, Public Identity, SEO, editorial and frontend obligations. Use exact per-domain applicability, not a generic field checklist.
5. **PREPARE:** fetch existing canonical dependencies; generate bounded missing-data proposals or explicit human-review questions. Do not invent Evidence or claim factual truth from captions, transcripts, hints, OCR, video metadata or generated prose.
6. **GOVERNED APPLY:** preview, authorization, proposal/controlled apply and canonical read-back in dependency order; preserve each existing domain owner, revision and Graph predicate registry.
7. **PRESENT / PROJECT:** domain-specific reader-safe composition, rights, accessibility, media derivatives, SEO and Public Identity when applicable. Apply topic relevance and avoid unrelated Knowledge padding.
8. **VERIFY:** exact owner read-back, relation/evidence eligibility, search/index, public URL, detail/archive/home as required by intent and domain. Public route HTTP alone is insufficient: exact canonical owner must be returned by the actual frontend query.
9. **CONVERGE / RECOVER:** only return success for all required obligations. Otherwise return explicit partial/pending/review/blocked state and a bounded continuation plan with same Capture/owner identities and idempotency.

## 4. Outcome truth invariant

A completion receipt must contain **separate dimensions**:
- admission and semantic preparation;
- canonical owner and dependency read-back;
- governance and registered relation validation;
- editorial/SEO/media quality as applicable;
- requested publication, public identity and exact frontend read-back;
- unresolved blockers, optional omissions, next permitted action, retry/continuation and exact identity.

`COMPLETE` for canonical Capture is not synonymous with user-requested public delivery. `NOT_APPLICABLE` is legal only for a genuinely unrequested or policy-inapplicable dimension, with machine-readable reason and scope. A requested public detail/archive cannot be marked `NOT_APPLICABLE` merely because a parent Capture has no Article Post. Homepage selection may independently be `NOT_APPLICABLE` under bounded homepage policy.

Use existing runtime status vocabulary wherever possible. New receipt fields/enums require registry, contracts, compatibility tests and explicit architecture approval; do not mint enums in documentation alone.

## 5. Domain-specific obligations

| Domain | Canonical completion | Conditional delivery and dependencies |
|---|---|---|
| Dictionary | Registered Entry/Form/Sense/concept reuse or governed delta, exact owner read-back | Owner delegation/public Dictionary detail only when applicable; ambiguity stays review |
| Media/Image | Byte validation, orientation/derivatives, rights, canonical Media/MediaAsset, provenance | MediaUsage target/role, SEO/accessibility, public derivative and exact frontend image when requested |
| Video | External source identity, canonical Video, compatible subject, eligible registered about relation/Evidence when required, governed read-back | Public Identity, SEO, detail/archive read-back; home only by policy or explicit intent |
| Article/News | Content intent, editorial owner, source/claim scope, required MediaUsage, Post read-back | Publication state, public route, search/SEO, article frontend read-back |
| Authority (Brand/Model/Variant/Movement/Clock Type/Classification/Component) | Registry-valid identity, hierarchy/attributes, reuse, governed relations and owner read-back | Dossier/public route only if domain policy and user intent require it |
| Knowledge/Claim | Subject, scope, provenance, Evidence eligibility, governed canonical read-back | Public claim projection only if authorized; no generic Claim detail route assumed |
| Source/Evidence | Exact canonical dependencies, lifecycle, rights/visibility, governed read-back | Private/hidden can be COMPLETE without public display |
| Graph | Registered endpoints/predicate, source and evidence requirements, current revisions, apply and read-back | Public related-content only when public-safe and applicable |

## 6. Relevance and adequacy law

Content quality is evaluated relative to the **specific source and user intent**, not just the broad semantic subject. Exact subject matches can still be editorially irrelevant. Required core facts must be sourced and on-topic; context claims must have an explainable direct contribution; unrelated history, filler, and coverage-padding are excluded. Editorial sufficiency should be proportionate to source type and length (e.g. short video vs article), while technical correctness, Evidence and public eligibility remain independent.

## 7. Recovery and operator exposure

On interruption, persist/reconcile the canonical subject packet, obligation plan and current owner receipts without creating a shadow semantic owner. A replay must retain Capture/owner UUIDs, validate fingerprints and reconcile `OUTCOME_UNKNOWN` before any write. `REVIEW_REQUIRED` must specify whether the operator should confirm an ambiguous identity, supply genuinely missing evidence, or resume a recoverable stage. Distinguish `CLIENT_EXPOSURE_GAP` from `RUNTIME_CAPABILITY_MISSING`; never replace an unavailable protected operation with a generic WordPress writer.

## 8. Required implementation seams

Review `EditorialCaptureCoordinator`, `CaptureSubjectHandoff`, `CaptureSemanticPreflight`, `CaptureCurrentOutcomeReducer`, all registered Capture adapters, the shared Semantic/Editorial selector, owner-specific Governance completion, Video publication/projection, `PublicIdentity`, `VideoFrontendProjection`, `MediaVideoPageQuery`, `HomeSemanticQuery`, and MCP Ability/connector exposure. Refactor only verified duplicated or missing logic into existing shared services. Preserve domain owners, registries, policy boundaries and exact provenance.

## 9. Acceptance matrix / regression

1. For `publish=true` Video, canonical owner COMPLETE but missing public detail/archive returns PUBLIC_PENDING/BLOCKED equivalent, never overall public success or blanket NOT_APPLICABLE.
2. Public Video with exact identity and valid projection verifies detail/archive; home only when selected by policy.
3. Video source subject inferred from canonical metadata vs explicit user hint is compatible and retains same packet; mismatch enters review.
4. Same external Video/idempotency reuses Capture/owner; interruption and OUTCOME_UNKNOWN do not duplicate.
5. Video without transcript does not automatically fail if sufficient safe metadata, subject and mandatory dependencies exist.
6. Video with tangential exact-subject Knowledge rejects unrelated filler; short-format proportionality holds.
7. Dictionary ambiguous term vs exact Entry/Sense, no duplicate owner or invented definition.
8. Media upload corrupt/valid, derivatives, private original, public rights, MediaUsage and target route.
9. Article text/image/news intent and Post publication read-back vs canonical-only draft.
10. Authority Brand/Model/Variant/Clock Type creation/update/reuse, exact registered relations and dossier applicability.
11. Knowledge/Source/Evidence with private visibility succeeds canonical-only without public demand; ungrounded facts fail closed.
12. Graph evidence-ineligible relation cannot be silently attached/published.
13. Checkpoint stale, permission denied, connector exposure gap, runtime capability gap and failed frontend readback have distinct outcomes.
14. Cross-adapter equivalence for intent/subject/dependency/recovery/completion and independent optional-enrichment behavior.
15. Reconcile existing Video `iyIT4nx8-mA`, `QthfuLUnxH4`, `kBTZDH4mTv4` using exact existing IDs; no duplicate, no unapproved staging/production mutation.
16. Verify actual frontend query sources, not URL synthesis or SEO preview alone.

## 10. Rollout / governance

P0: audit actual code paths and documented statuses; establish failing tests, fix publish-intent completion truth and missing public projection handoff.
P1: shared obligation/dependency planning and domain adapter parity; focused relevance and Evidence/reuse checks.
P2: exact frontend/public reconciliation and client exposure; cross-domain regression matrix.
P3: migration-free backfill/reconciliation of existing owners only through authorized bounded operations, then TEST acceptance, staged release and production with explicit authorization.

Before any normative change: review the Constitution and ACTIVE domain contracts, document a conflict matrix, obtain architecture approval for amendments, then update one subordinate cross-cutting contract plus affected owning contracts/status index. Do not modify the Constitution without a formal amendment decision. No push/deploy/migration/production changes are authorized by this document.
