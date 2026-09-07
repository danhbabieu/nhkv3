# Governance Automation Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a typed, capability-gated Governance Automation Policy that configures REVIEW_REQUIRED, AUTO_APPROVE, or AUTO_PUBLISH for registered ingestible types while preserving the existing governed mutation chain.

**Architecture:** A resolver reads a single sanitized WordPress option through a storage port and resolves only registered ingestible types, defaulting to REVIEW_REQUIRED. The existing governed orchestration seam consumes that resolver and returns truthful stopped, ready-to-apply, published, or blocked results; MCP and Admin are adapters over the same application services.

**Tech Stack:** PHP 8.x, WordPress plugin APIs, PHPUnit, existing NHK V3 Governance services/repositories/audit sink, existing MCP transport, existing Admin Workbench.

**Spec:** `docs/superpowers/specs/2026-09-07-governance-automation-policy-design.md`

## Global Constraints

- Human review is configurable; Governance gates are not.
- All semantic mutation remains `Proposal → Submit → Review/Approve → Eligibility → Controlled Apply → canonical read-back → projection/publication`.
- Default every missing policy entry to `REVIEW_REQUIRED`.
- Do not invent entity types, operations, predicates, relations, actor models, writers, migrations, or publication authorities.
- Do not write semantic records directly from Admin, MCP, or policy storage.
- AUTO_PUBLISH must not report success before controlled apply, canonical read-back, projection, and frontend verification pass.
- Private Source/Evidence remains private; Apply PASS is not Frontend PASS.
- No production, staging, V2, or shared semantic data mutation for acceptance.
- Preserve all pre-existing worktree changes unrelated to this feature.

## File map

- Create `public/wp-content/plugins/nhk-core/src/Domain/Governance/AutomationMode.php`: the three-value policy enum/value object.
- Create `public/wp-content/plugins/nhk-core/src/Contracts/Governance/AutomationPolicyStorage.php`: typed read/write storage port.
- Create `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernanceAutomationPolicyResolver.php`: registry-backed defaulting and mode resolution.
- Create `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/WpOptionAutomationPolicyStorage.php`: sanitized WordPress option adapter.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedSemanticIngestOrchestrator.php`: policy-aware truthful pipeline results and system audit context.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpGovernanceHandler.php` and `McpTransport.php`: shared policy-aware ingest entry point and response mapping.
- Modify `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchPage.php`, `AdminWorkbenchRegistry.php`, and `Plugin.php`: system page, save/read boundary, and dependency wiring.
- Create focused tests under `public/wp-content/plugins/nhk-core/tests/Unit/` for mode/resolver/storage/orchestration and under `tests/Unit/Admin/` for the Admin boundary.
- Modify the current Governance/Admin/MCP contract documentation and `docs/architecture/V3_EXECUTION_STATE.md` only after implementation verification.

### Task 1: Establish policy vocabulary and resolver

**Files:**
- Create the three domain/application/contract files in the file map.
- Test `tests/Unit/GovernanceAutomationPolicyTest.php`.

**Interfaces:**
- `AutomationMode::values(): array` returns the three exact strings.
- `AutomationMode::from(string $value): self` rejects every other value.
- `AutomationPolicyStorage::read(): array` and `write(array $policies): void`.
- `GovernanceAutomationPolicyResolver::__construct(object $registry, AutomationPolicyStorage $storage)`.
- `resolve(string $type): AutomationMode` and `all(): array`.

- [ ] Write tests proving all three enum values, invalid mode rejection, missing type default, unknown type rejection, and independent Video/Media/Content/Knowledge values.
- [ ] Run `vendor/bin/phpunit tests/Unit/GovernanceAutomationPolicyTest.php --testdox`; verify RED because the new classes do not exist.
- [ ] Implement the enum/value object, storage interface, resolver, and an in-memory test storage without WordPress globals.
- [ ] Run the focused test again; verify all resolver assertions pass.
- [ ] Run `git diff --check` and commit `feat: add governance automation policy resolver`.

### Task 2: Add the WordPress option adapter

**Files:**
- Create `src/Infrastructure/Governance/WpOptionAutomationPolicyStorage.php`.
- Test `tests/Unit/WpOptionAutomationPolicyStorageTest.php`.

**Interfaces:**
- `WpOptionAutomationPolicyStorage::__construct(object $registry, string $optionName = 'nhk_governance_automation_policy')`.
- `read(): array` returns only registered type keys and valid `AutomationMode` values.
- `write(array $policies): void` validates the complete submitted map before `update_option`.

- [ ] Write tests with stubbed `get_option`/`update_option` behavior proving absent option reads as empty, invalid mode/type is rejected, valid values round-trip, and one type cannot overwrite another.
- [ ] Run the focused storage test and verify the expected RED failure.
- [ ] Implement the adapter using WordPress option APIs, no migration, and no direct semantic repository calls.
- [ ] Run the focused storage test and verify GREEN.
- [ ] Run PHP lint on the new adapter and commit `feat: persist governance automation policy in wp options`.

### Task 3: Refactor the common governed orchestration seam

**Files:**
- Modify `src/Application/Governance/GovernedSemanticIngestOrchestrator.php`.
- Test `tests/Unit/GovernedSemanticIngestOrchestratorTest.php` and create `tests/Unit/GovernanceAutomationOrchestratorTest.php` if the existing fixture cannot express the new result contract.

