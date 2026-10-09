# Universal MCP Outcome Completion Law Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every accepted MCP Capture report the verified outcome requested by the caller, while preserving canonical ownership, Governance, idempotency, private semantic boundaries and exact public/frontend read-back.

**Architecture:** Add one application-level `OutcomeObligationCompiler` that derives a receipt-safe in-memory plan immediately after Content Intent resolution. Carry that plan through existing Capture diagnostics/context and feed it into `CompletionCoordinator`; the coordinator remains the sole aggregate completion authority. Existing domain services continue to own canonical writes and their existing receipts supply the domain-specific proof.

**Tech Stack:** PHP 8.x, PHPUnit 11, existing NHK V3 application/domain contracts, WordPress-native Article ownership, MCP catalog/dispatch/Ability registration.

**Spec:** `docs/superpowers/specs/2026-10-09-universal-mcp-outcome-completion-law-design.md`

## Global Constraints

- No new entity type, endpoint type, predicate, relation type, taxonomy, semantic owner, public route namespace or persistence owner.
- `REQUIRED`, `CONDITIONAL` and `OPTIONAL` are derived obligation classifications, not domain lifecycle statuses.
- No direct WordPress writer, direct database write, Governance bypass or generic adapter fallback.
- No automatic Evidence creation from OCR, transcript, captions, metadata, generated prose or user hints.
- No public requirement for private/internal semantic dependencies.
- No homepage requirement unless the request or existing domain policy makes it applicable.
- No live fixture replay, staging mutation, production mutation, deployment, push or external publish.
- Changed behavior must be implemented red-green: write the failing test, observe the expected failure, implement the minimum code, then rerun the test and the relevant suite.
- Preserve Capture UUID, canonical owner identity, revisions, source identity, idempotency, receipts and bounded recovery semantics.

## Review Focus

- Explicit `publish=true` with canonical read-back but no public/frontend proof must remain incomplete; owned by Tasks 2–3.
- Private Knowledge/Source/Evidence dependencies must remain `NOT_APPLICABLE` with a deterministic reason and must not acquire an invented public requirement; owned by Tasks 1–2.
- Optional homepage absence must not block a verified detail/archive outcome unless homepage was explicitly requested or policy-required; owned by Tasks 1–2.
- Replay after a stored obligation fingerprint or dependency revision changes must fail closed without creating a duplicate owner or reapplying Governance; owned by Task 3.
- A server-registered `nhk.video.frontend.reconcile` capability must remain distinct from a connector/client exposure gap; owned by Task 4.

---

### Task 1: Add the shared outcome-obligation compiler

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Completion/OutcomeObligationCompiler.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php`

**Interfaces:**
- Consumes: `capture_id`, resolved `content_intent`, public/publish signals, owner types/capabilities, dependency owner types and homepage request/policy inputs.
- Produces: `compile(string $captureId, array $intent, array $signals = []): array`, returning `version`, `capture_id`, `owner_types`, `public_request`, `obligations`, `fingerprint` and deterministic applicability/reason fields.

- [ ] **Step 1: Write the failing compiler tests**

  Add tests for:
  - every accepted owner receiving `canonical=REQUIRED`;
  - `publish=true` producing `publication=REQUIRED`, `public=REQUIRED` and `frontend=REQUIRED` for a public-capable Article/Video request;
  - private Knowledge/Source/Evidence dependency producing public/frontend `NOT_APPLICABLE` with a non-empty reason;
  - homepage being `OPTIONAL` unless explicitly requested or policy-required;
  - equivalent normalized inputs producing the same fingerprint and changed owner/revision inputs producing a different fingerprint;
  - ambiguous applicability throwing a fail-closed `InvalidArgumentException` with a stable diagnostic code.

- [ ] **Step 2: Run the focused test to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php`

  Expected: FAIL because `OutcomeObligationCompiler` does not yet exist.

- [ ] **Step 3: Implement `OutcomeObligationCompiler::compile()`**

  Normalize only already-resolved intent/signals; do not resolve subjects, create owners, infer relations or invent public capability. Emit the obligation classes from the spec, preserve owner/revision bindings in the fingerprint, and use the existing `CommandCanonicalizer` for deterministic canonicalization.

- [ ] **Step 4: Run the focused test to verify GREEN**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php`

  Expected: PASS with all compiler assertions green.

- [ ] **Step 5: Commit**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Completion/OutcomeObligationCompiler.php public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php
  git commit -m "feat: compile capture outcome obligations"
  ```

