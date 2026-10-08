# Universal Capture Semantic Handoff Root-Cause Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task, or use superpowers:subagent-driven-development for independent task delegation.

**Goal:** Make Capture’s current lifecycle, retry, owner-track, semantic-plan, completion, and canonical-readback projections converge on one registry-driven outcome for every supported input type.

**Architecture:** Keep the existing Capture/Governance pipeline and persistence model. Add one shared current-outcome decision in `CaptureCurrentOutcomeReducer`, domain-aware normalization in `CaptureEnrichmentPlanningEnvelope`, deterministic candidate normalization in `GovernedCaptureContinuationService`, and current-owner reconciliation in `CompletionCoordinator`; preserve append-only receipts and historical diagnostics.

**Tech Stack:** PHP 8.x, PHPUnit 11.5, existing NHK V3 domain/application services, runtime registries, and WordPress plugin bootstrap wiring.

**Spec:** `docs/superpowers/specs/2026-10-08-universal-capture-semantic-handoff-root-cause-repair.md`

## Global Constraints

- WordPress Posts remain editorial truth; Authority, Knowledge, Source/Evidence, Graph and Governance retain their existing ownership boundaries.
- All semantic types, scopes, facets, provenance classes, predicates, dependencies and idempotency material come from existing runtime registries/contracts.
- Preserve Capture UUID, request fingerprint, idempotency key, original input fingerprint, revision history and Governance receipts.
- A valid Knowledge candidate is never discarded merely because Source/Evidence is absent, pending, or not approved.
- Historical failures, approvals, denials, receipts and retries remain append-only; superseded history is not current state.
- No new semantic owner, entity type, relation, facet, schema field, migration, direct writer, direct SQL write, Governance bypass, seed, backfill, hard delete, deployment, or live semantic mutation.
- Use TDD: each production change begins with a failing regression test and the expected failure is observed before implementation.
- Preserve unrelated worktree changes and do not use destructive Git/database commands.

## Review Focus

- A resolved Authority packet must not become `FAILED_RETRYABLE` merely because its domain status is `resolved`; pinned in Task 1.
- `FAILED_RETRYABLE` must not override a current hard blocker or terminal lifecycle state; pinned in Task 2.
- A valid Knowledge candidate must remain visible when Source/Evidence is pending or absent; pinned in Task 3.
- Duplicate candidates and stale unkeyed owner tracks must not create duplicate plans or missing-owner symptoms; pinned in Tasks 3 and 4.
- An unchanged dependency fingerprint must not permit endless same-state retries or artificial progress revisions; pinned in Task 2 and Task 5.

---

### Task 1: Normalize resolved Authority owner tracks without losing diagnostics

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureEnrichmentPlanningEnvelope.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureEnrichmentPlanningTest.php`

**Interfaces:**
- Consumes: `diagnostics['subjects']` resolution packets and the existing `CaptureOwnerOutcome` normalizer.
- Produces: `ownerTracks['authority']` with a successful current status and canonical read-back identity when a resolved primary subject is present; unresolved/ambiguous packets retain their existing failure/review semantics.

- [ ] **Step 1: Write the failing tests**

Add focused tests named:

- `test_resolved_subject_packet_is_a_successful_authority_owner_track()` — pass `status=resolved` with `primary.id`, `primary.type`, and `primary.revision`; assert Authority status is `READ_BACK_VERIFIED`, canonical read-back contains the primary ID and revision, and no `SUBJECT_NOT_FOUND` blocker is emitted.
- `test_unresolved_subject_packet_retains_retryable_authority_failure()` — pass an unresolved packet with a `SUBJECT_NOT_FOUND` diagnostic; assert Authority remains retryable and retains the diagnostic.
- `test_authority_resolution_does_not_hide_existing_failure_history()` — pass current resolved subjects plus an existing historical `SUBJECT_NOT_FOUND`; assert the history remains available while the current Authority track is successful.

- [ ] **Step 2: Run the new tests and verify the expected red failure**

Run:

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CaptureEnrichmentPlanningTest.php --filter 'resolved_subject_packet|unresolved_subject_packet|authority_resolution'
```

