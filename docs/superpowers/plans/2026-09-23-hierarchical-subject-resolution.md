# Canonical Hierarchical Subject Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make shared NHK V3 subject resolution select the unique narrowest active canonical Authority identity across Capture and Video without treating compatible ancestors as ambiguity.

**Architecture:** Keep `CanonicalAuthoritySubjectResolver` as a read-only Authority identity lookup adapter. Move all multi-hint aggregation, composite lookup, structural compatibility, specificity, lexical-quality and state decisions into `SubjectResolutionService`, backed by a read-only registered structural-context port. Persist one revision-bound `SubjectResolutionPacket` and pass it through every downstream owner; retain Evidence, Graph and Governance as the only semantic mutation boundaries.

**Tech Stack:** PHP 8.x, WordPress plugin runtime, PHPUnit 11, existing Authority/Graph repositories and registries, existing Capture continuation and Video adapter contracts.

**Spec:** `docs/superpowers/specs/2026-09-23-hierarchical-subject-resolution-design.md`

## Global Constraints

- Do not hard-code any Brand, Model, Variant, UUID, YouTube ID, Post ID or fixture-specific exception in production code.
- Authority lookup is read-only; resolution never creates Authority, Knowledge, Source, Evidence, Video, Media or Graph state.
- Structural compatibility uses registered canonical Graph relations/context and never invents predicates or shortcut edges.
- Preserve `Source → Claim → Evidence → registered relation → Governance → Controlled Apply → canonical read-back`.
- Preserve platform plus external Video ID deduplication and A→B governed relation reconciliation.
- Preserve optimistic revisions, Capture idempotency, bounded reconciliation candidates and fail-closed inactive/stale/malformed state.
- Preserve wire compatibility for existing `SubjectResolutionPacket` aliases and existing Capture diagnostic codes.
- Do not mutate staging/production/V2 data; do not run destructive operations on `nhk_v3`.
- Update `docs/architecture/V3_EXECUTION_STATE.md` only in the final implementation checkpoint and record runtime verification as pending when no fresh deployment read-back exists.

## Review Focus

- A Brand and compatible exact Variant must select Variant, even when Brand is the first hint; pin in resolver unit tests.
- A designation shared by two Brands must remain ambiguous without canonical Brand context; pin in composite-resolution tests.
- A correct parent must be retained as context and never become a competing primary; pin in hierarchy tests.
- A contradictory parent must fail closed before Video/Evidence/Graph work; pin in preparation and coordinator tests.
- Body fragments and unresolved lexical noise must not displace an exact explicit identity; pin in source extraction and cross-intent tests.

## File and module map

- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/CanonicalAuthoritySubjectResolver.php` for lookup-only exact, alias, reference and composite candidate retrieval.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/SubjectResolutionService.php` for shared policy decisions and normalized states.
- Create `public/wp-content/plugins/nhk-core/src/Application/Semantic/SubjectStructuralContextReader.php` as the read-only structural-context interface consumed by the policy service.
- Create `public/wp-content/plugins/nhk-core/src/Application/Semantic/CanonicalSubjectStructuralContextReader.php` as the adapter over the existing registered Graph structural context query; it never writes Graph.
- Modify `public/wp-content/plugins/nhk-core/src/Domain/Capture/SubjectResolutionPacket.php` to persist compatible context, decision class and decision fingerprint while accepting old wire aliases.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php` to consume normalized states, exclude lexical noise and emit machine-actionable review diagnostics.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` only where packet propagation/state mapping or downstream handoff currently re-resolves the subject.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php` only where bounded reconciliation must use normalized state and packet validation.
- Modify `public/wp-content/plugins/nhk-core/src/Plugin.php` to wire the shared resolver and structural read adapter once; do not create a Video-only resolver.
- Extend the nearest existing semantic, Capture, Video, Article/Knowledge and contract tests; do not create fixture-specific production branches.

## Dependency order

### Task 1: Define the structural read boundary and packet shape

**Files:**

- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SubjectStructuralContextReader.php`.
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/CanonicalSubjectStructuralContextReader.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Capture/SubjectResolutionPacket.php`.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php` and a focused new structural-context test only if the existing structural query lacks coverage.

**Interfaces:**

- `SubjectStructuralContextReader::contextFor(array $candidate): array` returns `status`, `candidate`, `ancestors`, `relation_path`, `reasons`, `warnings` and current revisions.
- `CanonicalSubjectStructuralContextReader` consumes the existing read-only Graph/Authority structural context services and returns no mutation capability.
- `SubjectResolutionPacket` adds optional `compatibleContext`, `decisionClass` and `decisionFingerprint` fields with backward-compatible defaults; `toArray()` keeps `id`, `type`, `name` and `match` aliases.

