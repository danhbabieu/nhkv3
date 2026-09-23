# Video Intake Intelligent Constraints Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make Video Intake interpret short input, resolve subjects semantically, classify claims independently, self-repair bounded copy failures, and block only genuine identity/integrity/core-support failures.

**Architecture:** Add pure decision value objects and policies under `Application/Video`, then adapt the existing Capture/Video orchestration to consume their serializable output. Keep canonical repositories and Governance as owners; the engine only reads bounded context and emits a plan/trace. Replace artifact-wide quality blocking with claim-local findings aggregated after up to three repair rounds.

**Tech Stack:** PHP 8.x, PHPUnit, existing NHK Core domain/application ports, WordPress plugin test bootstrap.

**Spec:** `docs/superpowers/specs/2026-09-23-video-intake-intelligent-constraints-design.md`

## Global Constraints

- No Golden Capture, UUID, YouTube ID, Odo, brand, model, or video-specific branch.
- No MCP/live canonical mutation, direct database writer, new semantic owner, or Governance bypass.
- `USER_HINT` remains contextual; it is not Knowledge/Evidence without the governed authority pipeline.
- Preserve canonical Video UUID, idempotency, revisions, provenance, relation Evidence requirements, and Capture ownership.
- Claim findings are independent; missing non-essential enrichment or visual support cannot block the whole artifact.
- Hard blocks are limited to unresolved canonical identity, unreconciled core conflict, required approval, indispensable unsupported core claim, or integrity/ownership violation.

## Review Focus

- A short input with enough bounded facets auto-resolves and records why; test in the decision engine task.
- A specimen observation is public only with specimen/video scope and attribution; test in statement classification.
- A secondary missing Visual Support finding is repairable or removable, while a core indispensable claim remains hard-blocked; test in constraint aggregation.
- A persisted retry reuses the existing Capture/Video identity and trace; test in integration convergence.
- The Golden fixture follows the same generic path as unrelated brands/models/variants; test by parameterized acceptance data without identifiers in production code.

### Task 1: Add claim classification, constraint and decision-trace primitives

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoStatementClassification.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoConstraintSeverity.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAction.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoDecisionTrace.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoConstraintFinding.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoIntelligentConstraintPrimitivesTest.php`

**Interfaces:**
- `VideoDecisionTrace::record(array $statement, string $classification, array $support, array $scope, float $confidence, string $action, string $reason): self` returns an immutable trace entry.
- `VideoConstraintFinding` carries `code`, `severity`, `scope` (`claim` or `artifact`), `claim_id`, `repair`, and `reason`, and serializes deterministically.
- Enums/constants expose exactly the seven classifications, four severities, and registered repair actions from the spec.

- [ ] **Step 1: Write failing tests** for all enum values, required trace keys, deterministic serialization, and rejection of unknown severity/classification/action.
- [ ] **Step 2: Run the focused test** and confirm it fails because the primitives do not exist.
- [ ] **Step 3: Implement the minimal immutable value objects** with strict validation and no domain-specific fixture values.
- [ ] **Step 4: Run the focused test** and confirm it passes.
- [ ] **Step 5: Run PHP lint on the new files and `git diff --check`.**

### Task 2: Implement intelligent statement comparison and treatment

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoStatementDecisionEngine.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoStatementDecisionResult.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoStatementDecisionEngineTest.php`

**Interfaces:**
- `VideoStatementDecisionEngine::evaluate(array $statements, array $canonicalContext, array $evidenceContext, array $visualContext = []): VideoStatementDecisionResult`.
- Each result item contains the original normalized statement, one classification, support references, scope, confidence, chosen action, reason, and local findings.
- The engine treats observations as scoped media/specimen statements, canonical facts as ordinary copy input, in-scope inference as qualified copy, unsupported expansion as removal, and conflicts as canonical/evidence preference plus a diagnostic.

- [ ] **Step 1: Write failing tests** for canonical support, specimen observation attribution, source support, safe inference, uncertainty narrowing, unsupported removal, core/secondary conflict, and `USER_HINT` contextual behavior.
- [ ] **Step 2: Run the focused test** and confirm the expected classifications/actions are absent.
- [ ] **Step 3: Implement normalization/comparison** using field provenance and scope supplied by context; do not infer canonical truth from text alone.
- [ ] **Step 4: Run the focused test** and confirm all classifications pass, including unrelated subject names.
- [ ] **Step 5: Lint and diff-check the task.**