Expected: the resolved-subject test fails because the current generic normalizer maps `resolved` to `FAILED_RETRYABLE`.

- [ ] **Step 3: Implement domain-aware Authority normalization**

Add a private owner-aware normalization path in `CaptureEnrichmentPlanningEnvelope` and use it from `fromState()` for the Authority track. For a resolved packet with a non-empty `primary.id`, map the orchestration status to `READ_BACK_VERIFIED` and construct `canonical_readback` from the persisted primary identity/revision. Delegate all other owner results to the existing generic normalization path; do not change `CaptureOwnerOutcome::normalizeStatus()` globally.

- [ ] **Step 4: Run the focused tests and verify green**

Run the command from Step 2. Expected: all selected tests pass.

- [ ] **Step 5: Commit the independently testable owner-track change**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureEnrichmentPlanningEnvelope.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureEnrichmentPlanningTest.php
git commit -m "fix(capture): normalize resolved authority outcomes"
```

### Task 2: Unify lifecycle and retry decisions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpCaptureReadContractTest.php`

**Interfaces:**
- Consumes: `CaptureRecord`, optional continuation input, current blockers, phase receipts, and dependency fingerprints.
- Produces: `CaptureCurrentOutcomeReducer::currentDecision(CaptureRecord $capture, array $input = []): array` returning `lifecycle_state`, `retry` (`eligible` and nullable `reason`), and current `blockers`. `lifecycleState()` and `retryEligibility()` become projections of this decision; `captureGet()` uses one decision for both fields.

- [ ] **Step 1: Write failing reducer tests**

Add tests named:

- `test_failed_retryable_status_cannot_override_terminal_hard_block()` — assert lifecycle is `TERMINALLY_BLOCKED`, retry is false, and the reason is `CAPTURE_RETRY_NOT_ALLOWED`.
- `test_reviewed_knowledge_handoff_uses_one_decision_for_lifecycle_and_retry()` — assert the returned decision’s lifecycle and retry projections equal the two public methods for the same Capture/input.
- `test_unchanged_dependency_fingerprint_denies_repeated_retry()` — assert a review/retryable Capture with a persisted fingerprint equal to `CaptureDecisionDependencyFingerprint::current()` is not eligible.
- `test_changed_dependency_fingerprint_reopens_only_recoverable_review()` — assert a changed canonical revision makes the same review recoverable and retryable when no hard blocker exists.

- [ ] **Step 2: Run the reducer tests and verify red**

Run:

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php --filter 'terminal_hard_block|one_decision|unchanged_dependency|changed_dependency'
```

Expected: the hard-block test fails because `retryEligibility()` currently returns eligible immediately for `FAILED_RETRYABLE`; the decision test fails because no shared decision projection exists.

- [ ] **Step 3: Implement `currentDecision()` and route all projections through it**

Refactor the existing ordering into one private evaluation path exposed through:

```php
public static function currentDecision(CaptureRecord $capture, array $input = []): array
```

Evaluate convergence, active execution, current/superseded blockers, hard blockers, dependency freshness, and bounded retry in that order. Remove the Article-specific early retry branch from the universal decision path; Article freshness remains an input to the existing Article-specific blocker logic, not a bypass for Knowledge/Authority state. A historical `FAILED_RETRYABLE` status may be retryable only after the current decision confirms the Capture is recoverable and the dependency state has not already been consumed without change.

- [ ] **Step 4: Make `McpReadHandler::captureGet()` use one decision**

Locate the Capture read projection and call `currentDecision()` once with the read-available input. Use its `lifecycle_state`, `retry`, and `blockers` fields for the response. Preserve the existing response shape and all unrelated completion/read-back fields.

- [ ] **Step 5: Run reducer and MCP tests and verify green**

Run:

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpCaptureReadContractTest.php
```

