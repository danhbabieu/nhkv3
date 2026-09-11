# Governed Conversational Authority Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a governed, reuse-first conversational Authority workflow through the existing Capture boundary, with exact owner approval, canonical Graph relations, read-back, and fail-closed retry behavior.

**Architecture:** Extend the existing Capture/MCP orchestration instead of adding a semantic writer. A planning-only AuthorityIntentPlanner produces a persisted bounded plan; a Capture-owned executor materializes only the exact approved candidate set through the existing Proposal/Governance/Controlled Apply owners. Authority remains the identity owner, Graph remains the relation owner, and WordPress remains the editorial owner; Authority-only Captures do not create Posts while MIXED Captures reuse one editorial draft.

**Tech Stack:** PHP 8.x, PHPUnit 11, WordPress/WPDB adapters, existing NHK Core registries, repositories, Governance services, MCP catalog/transport and Admin Workbench.

**Spec:** `docs/superpowers/specs/2026-09-11-conversational-authority-design.md` (revision `7fc1b8e9`)

## Global Constraints

- Authority remains the sole canonical identity/lifecycle owner for the nine registered entity types.
- `classification` with `family=clock-type` represents clock types; no `clock_type`, taxonomy, postmeta or generic writer is added.
- New semantic mutation remains `Proposal → Submit → Approval policy → Eligibility → Controlled Apply → canonical read-back → audit`.
- `nhk.capture.ingest` is the only normal new-submission entry point; direct writers remain internal/admin compatibility boundaries.
- EDITORIAL is backward-compatible, AUTHORITY creates no Post, and MIXED creates at most one Post in the same Capture.
- UUID, stable-key, exact canonical-name and alias reuse precedes bounded lexical review; fuzzy creation is forbidden.
- Stable keys, predicates, entity types, families, fields and candidate IDs are server/registry-owned and fail closed when unknown.
- No V2, staging, production, DEMO or legacy article-body data is mutated; no live Hermle is created.
- Existing unrelated worktree changes are never staged.

## Implementation Map

| Boundary | Existing owner to extend | Deliverable |
|---|---|---|
| Capture | `CaptureRecord`, Capture repository, continuation/coordinator | immutable purpose, authority plan state, same-Capture continuation and idempotent result |
| Planner | new `Application\Authority` planning services | deterministic intent parsing, reuse/facet decomposition, candidate plan and fingerprint |
| Stable key | new canonical policy | server-owned namespace/family/name normalization and version |
| Resolution | `CanonicalAuthoritySubjectResolver`, Authority repository | ordered reuse and ambiguity diagnostics |
| Classification | Authority catalog/repository plus audit/backfill service | persisted-family audit, explicit idempotent mapping strategy, fail-closed hierarchy |
| Graph | `PredicateRegistry`, Graph policy/service | `subtype_of`, `classified_as`, scope/provenance/cycle/revision validation and read model |
| Governance | existing Proposal/Eligibility/Controlled Apply | exact candidate approval, effective-policy precedence, plan executor and atomic boundary |
| MCP | `McpToolCatalog`, transport, Ability projection and Plugin wiring | purpose/intent schema, actual dispatch path, plan/continuation/apply responses |
| Admin | existing Workbench policy storage/UI | guarded conversational policy setting reusing Governance queue |
| Read models | Graph/entity projection services | `CHILD_TYPE` vs `FACET_FILTER`, Brand clock-type projection |
| Knowledge | existing Knowledge/Governance owner | post-read-back dependent claim planning with exact scope/provenance |
| Verification/docs | PHPUnit, lint, current contracts/status/execution state | red-green evidence, migration checks, connector gate and current documentation |

### Task 1: Capture purpose and schema

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Capture/CaptureRecord.php`, `CaptureStage.php`, `CaptureAddendumRecord.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`, `EditorialCaptureContinuationService.php`, `McpTransport.php`, `McpToolCatalog.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ConversationalAuthorityCaptureTest.php`, `McpContractTest.php`

- [ ] Write failing tests for legacy editorial compatibility, AUTHORITY no-Post, MIXED one-Post maximum, same-Capture retry idempotency and inconsistent purpose rejection.
- [x] Run the focused tests and confirm the typed purpose/authority routing is green.
- [x] Add closed purpose/mode value objects/validation and persist bounded purpose/plan fields in Capture context without changing old editorial defaults.
- [x] Route new MCP Capture packets by explicit purpose and persisted Capture purpose on continuation; keep native files intact and reject authority intent with EDITORIAL.
- [x] Run focused Capture/MCP tests and inspect the no-Post/one-Post assertions.

### Task 2: Planner, reuse, facets and stable-key policy

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Authority/AuthorityIntentPlanner.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Authority/CanonicalAuthorityStableKeyPolicy.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Authority/AuthorityPlanFingerprint.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityIntentPlannerTest.php`, `AuthorityPlanFingerprintTest.php`

- [ ] Write failing planner tests for Hermle create/reuse, Table Clock/Cuckoo reuse, Public Clock create, Table Clock + France composition, ambiguous/retired identity, and glass-dome review without Model/type/subtype creation.
- [ ] Run them RED.
- [ ] Implement bounded deterministic parsing and ordered UUID → scoped stable key → exact normalized name → alias → review-only lexical resolution.
- [ ] Implement server-owned stable-key preview with versioned normalizer and collision diagnostics; client keys are ignored/rejected.
- [ ] Implement candidate IDs, dependency closure, rejection reasons, provenance/scope and canonical deterministic plan serialization/fingerprint.
- [ ] Run the planner/fingerprint tests GREEN and verify planning makes no repository writes.