- [ ] Step 1: Add packet round-trip tests for resolved Variant context, old packet aliases, missing optional fields and revision/fingerprint serialization.
- [ ] Step 2: Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'EditorialCaptureSemanticCoreTest|SubjectResolutionPacket' --no-progress` and confirm new assertions fail.
- [ ] Step 3: Implement the interface and packet fields without changing resolution policy or persistence owners.
- [ ] Step 4: Run the focused tests and confirm packet round-trip and structural read behavior pass.
- [ ] Step 5: Run `git diff --check` and PHP lint for the changed files.

**Invariant:** The packet is a read-only, revision-bound handoff; structural context is read-only and registry-driven.

### Task 2: Refactor Authority lookup into lookup-only candidate production

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/CanonicalAuthoritySubjectResolver.php`.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`.

**Interfaces:**

- Preserve `resolve(string $hint): list<array<string,mixed>>` and `resolveForType(string $type, array $query): list<array<string,mixed>>` for existing callers.
- Add a bounded method such as `resolveComposite(array $hints): list<array<string,mixed>>` that searches only active registered Authority identities and returns canonical candidate packets; it must not synthesize an identity.
- Candidate packets include `id`, `type`, `stable_key`, `name`, `revision`, `match`, `compatibility`, and normalized lookup diagnostics.

- [ ] Step 1: Add failing tests for exact name/alias precedence, Brand plus designation composite lookup, zero composite results and multiple compatible composite results.
- [ ] Step 2: Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'EditorialCaptureSemanticCoreTest' --no-progress` and record the first failure.
- [ ] Step 3: Implement composite lookup by querying existing active Authority records and matching registered fields/aliases/reference; reject retired/inactive entities.
- [ ] Step 4: Verify the resolver returns candidates only and does not select a primary, create Authority or access Graph mutation services.
- [ ] Step 5: Re-run the focused semantic test file and lint the resolver.

**Invariant:** Authority lookup never decides hierarchy policy and never creates a canonical entity.

### Task 3: Implement shared hierarchical decision policy

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SubjectResolutionService.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/CanonicalAuthoritySubjectResolver.php` only if Task 2 exposes a lookup contract gap.
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` for one shared construction/wiring path.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php` and the nearest resolver tests.

**Interfaces:**

- Preserve `resolve(array $hints): array` and `resolveSources(array $sources): array` return shape for existing callers.
- Construct `SubjectResolutionService` with the lookup callable/adapter and optional `SubjectStructuralContextReader`; existing test callables remain valid through a compatibility default.
- Return normalized `state`, compatibility `status`, `primary`, `subjects`, `compatible_context`, `candidates`, `conflicts`, `unresolved`, `diagnostics`, `continuation`, and `primary_source`.

- [ ] Step 1: Add failing tests for matrix cases A–L: compatible Brand/Variant, Brand/Model, Brand-only, exact Variant, composite Variant, designation collision, contradiction, correct parent, noise, UUID precedence, UUID conflict and missing identity.
- [ ] Step 2: Run the focused resolver suite and verify the existing Brand-first regression fails before the policy change.
- [ ] Step 3: Implement gather-before-locking, composite explicit-hint lookup, structural compatibility classification and narrowest-unique selection. Compatible ancestors move to `compatible_context`; unrelated same-type identities remain ambiguity; contradictions return conflict.
- [ ] Step 4: Add lexical-quality filtering for body/title candidates using canonical resolvability and entity-shape/source precedence, without a fixture-specific word list.
- [ ] Step 5: Normalize internal states and attach candidate IDs/types/revisions, rejection reasons and allowed continuation action.
- [ ] Step 6: Re-run matrix A–L, then run existing Capture semantic tests to detect compatibility regressions.
- [ ] Step 7: Lint changed PHP and run `git diff --check`.

**Invariant:** Specificity is applied only after active canonical identity, registered type, structural compatibility, unique narrowest candidate, current revision and contradiction checks pass.

### Task 4: Lock and propagate the packet through preparation and downstream owners

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php` only to assert/consume the packet projection rather than resolve again.
- Modify: the existing Video enrichment/knowledge/relation handoff code only where it currently rebuilds subject identity.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`, `EditorialCaptureSemanticCoreTest.php`, `CaptureVideoProvenancePlannerTest.php` and affected Video tests.

**Interfaces:**

- `ContentPreparationOrchestrator` emits one `SubjectResolutionPacket` for a prepared Capture and passes its projection under the existing `subject_resolution_packet` key.
- Video, Article, Knowledge and relation callbacks receive the same canonical UUID/type/revision and may read compatible context but cannot replace `primary`.

- [ ] Step 1: Add failing handoff tests for matrix cases N and cross-domain intent isolation across VIDEO, TEXT_ARTICLE, IMAGE_ARTICLE, KNOWLEDGE_DELTA and MEDIA_ENRICHMENT.
- [ ] Step 2: Run the focused Capture/Video command and confirm any shadow-resolution or packet mismatch failures.
- [ ] Step 3: Make preparation use the normalized resolution state and create the packet once after final resolution; remove/restrict any downstream re-resolution path.
- [ ] Step 4: Pass the packet to Video enrichment, Video relation planning, Knowledge enrichment, Source/Claim/Evidence orchestration and Article/Media contexts without altering their write boundaries.
- [ ] Step 5: Ensure non-Video intents do not invoke Video-only callbacks or receive Video blockers.
- [ ] Step 6: Re-run the focused handoff and intent suites and lint changed files.

**Invariant:** Every downstream semantic decision uses the same Capture-bound packet; packet handoff never authorizes semantic mutation.

### Task 5: Normalize reconciliation and continuation state machine

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` only for state mapping and normal-path resume.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php` only for packet currentness/contradiction checks.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php` and existing subject reconciliation tests.