Expected: selected files pass; any unrelated existing failures must be recorded by test identity.

- [ ] **Step 6: Commit the decision unification**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpCaptureReadContractTest.php
git commit -m "fix(capture): unify lifecycle and retry decisions"
```

### Task 3: Make Knowledge handoff planning registry-driven and duplicate-safe

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticCaptureDependencyEligibilityTest.php`

**Interfaces:**
- Consumes: resolved Authority registry definitions, `KnowledgeFacetProfile`, guarded semantic candidates, source/evidence metadata, Capture identity and continuation state.
- Produces: one deterministic Knowledge plan per materially identical candidate, retained valid candidates under pending Source/Evidence, and dependent relation plans only when their Knowledge source is available.

- [ ] **Step 1: Write failing planner tests**

Add tests named:

- `test_duplicate_candidates_produce_one_knowledge_plan_and_one_relation_plan()` — provide duplicate structured candidates with the same subject, normalized text, scope, facet, provenance and evidence identity; assert one Knowledge plan and one dependent relation plan.
- `test_candidates_with_distinct_provenance_remain_distinct()` — provide otherwise equal candidates with different governed provenance/evidence identities; assert both remain.
- `test_valid_knowledge_candidate_survives_missing_source_evidence_as_pending_dependency()` — provide a valid candidate and no Source/Evidence approval; assert the Knowledge plan remains present, with a review/dependency outcome rather than disappearance or `NOT_APPLICABLE`.
- `test_all_registered_authority_types_use_catalog_knowledge_scope()` — iterate the nine canonical catalog types and assert the planner uses each registered scope without a subject-type branch in the service.

- [ ] **Step 2: Run planner tests and verify red**

Run:

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticCaptureDependencyEligibilityTest.php --filter 'duplicate_candidates|distinct_provenance|missing_source_evidence|registered_authority_types'
```

Expected: duplicate candidates currently create repeated plans, and the missing Source/Evidence case currently collapses downstream state instead of preserving the Knowledge candidate.

- [ ] **Step 3: Implement deterministic candidate normalization and deduplication**

Add a private normalization/deduplication pass immediately after semantic guard validation and before Knowledge plan construction. Build the deduplication key from Capture identity, canonical subject ID/type, normalized candidate text, registry-derived scope, facet, provenance and contractually material evidence/source IDs. Preserve input order for the first valid candidate and keep materially distinct candidates separate. Reuse the existing plan/idempotency key format unless a versioned fingerprint change is required by the test; do not add a fixture-specific exception.

- [ ] **Step 4: Preserve Knowledge candidates through Source/Evidence dependency states**

Adjust the planning/result aggregation so missing or pending Source/Evidence does not make a valid Knowledge candidate disappear. Use the existing review/dependency statuses and blocker vocabulary; only use `NOT_APPLICABLE` when the owner is not required by the current intent/dependency graph. Keep relation plans dependent on successful or canonical Knowledge availability.

- [ ] **Step 5: Run planner tests and the existing Governance slice**

Run:

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticCaptureDependencyEligibilityTest.php
```

Expected: selected planner and dependency tests pass with no direct-writer or Governance-bypass path introduced.

- [ ] **Step 6: Commit planner normalization**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticCaptureDependencyEligibilityTest.php
git commit -m "fix(capture): preserve and deduplicate knowledge handoff plans"
```

### Task 4: Reconcile stale completion owners against current canonical read-back

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CompletionCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureEnrichmentPlanningEnvelope.php` if Task 1 exposes a shared normalization helper required by completion projection
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureEnrichmentPlanningTest.php`

**Interfaces:**
- Consumes: append-only completion children, owner type/ID, `current_outcome`, canonical read-back and historical receipts.
- Produces: an effective current owner set where a newer keyed current read-back supersedes an older unkeyed track of the same owner type without merging distinct keyed owners.

- [ ] **Step 1: Write failing completion tests**

Add tests named:

- `test_keyed_current_knowledge_readback_supersedes_stale_unkeyed_knowledge_child()` — assert effective children contain the keyed current Knowledge owner and no derived missing-owner blocker.
- `test_distinct_keyed_knowledge_owners_are_not_merged()` — assert two current Knowledge IDs remain separate.
- `test_historical_unkeyed_child_remains_in_receipts_but_not_current_completion()` — assert raw history is preserved while aggregate completion uses only the effective current owner set.
- `test_required_owner_readback_blocker_clears_after_current_knowledge_readback()` — assert the stale symptom disappears while a real Governance blocker remains.

- [ ] **Step 2: Run completion tests and verify red**

Run:

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureEnrichmentPlanningTest.php --filter 'keyed_current_knowledge|distinct_keyed_knowledge|historical_unkeyed|required_owner_readback'
```

