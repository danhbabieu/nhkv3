# Capture Retry Superseded Failure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Capture retry decisions use the latest effective phase outcome while retaining every historical failure attempt and preserving all existing gates.

**Architecture:** Keep `CapturePhaseReceiptReducer` as the single append-only receipt/effective-outcome boundary. Add current-versus-historical reconciliation in `CaptureCurrentOutcomeReducer`, then make the coordinator, MCP read projection and retry executor consume that shared result. No new Capture owner, receipt store, failure-code exception or direct mutation path is introduced.

**Tech Stack:** PHP 8.5, PHPUnit 11.5, WordPress plugin runtime, existing Capture repository and MCP transport/application services.

**Spec:** `docs/superpowers/specs/2026-10-06-capture-retry-superseded-failure-design.md`

## Global Constraints

- Preserve immutable/auditable phase attempts; never delete historical failure records.
- Historical failure is not a current blocker after the latest effective phase outcome supersedes it.
- Retry remains bound to the same Capture ID, exact original idempotency key, request fingerprint, documentation checkpoint, revision/CAS and Governance controls.
- Do not special-case `CAPTURE_UTF8_INVALID`, Odo 24, or any other failure code.
- Do not bypass `ARTICLE_PRE_CREATE_REVIEW`, Article quality gates or Governance.
- Do not alter the c908cbfb UTF-8 fix or its regression tests.
- No staging/production mutation, migration, deployment, push or unrelated refactoring.
- Preserve the existing dirty duplicate-audit worktree changes; stage only files belonging to this plan.

## Review Focus

- A legacy receipt without an `attempts` list must still expose one effective latest outcome; test this in the receipt reducer task.
- A retry that fails with the same code must keep that code current, not classify it as superseded; test this in the current-outcome task.
- A current owner review or system-blocked result must remain blocking even when an older retryable code is superseded; test both in the current-outcome task.
- A successful retry followed by later phase receipts must not resurrect an earlier code through completion diagnostics or MCP projection; test this in coordinator/projection integration coverage.
- Idempotent replay and changed-fingerprint retry must preserve the existing Capture binding and avoid duplicate Article/semantic outputs; test this in continuation coverage.

## File Map

- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/CapturePhaseReceiptReducer.php`: central latest/current/superseded receipt queries.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php`: shared current blocker/failure and diagnostic reconciliation policy.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`: append current receipt outcomes without inheriting stale top-level failures; reconcile diagnostics after each append.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php`: expose current effective blockers/retry state while retaining bounded audit behavior.
- Modify `public/wp-content/plugins/nhk-core/tests/Unit/CapturePhaseReceiptReducerTest.php`: reducer-level supersession cases.
- Modify `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`: current-state and blocker matrix.
- Modify `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`: coordinator retry lifecycle, replay/fingerprint binding and no duplicate owner behavior.
- Modify `public/wp-content/plugins/nhk-core/tests/Unit/McpCaptureReadContractTest.php`: MCP current projection regression.
- Modify `docs/architecture/V3_EXECUTION_STATE.md`: dated local verification checkpoint after implementation.

## Interfaces

Task 1 produces these receipt APIs for later tasks:

```php
public static function latest(array $receipt): array;
public static function currentFailureCodes(array $receipts): array;
public static function supersededFailureCodes(array $receipts): array;
```

Task 2 produces these current-state APIs:

```php
public static function currentBlockers(array $diagnostics, array $phaseReceipts): array;
public static function reconcileDiagnostics(array $diagnostics, array $phaseReceipts): array;
```

`currentBlockers()` returns only effective current failure/blocker codes. It
must preserve non-failure owner-review/system-blocked codes and exclude a code
only when receipt history proves it was superseded.

### Task 1: Centralize effective phase receipt semantics

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CapturePhaseReceiptReducer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CapturePhaseReceiptReducerTest.php`

**Interfaces:**
- Consumes the existing persisted receipt shape, including legacy receipts,
  `attempts`, `latest`, `current_outcome` and `failure_code`.
- Produces `latest()`, `currentFailureCodes()` and
  `supersededFailureCodes()` for current-state consumers.

- [ ] **Step 1: Write failing reducer tests**

Add focused tests named:

- `test_latest_normalizes_legacy_receipt_without_attempts`
- `test_current_failure_codes_use_only_latest_failed_attempts`
- `test_completed_latest_attempt_exposes_prior_failure_as_superseded`
- `test_same_failure_on_latest_retry_remains_current`
- `test_different_latest_failure_replaces_prior_code_but_preserves_history`

Assert that attempts remain count-stable and unchanged, `latest` is the only
effective attempt, and superseded codes are returned only as historical data.

- [ ] **Step 2: Run the reducer tests and verify the expected failures**

Run:

```bash
vendor/bin/phpunit --filter CapturePhaseReceiptReducerTest public/wp-content/plugins/nhk-core/tests/Unit
```

Expected: the new helper tests fail because the APIs do not yet exist or do
not yet distinguish current and superseded outcomes; existing tests remain
diagnostic evidence rather than being weakened.

- [ ] **Step 3: Implement the reducer helpers**

Implement the exact interfaces above in `CapturePhaseReceiptReducer`. Reuse
the existing `legacyAttempt()` normalization and append-only structure. Treat
the latest attempt's status/result as authoritative; collect prior failure
codes for supersession without rewriting any attempt.

- [ ] **Step 4: Run the reducer tests to verify green**

Run the same PHPUnit command. Expected: all `CapturePhaseReceiptReducerTest`
tests pass with no new failures.

- [ ] **Step 5: Commit the reducer slice**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/CapturePhaseReceiptReducer.php public/wp-content/plugins/nhk-core/tests/Unit/CapturePhaseReceiptReducerTest.php
git commit -m "fix: centralize capture phase current outcomes"
```

