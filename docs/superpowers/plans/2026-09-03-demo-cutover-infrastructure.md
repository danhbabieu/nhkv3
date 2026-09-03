# DEMO Cutover Infrastructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement one human-controlled, generic command that prepares and gates a DEMO semantic cutover without embedding pack business logic or executing real DEMO mutation in this task.

**Architecture:** A thin POSIX shell delegates to a typed PHP `DemoCutoverRunner`. The runner coordinates injected stage ports for safety, deterministic deployment, authenticated runtime checks, read/inventory/planning, Governance and evidence; every stage stops on non-pass and only an interactive approval can reach Controlled Apply.

**Tech Stack:** PHP 8.3+, Symfony UID already in the repository, PHPUnit 11, WordPress plugin PSR-4 runtime, POSIX shell, JSON Lines evidence.

**Spec:** `docs/superpowers/specs/2026-09-03-demo-cutover-infrastructure-design.md`

## Global Constraints

- Target allowlist contains only `demo.1945.vn`; unknown targets fail closed.
- `--pack` is a validated generic manifest identifier; runner contains no Odo rules or semantic records.
- No real DEMO deployment/cutover, V2/staging/production mutation, direct SQL, local semantic DB, hard delete or auto-approval.
- Semantic writes remain Proposal → Human Approval → Eligibility → Controlled Apply → read-back → evidence.
- Live UUID/stable-key/revision bindings and idempotency fingerprints are mandatory for planning.
- Credentials never appear in CLI arguments, logs or evidence.
- Existing user changes are preserved; execution state is updated after checkpoints.

## File Map

- Create `public/wp-content/plugins/nhk-core/src/Application/Demo/DemoCutoverRunner.php`: stage sequencing and fail-closed state machine.
- Create `public/wp-content/plugins/nhk-core/src/Application/Demo/DemoCutoverTypes.php`: immutable context, stage result, proposal packet and evidence value objects.
- Create `public/wp-content/plugins/nhk-core/src/Contracts/Demo/DemoCutoverPorts.php`: generic ports for all external/domain actions.
- Create `public/wp-content/plugins/nhk-core/src/Infrastructure/Demo/LocalCutoverAdapters.php`: safe repository-local adapters for source revision, manifest and runtime seams; no network mutation.
- Create `tools/nhk-demo-cutover.php`: argument parsing and dependency composition for the command.
- Create `scripts/nhk-demo-cutover`: thin executable wrapper.
- Create `public/wp-content/plugins/nhk-core/tests/Unit/DemoCutoverRunnerTest.php` and `DemoCutoverTypesTest.php`: unit/contract coverage.
- Create `public/wp-content/plugins/nhk-core/tests/Unit/DemoCutoverCliContractTest.php`: shell contract assertions.
- Modify `docs/architecture/V3_EXECUTION_STATE.md`: checkpoint evidence after implementation.

### Task 1: Define typed cutover value objects and ports

**Files:** Create the files listed for `DemoCutoverTypes.php` and `DemoCutoverPorts.php`; test `DemoCutoverTypesTest.php`.

**Interfaces:** `DemoCutoverContext`, `StageResult`, `CutoverEvidence`, `ProposalPacket`, and ports with `run(Context): StageResult` or their typed equivalent. Safe identifiers are strings; secrets are never accepted.

- [ ] Write a failing test for exact target/pack validation and stable status/reason codes.
- [ ] Run `vendor/bin/phpunit --filter DemoCutoverTypesTest`; confirm missing-class failure.
- [ ] Implement immutable enums/value objects with strict validation and redacted evidence serialization.
- [ ] Run the focused test and confirm pass.
- [ ] Add tests proving proposal packets require live revision bindings and an idempotency fingerprint.
- [ ] Run focused tests; implement only the missing invariant.
- [ ] Commit `feat: add typed demo cutover contracts`.

### Task 2: Implement fail-closed runner sequencing

**Files:** Create `DemoCutoverRunner.php`; test `DemoCutoverRunnerTest.php` with in-memory test ports.

**Interfaces:** `DemoCutoverRunner::prepare(DemoCutoverContext): CutoverRunResult`; `DemoCutoverRunner::apply(ApprovalToken): CutoverRunResult`. The runner calls safety → deploy → verify → preflight → Graph → editorial snapshot → inventory → plan → submit → approval, then eligibility → apply → read-back → evidence only after approval.