Expected: the current reducer keeps unkeyed and keyed children as separate effective entries, so the stale missing-owner symptom remains.

- [ ] **Step 3: Implement current-owner precedence in `effectiveChildren()`**

Update the existing effective-child grouping to retain historical raw children but let a current keyed child supersede an unkeyed child of the same owner type. Never collapse two keyed owner IDs. Keep `current_outcome` precedence and existing semantic dependency/public projection behavior intact.

- [ ] **Step 4: Run completion and Capture convergence tests**

Run:

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureEnrichmentPlanningTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
```

Expected: current-owner completion tests pass; existing Article/Video convergence behavior remains unchanged.

- [ ] **Step 5: Commit current-owner reconciliation**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/CompletionCoordinator.php public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureEnrichmentPlanningTest.php
git commit -m "fix(capture): reconcile current completion owners"
```

### Task 5: Verify the universal matrix and record the implementation checkpoint

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: relevant files from Tasks 1–4; add a dedicated test only if an existing focused file cannot express the supplied Capture regression matrix.

**Interfaces:**
- Consumes: all repaired projections and existing Governance/readback contracts.
- Produces: fresh evidence for local behavior, explicit full-suite baseline comparison, and a truthful execution-state checkpoint.

- [ ] **Step 1: Add the supplied Capture regression fixture matrix**

Use synthetic/persisted fixtures for Capture `01a11bc5-125f-78de-98ac-b4535dfc2886` semantics without contacting or mutating an external runtime. Cover original fingerprint/idempotency preservation, revision sequence shape, resolved Music subject, `KNOWLEDGE_DELTA`, candidate retention, stale subject diagnostic supersession, retry eligibility/lifecycle agreement, and no-progress repeated retry denial.

- [ ] **Step 2: Run the focused universal verification command**

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'UniversalKnowledgeBranchTest|GovernedCaptureContinuationServiceTest|CaptureCurrentOutcomeReducerTest|EditorialCaptureContinuationTest|CompletionConvergenceTest|McpCaptureReadContractTest|CaptureEnrichmentPlanningTest|SemanticCaptureDependencyEligibilityTest'
```

Expected: all changed-scope tests pass; report warnings/deprecations separately.

- [ ] **Step 3: Run static and contract checks**

Run PHP lint on every changed PHP file, the NHK Contract suite, `git diff --check`, and the repository’s changed-scope secret review. Expected: all checks pass.

- [ ] **Step 4: Run the full unit baseline comparison**

Run the project’s documented full Unit command under the available memory setting. Compare failure/error identities with the pre-existing baseline; do not hide or downgrade unrelated failures.

- [ ] **Step 5: Update execution state with evidence and boundaries**

Read `docs/architecture/V3_EXECUTION_STATE.md` immediately before the checkpoint update. Record root causes fixed, focused counts, full-suite baseline identities, lint/diff/secret results, runtime availability, and explicitly state that no semantic mutation, deployment, approval, controlled apply or public completion occurred.

- [ ] **Step 6: Final verification before claiming completion**

Run `git status --short --branch`, `git diff --check`, and the final focused command from Step 2. Confirm no local env, credential, dump, token or private key is staged. Only then report the implementation status.