### Task 2: Reconcile current diagnostics and retry blocker derivation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`

**Interfaces:**
- Consumes Task 1 receipt helpers plus persisted `diagnostics.completion`,
  `diagnostics.failure`, `failure_history` and current phase receipts.
- Produces `currentBlockers()` and `reconcileDiagnostics()` while preserving
  existing `retryEligibility()` and `failureCode()` public behavior.

- [ ] **Step 1: Write the failing current-state matrix tests**

Add tests named:

- `test_failed_retryable_x_then_success_makes_x_historical_only`
- `test_failed_retryable_x_then_x_keeps_x_current`
- `test_failed_retryable_x_then_y_uses_y_current_and_preserves_x_history`
- `test_later_successful_phase_does_not_resurrect_superseded_failure`
- `test_current_owner_review_remains_blocking_after_historical_failure_is_superseded`
- `test_current_system_blocked_failure_remains_authoritative`

Use generic codes such as `ERROR_X`, `ERROR_Y`, `OWNER_REVIEW_REQUIRED` and
`SYSTEM_BLOCKED`; do not use the UTF-8 or Odo fixture in these lifecycle tests.
Assert both `failureCode()`/`currentBlockers()` and the preserved
`failure_history`/receipt attempts.

- [ ] **Step 2: Run the matrix tests and verify red**

Run:

```bash
vendor/bin/phpunit --filter CaptureCurrentOutcomeReducerTest public/wp-content/plugins/nhk-core/tests/Unit
```

Expected: the new cases fail because stale completion/failure diagnostics are
currently eligible to win current-state decisions.

- [ ] **Step 3: Implement current-state reconciliation**

Implement `currentBlockers()` and `reconcileDiagnostics()` using the receipt
helpers. Move only proven superseded top-level failures into append-only
`failure_history`; filter only codes proven superseded and not currently
reported by an effective failed/blocked phase. Preserve owner-review and
system-blocked blockers, and keep retry eligibility fail-closed for current
non-retryable states.

- [ ] **Step 4: Run the matrix tests and the existing Capture reducer tests**

Run:

```bash
vendor/bin/phpunit --filter 'CaptureCurrentOutcomeReducerTest|CapturePhaseReceiptReducerTest' public/wp-content/plugins/nhk-core/tests/Unit
```

Expected: all selected tests pass, including the pre-existing review,
category, hard-block and dependency-fingerprint cases.

- [ ] **Step 5: Commit the current-outcome slice**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php
git commit -m "fix: separate current and historical capture failures"
```

### Task 3: Integrate reconciliation into coordinator receipt writes

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:**
- Consumes Task 2 `reconcileDiagnostics()` and the Task 1 receipt helpers.
- Produces Capture records whose newly appended phase receipt receives only a
  current failure code, while historical diagnostics remain auditable.
- Extend the private coordinator `save()` path with an explicit nullable
  current-failure-code input (defaulting to `null`) so persisted diagnostics
  cannot implicitly become the next receipt's failure code.

- [ ] **Step 1: Write the failing coordinator regression test**

Add a test named `test_retry_success_then_article_pre_create_review_does_not_reuse_historical_failure`.
Construct a Capture with an initial retryable phase failure, then exercise the
same-Capture retry path through successful interpretation/semantic phases and a
current Article pre-create review. Assert:

- the old failure remains in `failure_history` and the original receipt
  attempt;
- `ARTICLE_PRE_CREATE_REVIEW` has no inherited old `failure_code`;
- current blockers contain only the actual current review/media/quality state;
- no second Capture or Article is created.

- [ ] **Step 2: Run the coordinator regression and verify red**

Run:

```bash
vendor/bin/phpunit --filter EditorialCaptureConvergenceE2ETest public/wp-content/plugins/nhk-core/tests/Unit
```

Expected: it fails because
`save()` currently copies the persisted top-level failure into the later review
receipt and completion state.

- [ ] **Step 3: Implement the minimal coordinator integration**

In `save()`, determine the failure code for the current operation before
appending the attempt; do not use a persisted top-level failure as an implicit
current code. Append the receipt, then call
`CaptureCurrentOutcomeReducer::reconcileDiagnostics()` before persistence.
Preserve explicit current failure codes produced by the current catch/owner
operation and leave current Article pre-create review gates unchanged. Remove
or narrow `settleHistoricalFailure()` only where the new central reconciliation
supersedes its behavior; do not create a second history mechanism.

- [ ] **Step 4: Run coordinator and focused Capture/Article tests**

Run:

```bash
vendor/bin/phpunit --filter 'EditorialCaptureContinuationTest|EditorialCaptureConvergenceE2ETest|CaptureArticlePreflightHandoffTest|CaptureCurrentOutcomeReducerTest|CapturePhaseReceiptReducerTest' public/wp-content/plugins/nhk-core/tests/Unit
```

Expected: all selected tests pass, including existing subject binding,
governance continuation, Article preflight and UTF-8-related retry cases.

- [ ] **Step 5: Commit the coordinator slice**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
git commit -m "fix: reconcile capture failures after retry"
```