### Task 2: Make aggregate completion enforce required public/frontend outcomes

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalOwnerLifecycleAcceptanceTest.php`

**Interfaces:**
- Consumes: Task 1 obligation plan under `evidence['outcome_obligations']` for `finalize()` and `aggregateCapture()`.
- Produces: completion packets that preserve canonical fields, expose the plan/fingerprint, keep truthful `NOT_APPLICABLE` reasons, and prevent aggregate `complete=true` when a required public/frontend obligation is unresolved.

- [ ] **Step 1: Write the failing completion tests**

  Add tests for:
  - `publish=true` plus canonical read-back and missing public read-back not being complete;
  - public read-back plus missing exact frontend read-back not being complete;
  - private semantic dependency remaining complete with public/frontend `NOT_APPLICABLE` and a reason;
  - optional homepage absence not blocking detail/archive completion;
  - `REQUIRED`, `CONDITIONAL`, `OPTIONAL` aggregation being deterministic;
  - historical child projections not overriding the current obligation-bound result.

- [ ] **Step 2: Run the focused tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php public/wp-content/plugins/nhk-core/tests/Unit/UniversalOwnerLifecycleAcceptanceTest.php`

  Expected: FAIL on the new assertions because current aggregation filters required public/frontend gaps and hardcodes aggregate publication as `NOT_APPLICABLE`.

- [ ] **Step 3: Implement the minimum coordinator change**

  Extend the existing evidence path rather than changing the public method shape. When an obligation plan is present, classify public/frontend/publication/homepage states from the plan, attach deterministic reasons to `NOT_APPLICABLE`, include required surface blockers in `ownerBlockers`/aggregate blockers, and preserve compatibility for callers without a plan. Keep optional enrichment as warnings/gaps and preserve semantic dependency normalization.

- [ ] **Step 4: Run the focused tests to verify GREEN**

  Run the same PHPUnit command from Step 2.

  Expected: PASS, with existing historical/current-child convergence tests still green.

- [ ] **Step 5: Run the related baseline slice**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php public/wp-content/plugins/nhk-core/tests/Unit/UniversalOwnerLifecycleAcceptanceTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentCompletionRegressionTest.php`

  Expected: PASS; any pre-existing failure must be recorded in the ledger before continuing.

- [ ] **Step 6: Commit**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php public/wp-content/plugins/nhk-core/tests/Unit/UniversalOwnerLifecycleAcceptanceTest.php
  git commit -m "fix: enforce requested public completion outcomes"
  ```

### Task 3: Bind the plan to Capture admission, final read-back and recovery

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/AuthorityCaptureService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`

**Interfaces:**
- Consumes: `OutcomeObligationCompiler` from Task 1 and the coordinator behavior from Task 2.
- Produces: persisted Capture diagnostics/context containing the original obligation fingerprint; Article, Video, Media, Authority and semantic continuations passing the same plan into aggregate completion; fail-closed replay when the binding changes.

- [ ] **Step 1: Write failing Capture admission/recovery tests**

  Add tests proving:
  - the plan is compiled immediately after Content Intent resolution and is present in Capture diagnostics/context;
  - Article `publish=true` passes the plan to final aggregation and cannot return `COMPLETE` without publication/frontend proof;
  - non-Article Video/Media/Knowledge paths use the same plan without fabricating an Article or public requirement;
  - retry reuses the stored plan/fingerprint and reports `OUTCOME_OBLIGATION_BINDING_CHANGED` on changed intent/owner/revision inputs;
  - replay reuses existing canonical IDs/receipts and never calls a second owner creation or Governance apply.

- [ ] **Step 2: Run the focused Capture tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`

  Expected: FAIL because no shared outcome plan is currently persisted or supplied to all final/recovery aggregate calls.

- [ ] **Step 3: Wire admission and finalization**

  Inject one compiler instance with the existing optional-dependency pattern. Compile after `ContentIntentRouter::route()`/`reusePersisted()`, store the plan/fingerprint in existing Capture context/diagnostics, and pass it through Article and non-Article `aggregateCapture()` calls. Authority Capture must compile its Authority/Mixed plan at admission and approval; Governed continuation and MCP read aggregation must reuse the persisted plan rather than recompute from incomplete child packets.

- [ ] **Step 4: Wire bounded recovery**

  Before retry/re-entry, compare the stored fingerprint and relevant dependency revisions. On mismatch, stop with a deterministic review/block outcome; on a match, reuse existing canonical IDs, phase receipts and idempotency keys and continue only the unresolved child. Do not introduce a new writer or mutation path.

- [ ] **Step 5: Run the focused Capture tests to verify GREEN**

  Run the same command from Step 2.

  Expected: PASS, including idempotency and historical receipt assertions.