**Interfaces:**

- Reconciliation accepts only an exact candidate UUID from the persisted bounded candidate set, current active read-back, expected revision and the current Capture/idempotency binding.
- Successful reconciliation returns normalized `SUBJECT_RESOLVED` plus the locked packet; invalid selections return `SUBJECT_AMBIGUOUS`, `SUBJECT_CONFLICT` or `SUBJECT_REVIEW_REQUIRED` with a continuation contract.

- [ ] Step 1: Add failing tests for matrix cases O and P, including wrong UUID, inactive candidate, stale revision, changed payload and valid same-Capture resume.
- [ ] Step 2: Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'EditorialCaptureContinuationTest|SubjectReconciliation' --no-progress` and record failures.
- [ ] Step 3: Implement normalized state admission and packet lock; make valid reconciliation re-enter the normal Capture path rather than a weaker completion-only resolver.
- [ ] Step 4: Preserve prior review receipts while replacing the active subject state; forbid `NOT_AMBIGUOUS` as an internal terminal state.
- [ ] Step 5: Re-run continuation tests and inspect diagnostics for machine-actionable candidate/reason/action fields.

**Invariant:** Reconciliation resumes the existing Capture and idempotency identity; it cannot select an arbitrary Authority UUID or bypass contradiction checks.

### Task 6: Add Video identity/relation regressions and cross-domain coverage

**Files:**

- Test: existing Video subject, provenance, relation and Capture convergence test files nearest the current adapters.
- Test: existing Article/Knowledge/Media intent test files.
- Modify: no production code unless a test exposes a handoff defect from Tasks 1–5.

- [ ] Step 1: Add matrix M: same platform plus external ID reuses the existing Video UUID even when subject resolution changes.
- [ ] Step 2: Add matrix N: Video adapter, Knowledge enrichment and relation planner all receive the same Variant packet; no Brand/Model fallback appears.
- [ ] Step 3: Add Evidence/Governance assertions proving resolution alone does not create Evidence or Graph edges and existing relation gates remain required.
- [ ] Step 4: Run Video, Graph, Governance, Article, Knowledge and Media affected tests; keep failures visible rather than broad-catching them.
- [ ] Step 5: Run contract tests for Capture, Video, Authority, Graph and Governance registries.

**Invariant:** Video external identity and governed relation lifecycle remain unchanged by subject selection.

### Task 7: Full verification, documentation checkpoint and implementation commit

**Files:**

- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with root cause, architectural change, affected components, exact test evidence, remaining gaps and `RUNTIME_VERIFICATION=PENDING_DEPLOYMENT` unless fresh deployment read-back exists.
- No unrelated file changes.

- [ ] Step 1: Run focused resolver, Capture, Video, Article/Knowledge, Authority and Graph/Governance suites.
- [ ] Step 2: Run the full NHK Unit suite with `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --no-progress`.
- [ ] Step 3: Run PHP lint on every changed PHP file, `git diff --check`, and a secret review over the staged diff.
- [ ] Step 4: Review the diff against the spec for fixture-specific production branches, shadow resolvers, new predicates, direct writers, Evidence bypasses and identity downgrade paths.
- [ ] Step 5: Confirm `git status --short` and preserve unrelated pre-existing changes; stage only implementation files, tests, execution-state checkpoint and the plan if required by repository convention.
- [ ] Step 6: Commit the implementation as `fix: resolve canonical subjects hierarchically`.
- [ ] Step 7: Report spec commit, implementation commit, source revision, test results, regression matrix and runtime verification status using the user-requested format.

## Verification command set

```bash
vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'EditorialCaptureSemanticCoreTest|EditorialCaptureContinuationTest|EditorialCaptureConvergenceE2ETest|ContentIntentRouterTest|CaptureVideoProvenancePlannerTest' --no-progress
vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'Video|Graph|Governance|Authority|Article|Knowledge|Media' --no-progress
vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --no-progress
git diff --check
```

The plan intentionally contains no staging/live mutation command. Integration checks may run only against the repository's guarded exact `nhk_v3_test` configuration when available; missing WordPress/database runtime remains an explicit verification gap.