### Task 4: Align MCP current projection and retry replay contracts

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpCaptureReadContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php`

**Interfaces:**
- Consumes Task 2 effective blockers and the existing continuation service.
- Produces MCP `capture.get` and retry responses that agree on current
  blockers/eligibility without granting mutation authority.

- [ ] **Step 1: Write failing MCP and idempotency tests**

Add tests named:

- `test_capture_read_hides_superseded_failure_from_current_blockers`
- `test_capture_retry_replay_keeps_same_capture_and_canonical_outputs`
- `test_capture_retry_rejects_changed_request_fingerprint`

Assert that the read projection preserves audit history but omits the
superseded code from current blockers, that identical retry identity returns
the same Capture/outputs without duplicate Article or semantic owners, and
that changed payload/fingerprint remains rejected.

- [ ] **Step 2: Run the MCP/continuation tests and verify red**

Run:

```bash
vendor/bin/phpunit --filter 'McpCaptureReadContractTest|EditorialCaptureContinuationTest' public/wp-content/plugins/nhk-core/tests/Unit
```

Expected: the new stale-projection or replay assertions fail before the MCP
projection consumes the shared effective outcome.

- [ ] **Step 3: Implement the projection alignment**

Use `CaptureCurrentOutcomeReducer::currentBlockers()` for current blocker
output, while leaving bounded audit/history data available through the existing
Capture result shape. Keep transport validation, Capture binding, docs
checkpoint, Governance and CAS behavior unchanged. Do not make the read
projection an executor or add a bypass path.

- [ ] **Step 4: Run the MCP/continuation tests to verify green**

Run the same PHPUnit command. Expected: all selected tests pass.

- [ ] **Step 5: Commit the MCP slice**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php public/wp-content/plugins/nhk-core/tests/Unit/McpCaptureReadContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php
git commit -m "fix: align capture MCP retry projection"
```

### Task 5: Full verification and execution-state checkpoint

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] **Step 1: Run the focused lifecycle suite**

```bash
vendor/bin/phpunit --filter 'CapturePhaseReceiptReducerTest|CaptureCurrentOutcomeReducerTest|CaptureArticlePreflightHandoffTest|EditorialCaptureContinuationTest|EditorialCaptureConvergenceE2ETest|McpCaptureReadContractTest' public/wp-content/plugins/nhk-core/tests/Unit
```

Expected: all selected tests pass; report any pre-existing deprecations or
environment-gated failures separately.

- [ ] **Step 2: Run PHP lint for changed PHP files**

```bash
find public/wp-content/plugins/nhk-core/src/Application/Capture public/wp-content/plugins/nhk-core/src/Application/Mcp public/wp-content/plugins/nhk-core/tests/Unit -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports no syntax errors.

- [ ] **Step 3: Run repository checks and secret review**

Run:

```bash
git diff --check
git diff --cached --check
git status --short
rg -n --hidden --glob '!.git/**' '(sk-[A-Za-z0-9]{20,}|-----BEGIN (RSA|EC|OPENSSH|PRIVATE) KEY-----|password\s*[:=])' public/wp-content/plugins/nhk-core/src public/wp-content/plugins/nhk-core/tests docs/superpowers/specs docs/superpowers/plans
```

Expected: no whitespace errors and no new secrets. Existing unrelated dirty
files must remain visible and uncommitted unless they were already user-owned.

- [ ] **Step 4: Run the configured broader suite as feasible**

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist
```

Use the repository’s documented memory/runtime constraints if needed. Do not
hide failures; record environment-gated or pre-existing failures explicitly.

- [ ] **Step 5: Update the execution state ledger**

Append a dated 2026-10-06 local/no-data-mutation checkpoint recording the
root-cause fix, focused test counts, lint/diff/secret-review results and any
broader-suite limitations. Do not claim deployment, staging acceptance or
production change.

- [ ] **Step 6: Commit the verification checkpoint**

```bash
git add docs/architecture/V3_EXECUTION_STATE.md
git commit -m "docs: record capture retry lifecycle verification"
```