### Task 3: Classification family audit and Graph predicates

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Graph/PredicateRegistry.php`, `PredicateDefinition.php`, `GraphService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Authority/ClassificationFamilyAudit.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/ClassificationHierarchyPolicy.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/ClassificationReadModel.php`
- Create: additive migration/backfill support under existing migration owner, without automatic data mutation
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ConversationalAuthorityGraphTest.php`, repository/contract tests

- [ ] Write failing tests for predicate registration, Mantel → Table Clock subtype, duplicate replay, self/direct/multi-hop cycle, cross-family, inactive endpoint, revision drift, cardinality conflict, classified_as source allow-list and provenance scope matrix.
- [ ] Run RED.
- [ ] Register only `subtype_of` and `classified_as`; implement bounded family audit before hierarchy validation and typed unresolved-family failure.
- [ ] Implement explicit deterministic family mapping/backfill planning, rerunnable no-duplicate behavior and canonical read-back; do not infer family from stable-key prefix alone.
- [ ] Implement hierarchy cycle checking and `classified_as` scope/provenance rejection, with `SYSTEM_INFERENCE` discovery-only and no Brand/Movement source.
- [ ] Implement read-model labels `CHILD_TYPE` and `FACET_FILTER`, preserving France as an independent facet.
- [ ] Run Graph/family/read-model tests GREEN.

### Task 4: Fingerprint closure, policy precedence and governed exact-plan executor

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Governance/ConversationalAuthorityPolicy.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Governance/ConversationalAuthorityPolicyResolver.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedAuthorityPlanExecutor.php`
- Modify: existing Governance policy storage/resolver, `ControlledApplyService.php`, Proposal binding/eligibility and failure diagnostics
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ConversationalAuthorityGovernanceTest.php`

- [ ] Write failing tests for complete fingerprint closure, stale Capture/entity/predicate/policy/docs dependencies, exact candidate approval, partial approval, unknown/missing dependency and replay.
- [ ] Run RED.
- [ ] Implement strict policy precedence `OFF` > `REVIEW_REQUIRED` > owner-confirmed auto-apply and versioned policy fingerprints.
- [ ] Implement executor verification before Proposal creation, typed reapproval/approval diagnostics, dependency ordering and typed Proposal packets.
- [ ] Trace Authority/Graph/Proposal/Audit transaction ownership with tests; use one Unit of Work only when the shared WPDB transaction boundary is proven, otherwise compensate deterministically or fail before mutation.
- [ ] Add multi-candidate rollback/failure-attempt tests and run Governance tests GREEN.

### Task 5: Capture wiring, Knowledge continuation, MCP and Admin

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`, Capture services, `McpTransport.php`, `McpToolCatalog.php`, `McpAbilityRegistration.php`
- Modify: existing Admin Workbench option/form handler and current MCP/Capture contracts
- Create/modify: bounded Knowledge continuation and Authority/Brand read projections
- Test: MCP schema/dispatch, Plugin wiring, Admin policy, mixed-mode and Knowledge continuation tests

- [ ] Write failing application/contract tests for PLAN, same-Capture continuation, APPLY_APPROVED_PLAN dispatch, exact `files[]` preservation, mixed editorial dependency and old `SUBJECT_NOT_FOUND` continuation.
- [ ] Run RED.
- [ ] Wire services through the existing Plugin runtime and preserve Capability/documentation/idempotency checks and native file metadata.
- [ ] Add guarded Admin `Conversational Authority Creation` setting using existing Governance queue and nonce/capability patterns.
- [ ] Add dependent Knowledge planning/application only after Authority canonical read-back; retain narrow scope/provenance and never treat Article prose as Evidence.
- [ ] Run focused application/MCP/Admin tests GREEN.

### Task 6: Documentation, live-like connector gate and final verification

**Files:**
- Modify: affected current Authority/Graph/Governance/Capture/MCP/Admin/status/execution docs only
- Test/tools: actual local MCP `tools/list`/dispatch probe when available; otherwise record typed environment blocker

- [ ] Re-read the spec and map every acceptance case to test/evidence, including no glass-dome subtype contradiction and MIXED flow.
- [ ] Update executable/current docs with purpose modes, predicates, family audit, exact fingerprints, policy precedence, transaction evidence and connector acceptance status; append a dated Execution State checkpoint.
- [ ] Regenerate canonical MCP documentation snapshot with the official command if the current build requires it, then bootstrap again and record new versions/hashes/build identity.
- [ ] Run focused and relevant regression suites, PHP lint, formatter/lint, `git diff --check`, secret scan and placeholder scan; report exact counts and skips/failures.
- [ ] Stage only feature files, commit bounded changes, verify commit/worktree, push current branch and verify remote commit. Do not deploy or create Hermle live.

## Self-review

- The plan explicitly covers every affected boundary named by the approved spec.
- Glass-dome is only a registry/evidence-driven review candidate; the hierarchy fixture is Mantel Clock → Table Clock.
- EDITORIAL, AUTHORITY and MIXED share one Capture boundary and preserve the native Post rule only where applicable.
- Transaction atomicity is conditional on a proven shared boundary; failure diagnostics survive rollback and compensation is never assumed.
- Live connector acceptance is a separate gate from catalog registration and is reported as environment-blocked when unavailable.