- [ ] **Step 6: Commit**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Capture/AuthorityCaptureService.php public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php
  git commit -m "feat: bind capture recovery to outcome obligations"
  ```

### Task 4: Prove domain parity and separate server capability from client exposure

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoFrontendReconciliationServiceTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalOutcomeObligationParityTest.php`
- Inspect/modify only if a regression is proven: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`, `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDispatchRegistry.php`, `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`

**Interfaces:**
- Consumes: Task 1 compiler recipes and Task 2 completion packet semantics.
- Produces: executable regression evidence for Dictionary, Media, Video, Article, Authority, Knowledge/Source/Evidence and Graph; server-side `nhk.video.frontend.reconcile` evidence independent of connector/client discovery.

- [ ] **Step 1: Write the failing parity/exposure tests**

  Cover each registered domain with neutral synthetic identities and assert canonical proof, required/conditional/optional surface classification, private dependency behavior, missing dependency, ambiguous subject, relation/read-back failure and replay semantics. Assert the Video frontend tool remains in catalog, dispatch and Ability registration; represent an unavailable client discovery as `CLIENT_EXPOSURE_GAP`, never as missing server capability.

- [ ] **Step 2: Run the focused parity tests to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/UniversalOutcomeObligationParityTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoFrontendReconciliationServiceTest.php`

  Expected: FAIL only where the new obligation or exposure assertions are not yet represented.

- [ ] **Step 3: Implement only proven wiring gaps**

  Reuse runtime registries, existing owner receipts and the read-only `VideoFrontendReconciliationService`. Do not add a connector fallback, a new MCP tool, an unregistered predicate, a fixture mutation or a synthetic real-runtime record.

- [ ] **Step 4: Run the focused parity tests to verify GREEN**

  Run the same command from Step 2.

  Expected: PASS with server capability and client exposure classified separately.

- [ ] **Step 5: Commit**

  ```bash
  git add public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoFrontendReconciliationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/UniversalOutcomeObligationParityTest.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDispatchRegistry.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php
  git commit -m "test: prove universal outcome domain parity"
  ```

### Task 5: Register the active cross-domain contract and complete verification

**Files:**
- Create: `docs/architecture/NHK_V3_UNIVERSAL_OUTCOME_COMPLETION_CONTRACT.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

**Interfaces:**
- Consumes: implemented packet fields, blocker/reason vocabulary and tested MCP capability from Tasks 1–4.
- Produces: one ACTIVE subordinate contract that references, rather than duplicates, domain ownership; documentation status and execution evidence that distinguish local tests from unavailable proposal/runtime/client evidence.

- [ ] **Step 1: Write the failing contract/bootstrap assertions**

  Assert the new contract is ACTIVE, names the exact obligation fields and reasons, references the owning contracts, prohibits private-public coercion/direct writers, and records `DOCUMENT_UNAVAILABLE` for the supplied proposal commit when still unavailable.

- [ ] **Step 2: Run the contract test to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

  Expected: FAIL because the new contract/status references do not yet exist.

- [ ] **Step 3: Write the contract and status evidence**

  Document the shared admission → canonical → public/frontend → verified outcome lifecycle, domain recipe references, recovery/idempotency law, exact read-back law, server/client exposure distinction, real Video fixture expectations, and the non-mutation/runtime gate. Update the status index and execution state with only verified local evidence.

- [ ] **Step 4: Run contract/bootstrap verification**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php` and the repository documentation/bootstrap check required by the existing MCP contract.

  Expected: PASS; no documentation registry mismatch.

- [ ] **Step 5: Run final verification**

  Run:
  - `php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml.dist`
  - `find public/wp-content/plugins/nhk-core/src public/wp-content/plugins/nhk-core/tests/Unit -name '*.php' -print0 | xargs -0 -n1 php -l`
  - `git diff --check`
  - scoped secret review for changed files

  Expected: changed tests/code and all applicable gates pass; any unrelated baseline/environment failures are listed exactly and do not get hidden.

- [ ] **Step 6: Commit documentation and final evidence**

  ```bash
  git add docs/architecture/NHK_V3_UNIVERSAL_OUTCOME_COMPLETION_CONTRACT.md docs/mcp/MCP_V3_CONTENT_OPERATIONS.md docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md docs/architecture/V3_EXECUTION_STATE.md public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php
  git commit -m "docs: register universal outcome completion contract"
  ```

## Execution Handoff

The implementation has tightly coupled interfaces and a shared completion seam, so the recommended execution method is **Native**: implement each task in this session with TDD, then perform one fresh whole-branch review. Subagent-driven execution is possible but would add repeated context setup without isolating independent ownership boundaries.

Plan complete and saved to `docs/superpowers/plans/2026-10-09-universal-mcp-outcome-completion-law.md`. Please review the plan and confirm that it captures the requested scope and that **Native** execution is acceptable before implementation begins.