**Interfaces:**
- Constructor receives a `GovernanceAutomationPolicyResolver`, existing `GovernedLifecycle`, an apply callable, and an optional projection/publication verifier callable.
- `run(array $nodes): array` returns per-node structured results with `status`, `mode`, `gate_reached`, `proposal_id`, `proposal_state`, `blockers`, and optional read-back/projection/frontend fields.
- System approval/apply/publication audit context uses `actor_kind=system`; human actor values remain unchanged.

- [ ] Add RED tests for REVIEW_REQUIRED stopping after submit/review, AUTO_APPROVE stopping after eligibility without apply, AUTO_PUBLISH executing apply/read-back/projection in order, and each gate returning a blocker without false success.
- [ ] Run the focused orchestration tests and verify RED for the new modes/results while confirming the legacy test still documents the old behavior to be updated deliberately.
- [ ] Implement the smallest policy-aware branch around the existing lifecycle; preserve dependency verification and existing callback contracts where possible.
- [ ] Add audit calls through the existing `GovernanceAuditSink` port or the existing injected audit callback; never write audit tables directly from the orchestrator.
- [ ] Run both orchestration test files and verify GREEN, then run the existing Governance unit subset.
- [ ] Commit `feat: make governed ingest orchestration policy-aware`.

### Task 4: Wire MCP ingest through the shared orchestration boundary

**Files:**
- Modify `src/Application/Mcp/McpGovernanceHandler.php`, `src/Application/Mcp/McpTransport.php`, and `src/Plugin.php`.
- Test `tests/Unit/McpGovernanceAutomationTest.php` and relevant existing MCP contract tests.

**Interfaces:**
- The existing `nhk.media.ingest`, `nhk.video.ingest`, `nhk.knowledge.ingest`, `nhk.source.ingest`, and `nhk.evidence.ingest` handlers resolve the type policy through one injected application service.
- MCP structured responses use the orchestration result without translating blocked outcomes to success.

- [ ] Write RED tests proving the same handler path returns submitted/manual, approved/ready-to-apply, published/frontend-available, and blocked results for the three modes.
- [ ] Run the focused MCP tests and verify RED.
- [ ] Wire one resolver/orchestrator instance in `Plugin` and route supported governed ingest calls through it; retain endpoint-specific validation and owner services.
- [ ] Ensure policy failures are represented in `structuredContent` and text content with the exact blocker, while transport-level permission errors remain unchanged.
- [ ] Run focused MCP and existing `McpContractTest.php`/`McpReadContractTest.php`; verify GREEN.
- [ ] Commit `feat: apply automation policy to mcp ingest`.

### Task 5: Add the Admin configuration surface

**Files:**
- Modify `src/Infrastructure/Admin/AdminWorkbenchPage.php`, `AdminWorkbenchRegistry.php`, and `Plugin.php`.
- Create `tests/Unit/Admin/GovernanceAutomationAdminTest.php`.

**Interfaces:**
- Admin read view model exposes registered ingestible rows with `type`, Vietnamese `label`, `mode`, and `description`.
- Save handler accepts the complete type-to-mode map, checks `manage_options` (or the current narrower settings capability), verifies `check_admin_referer`, and delegates to `WpOptionAutomationPolicyStorage`.

- [ ] Write RED tests for capability denial, nonce denial, invalid mode/type rejection, three mode labels, AUTO_PUBLISH warning, default REVIEW_REQUIRED rendering, and success/error notices.
- [ ] Run the focused Admin test and verify RED.
- [ ] Implement the page under Hệ thống with Vietnamese-first table/select controls and no UUID/JSON/technical inputs.
- [ ] Register the save action and ensure all values are validated server-side before one option update.
- [ ] Run the focused Admin tests and existing `AdminWorkbenchArchitectureTest.php`, `AdminWorkbenchRegistryTest.php`, and `UnifiedWorkbenchTest.php`; verify GREEN.
- [ ] Commit `feat: add admin governance automation settings`.

### Task 6: Documentation and regression quality gates

**Files:**
- Modify only the relevant current Governance/Admin/MCP contract docs and `docs/architecture/V3_EXECUTION_STATE.md`.
- Do not modify unrelated dirty files or rewrite historical checkpoints.

- [ ] Add a concise invariant statement that automation is not a Governance bypass and label the current implementation/runtime acceptance accurately.
- [ ] Run focused Governance/Admin/MCP tests.
- [ ] Run the full Unit suite with the repository's configured PHPUnit command.
- [ ] Run guarded Integration only if the exact `nhk_v3_test` environment is available; report environment blockers without mutating shared data.
- [ ] Run PHP lint for changed PHP files, JavaScript syntax checks for changed JS, `composer validate --no-check-publish`, `git diff --check`, and the repository secret review command if available.
- [ ] Read `docs/architecture/V3_EXECUTION_STATE.md` before updating it; record test counts, known pre-existing failures, and runtime acceptance status.
- [ ] Review `git diff`, confirm no credentials/private keys/env files or unrelated changes were added, and commit `docs: record governance automation policy implementation`.

## Final verification checklist

- [ ] Policy resolver defaults every absent registered type to REVIEW_REQUIRED.
- [ ] Admin read/write is capability- and nonce-protected with only three valid modes.
- [ ] REVIEW_REQUIRED never auto-approves or applies.
- [ ] AUTO_APPROVE approves only after validation/review and eligibility, then stops before apply.
- [ ] AUTO_PUBLISH proves apply, canonical read-back, projection, and frontend availability before success.
- [ ] Blockers preserve state and are returned truthfully.
- [ ] Automated actions are auditable and distinct from human actions.
- [ ] Replay is idempotent and changed bindings conflict.
- [ ] Governance regression suite remains green apart from explicitly recorded pre-existing failures.
- [ ] No push was performed.