- [ ] Write a failing test proving stage order and that a blocked stage prevents all later ports.
- [ ] Run the focused test and confirm the expected missing-runner failure.
- [ ] Implement the minimal stage pipeline and first-failure result.
- [ ] Run the focused test and confirm pass.
- [ ] Write a failing test proving no apply call occurs without an interactive approval token matching the proposal fingerprint.
- [ ] Implement the approval boundary and exact-fingerprint check.
- [ ] Add tests for stale live revision, eligibility block, read-back mismatch and uncertain apply; each must preserve its distinct reason code.
- [ ] Implement the minimum fail-closed branches and run the focused suite.
- [ ] Add idempotent evidence test for repeated same run/input fingerprint.
- [ ] Commit `feat: add fail-closed demo cutover orchestration`.

### Task 3: Add safe local adapters and production wiring seams

**Files:** Create `LocalCutoverAdapters.php` and `tools/nhk-demo-cutover.php`.

**Interfaces:** Local adapters validate repo root, `composer.lock`, plugin artifact, manifest path and source revision. Remote/domain ports are explicit unavailable adapters unless configured; they never silently simulate success.

- [ ] Write a failing test for missing manifest, non-allowlisted target and unavailable authenticated runtime.
- [ ] Run the focused tests and confirm failures.
- [x] Implement repository checks, safe manifest loading, and blocked results with stable codes.
- [ ] Run focused tests and confirm pass.
- [ ] Write a failing test that serialized evidence redacts credential-shaped keys and values.
- [ ] Implement redaction before persistence/output.
- [x] Compose the runner in `tools/nhk-demo-cutover.php`; default to prepare-only and require TTY/explicit approval before apply.
- [ ] Run `php tools/nhk-demo-cutover.php --help` and the no-credential dry preparation path; verify it blocks rather than claims remote success.
- [ ] Commit `feat: wire safe demo cutover adapters`.

### Task 4: Add the thin human-controlled command

**Files:** Create executable `scripts/nhk-demo-cutover`; test `DemoCutoverCliContractTest.php`.

**Interfaces:** Supported options are `--target=<host>`, `--pack=<id>`, `--root=<path>`, `--json`; unknown/missing options exit non-zero. The wrapper delegates to one PHP command and preserves its exit code.

- [ ] Write a failing test asserting the wrapper exists, is executable, contains no Odo business terms and delegates to the PHP command.
- [ ] Run the focused test and confirm failure.
- [ ] Add the minimal shell wrapper using `exec` and safe path resolution.
- [ ] Run the focused test and confirm pass.
- [ ] Add argument contract tests for the requested invocation and invalid target.
- [x] Run `./scripts/nhk-demo-cutover --target=demo.1945.vn --pack=odo`; verify it stops with `REMOTE_DEPLOYMENT_CONFIG_REQUIRED` and does not mutate data.
- [ ] Commit `feat: add human-controlled demo cutover command`.

### Task 5: Verification and checkpoint

**Files:** Modify `docs/architecture/V3_EXECUTION_STATE.md`; no production/data files.

- [ ] Run focused Demo tests and record counts.
- [ ] Run the full `composer test` suite and preserve/describe any pre-existing WordPress bootstrap errors.
- [ ] Run `composer validate --no-check-publish` and `composer lint` (or the repository-equivalent `composer run-script lint`).
- [ ] Run `git diff --check`.
- [ ] Run secret review over changed files for credentials/private keys/tokens; expected no findings.
- [ ] Re-read this plan and the spec, verify every requirement has an implementation/test or is explicitly out of scope.
- [ ] Update execution state with exact verification evidence and the real command behavior.
- [ ] Commit `test: verify demo cutover infrastructure`.

## Plan self-review

- Spec coverage: target allowlist, thin shell, generic pack, deterministic deployment seam, authenticated runtime gate, all read/planning/Governance stages, approval, read-back, evidence, fail-closed behavior, redaction, tests and no-live-cutover are covered by Tasks 1–5.
- Placeholder scan: no `TBD`, `TODO`, or unspecified implementation step is required; unavailable live adapters are an explicit tested state.
- Type consistency: runner consumes `DemoCutoverContext`, emits `CutoverRunResult`, and approval/apply uses the proposal fingerprint from `ProposalPacket`; ports are defined before runner use.
- Scope: one infrastructure subsystem with independently testable contract, runner, wiring, shell and verification slices.
