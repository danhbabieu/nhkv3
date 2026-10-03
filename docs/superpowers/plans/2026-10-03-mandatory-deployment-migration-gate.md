# Mandatory Deployment Migration Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the canonical deploy verifier fail closed unless the exact deployed source revision has completed remote `migration-up` and the Migration024 schema is read back as ready.

**Architecture:** Reuse `RemoteDeploymentAdapter` for file transfer and `RemoteRuntimeAdapter` for the existing SSH maintenance transport. The verifier will run migration-up immediately after transfer and before MCP runtime verification; the runtime adapter will validate the returned release tuple and Migration024 receipt, while deployment configuration supplies non-secret migration authorization env values to the remote process.

**Tech Stack:** PHP 8.x, PHPUnit 11, WordPress maintenance CLI, SSH/rsync.

**Spec:** User request pasted in `/Users/imac24-2125d/.codex/attachments/c0b2d717-d0ee-43a6-9b60-23e1da564ac1/Văn bản đã dán.txt`.

## Global Constraints

- Migration is explicit deploy/maintenance lifecycle only; frontend requests remain migration-free.
- `MigrationDatabaseGuard` remains authoritative; no raw SQL or direct database writes are added to deployment code.
- Migration024 requires all three tables: `dictionary_entries`, `dictionary_forms`, and `dictionary_entry_senses`.
- Missing/stale authorization or source binding fails closed.
- No real deployment is run in this task.

## Review Focus

- Remote receipt has stale or mismatched `source_revision`, `pack`, or `run_id` → reject before runtime verification.
- Migration receipt reports current/target below 24 → `MIGRATION_TARGET_NOT_REACHED`.
- Migration024 readiness is false → `DICTIONARY_ENTRY_SENSE_SCHEMA_NOT_READY`.
- Deployment config lacks migration authorization → block before remote migration-up.
- Migration-up transport fails or returns malformed JSON → preserve a machine-readable failure and never report release pass.

---

### Task 1: Lock the remote migration contract with failing tests

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/RemoteRuntimeAdapterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/RemoteDeploymentAdapterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/NhkDeployVerifyCliContractTest.php`

- [x] Add tests for exact context binding, migration target/readiness gates, and required authorization config.
- [x] Run the focused tests and verify they fail for the current implementation.

### Task 2: Implement fail-closed remote migration execution

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Demo/StageResult.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Demo/RemoteRuntimeAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Demo/RemoteDeploymentAdapter.php`

- [x] Add bounded receipt metadata to `StageResult`.
- [x] Load migration authorization fields from the existing deploy config and inject them only into the explicit maintenance command.
- [x] Validate exact context tuple and Migration024 receipt before returning pass.

### Task 3: Wire the release sequence into the verifier

**Files:**
- Modify: `tools/nhk-deploy-verify.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/NhkDeployVerifyCliContractTest.php`

- [x] Instantiate the existing runtime adapter after a successful transfer.
- [x] Run `migration-up`; stop with its reason code on any non-pass result.
- [x] Run MCP/application verification only after migration-up passes and include the migration receipt in JSON output.

### Task 4: Update operator contracts and checkpoint evidence

**Files:**
- Modify: `docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md`
- Modify: `public/wp-content/plugins/nhk-core/migrations/README.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [x] Document the mandatory release order, config keys, and failure codes without secrets or real deployment claims.
- [x] Record the implementation checkpoint and remaining live deployment verification as pending.

### Task 5: Verify the complete slice

- [x] Run focused PHPUnit tests.
- [x] Run PHP lint, `git diff --check`, and the relevant full test suite.
- [x] Perform a secret review and inspect the final diff against Constitution constraints.