### Task 3: Replace subject lookup with scored semantic discrimination

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SubjectResolutionService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSubjectResolutionDecision.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSubjectResolutionDecisionTest.php`
- Modify: existing subject-resolution tests only where assertions encode the old generic ambiguity behavior.

**Interfaces:**
- `VideoSubjectResolutionDecision::resolve(array $input, array $candidates, array $context = []): array` returns `decision` (`AUTO_RESOLVE`, `HUMAN_REVIEW`, `HARD_BLOCK`), selected candidate, scored candidates, discrimination features, confidence, conflicts, and trace entries.
- Candidate scoring consumes canonical name/alias, parent/model, configuration facets, music, movement, observations and bounded context. Thresholds are relative/semantic and configured by policy, never by entity identity.

- [ ] **Step 1: Write failing tests** for clear facet-based resolution, true tie, incompatible parent conflict, user observation aiding resolution, and generic unrelated brand/model/variant fixtures.
- [ ] **Step 2: Run the focused test** and observe current resolver lacks the decision/trace output.
- [ ] **Step 3: Implement scoring and semantic discrimination** while preserving existing exact UUID/stable-key validation and immutable packet handoff.
- [ ] **Step 4: Run focused subject and existing Capture resolver tests.**
- [ ] **Step 5: Lint and diff-check.**

### Task 4: Add claim-local quality aggregation and bounded repair loop

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialRepairPlanner.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialDecisionPipeline.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialQualityPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialQualityGate.php` only at its shared aggregation seam.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialDecisionPipelineTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialQualityGateTest.php` for new severity semantics.

**Interfaces:**
- `VideoEditorialDecisionPipeline::run(array $package, array $decisionContext, callable $compose, callable $critique): array` returns `quality`, `editorial_package`, `trace`, `rounds`, and structured findings.
- `VideoEditorialRepairPlanner::plan(array $findings, array $package, int $round): array` returns only registered repair operations; `apply` is explicit and deterministic.
- Pipeline performs at most three rounds and preserves claim-local failures. It returns `READY`, `REVIEW_REQUIRED`, or `HARD_BLOCK` from unresolved aggregate severity.

- [ ] **Step 1: Write failing tests** for repairable scope/attribution, unsupported sentence removal, universal-to-scoped wording, title narrowing, alternate Knowledge selection, section regeneration, secondary visual gap, core visual gap, and three-round exhaustion.
- [ ] **Step 2: Run focused tests** to verify the current validator blocks too early or emits only generic `VIDEO_EDITORIAL_QUALITY_BLOCKED`.
- [ ] **Step 3: Implement the registered repair planner and aggregate policy** without broad catches or generic success fallback.
- [ ] **Step 4: Run focused quality/composer/video tests** and confirm old valid behavior remains valid.
- [ ] **Step 5: Lint and diff-check.**

### Task 5: Integrate the decision engine into Video Capture preparation and retry convergence

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationResult.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php` only for persisted retry eligibility if required by the new packet.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoIntelligentConstraintsAcceptanceTest.php`

**Interfaces:**
- Preparation result adds `decision_trace`, `constraint_findings`, `quality_decision`, and `repair_rounds` while retaining existing packet/fingerprint serialization and raw-body omission.
- Coordinator consumes the decision output before governed dependency planning; no direct Knowledge/Evidence/Graph write is introduced.
- Retry path rehydrates the same Capture packet and delegates existing Video idempotency/reconciliation, never creates a second owner.

- [ ] **Step 1: Add failing acceptance tests** for short input, observation, facet-resolvable ambiguity, real ambiguity, mixed facts, unsupported/conflicting details, sparse/rich Knowledge, visual support variants, persisted retry, duplicate idempotency, unrelated entity fixtures, and Golden as an ordinary data row.
- [ ] **Step 2: Run the acceptance tests** and capture the old generic block/early-review failures.
- [ ] **Step 3: Integrate the pure engine and pipeline** into preparation/adapter flow, preserving governed lifecycle boundaries and existing exact-target conflict behavior.
- [ ] **Step 4: Run the focused Capture/Video suite** and verify same Video UUID/no duplicate through read-only fakes.
- [ ] **Step 5: Lint, diff-check, and inspect serialized trace for secrets/raw bodies.**

### Task 6: Update execution evidence and run complete verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md` only if the current checkpoint wording is directly affected.
- Test: focused PHPUnit command for all changed Video/Capture/Semantic tests.

- [ ] **Step 1: Run focused PHPUnit** and record test/assertion counts plus any environment-gated failures.
- [ ] **Step 2: Run the full Unit suite** using the repository's canonical command; do not claim integration success if the guarded WordPress runtime is unavailable.
- [ ] **Step 3: Run PHP lint for changed PHP files, `git diff --check`, and a secret review.**
- [ ] **Step 4: Inspect the final diff for Golden/identity special cases and mutation-capable calls.**
- [ ] **Step 5: Update execution state** with architecture, severity policy, acceptance matrix, Golden trace evidence, and exact full-test result.
- [ ] **Step 6: Run final verification before reporting completion.**

## Self-review

- Spec coverage: all seven classifications, contextual USER_HINT, semantic resolution, severity aggregation, bounded repairs, trace persistence, idempotency and generic acceptance rows map to Tasks 1–6.
- Placeholder scan: no TBD/TODO/"implement later"/unbounded error-handling steps are present.
- Type consistency: Task 1 primitives feed Task 2 result items; Task 3 emits resolution decisions; Task 4 consumes findings and returns quality; Task 5 serializes those exact fields.
- Review focus: all five high-risk inputs have explicit tests in Tasks 2–5.
