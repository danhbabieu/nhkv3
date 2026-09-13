# Operational Runtime Provisioning Package Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a repository-consistent handoff package that lets an infrastructure owner provision and verify a persistent, non-staging NHK V3 operational runtime without semantic mutation.

**Architecture:** The package documents the existing WordPress environment contract, the existing UP-only migration and governed semantic boundaries, and the existing Demo/recovery separation. A new CLI is strictly read-only: it bootstraps the target WordPress runtime, reads existing health/migration/documentation/read-surface boundaries, emits a redacted identity packet, and fails closed for development, test, staging, or recovery-only targets. It does not provision infrastructure, create data, call Capture, or call any writer.

**Tech Stack:** PHP 8+, WordPress bootstrap, existing NHK Core `MigrationStatus`, `McpDocumentationRegistry`, REST route registry, PHPUnit 11, Composer documentation generator.

**Spec:** `docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md`, `docs/architecture/V3_SNAPSHOT_RECOVERY_RUNTIME.md`, `docs/architecture/ISOLATED_VIDEO_RECOVERY_RUNTIME_2026-09-12.md`, and the approved operational-runtime handoff request dated 2026-09-13.

## Global Constraints

- Do not change Clock Type architecture, frontend behavior, ontology, PR7, backfill, or semantic data.
- Do not mutate `https://demo.1945.vn`, `nhk_v3_test`, production, or the recovery runtime.
- Do not create a database, copy rows, restore snapshots, run migrations, or invoke a writer from this package.
- Preserve the existing `RemoteDeploymentAdapter`; it remains Demo-only artifact transport and is not repurposed.
- Use `family=clock_type` only in future governed Clock Type workflows; this package contains no Clock Type operation.
- Never emit passwords, DSNs, salts, tokens, private keys, or raw secret configuration.
- Keep test, recovery, staging and operational runtime identities distinct and fail closed on ambiguity.

---

### Task 1: Write the provisioning runbook

**Files:**
- Create: `docs/architecture/OPERATIONAL_RUNTIME_PROVISIONING_RUNBOOK.md`
- Read: `AGENTS.md`, `docs/constitution/READ_FIRST.md`, `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md`, `docs/architecture/V3_SNAPSHOT_RECOVERY_RUNTIME.md`, `docs/architecture/ISOLATED_VIDEO_RECOVERY_RUNTIME_2026-09-12.md`

**Interfaces:**
- Consumes: existing `DB_*`, `WP_*`, `NHK_RUNTIME_*`, migration, documentation and MCP boundaries.
- Produces: the infrastructure handoff contract, environment checklist, database/bootstrap sequence, MCP registration checklist, acceptance gates A–H, backup/recovery checklist and first-business-action handoff.

- [ ] **Step 1: Write the failing documentation contract test**

Add `public/wp-content/plugins/nhk-core/tests/Unit/OperationalRuntimeProvisioningDocumentationTest.php` with assertions that the runbook contains the exact status names `PERSISTENT_CANONICAL_RUNTIME_READY`, `CANONICAL_RUNTIME_NOT_PROVISIONED`, `CANONICAL_RUNTIME_EXISTS_CONNECTOR_NOT_BOUND`, and `CANONICAL_RUNTIME_WRITE_POLICY_BLOCKED`; the forbidden targets `nhk_v3_test`, `https://demo.1945.vn`, and recovery mode; the exact required MCP capability names; and the no-mutation rule.

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/OperationalRuntimeProvisioningDocumentationTest.php
```

Expected: FAIL because the runbook does not exist.

- [ ] **Step 3: Write the runbook**

The runbook must define:

1. Target requirements: dedicated persistent non-test datastore; WordPress/NHK Core; current migrations; Authority, Graph, Knowledge, Media, Video, Public Identity and Governance on one binding; read-after-write; recovery ownership; authenticated MCP connector.
2. `REQUIRED_PUBLIC_CONFIG`: existing non-secret `DB_NAME`, `DB_HOST` only where the infrastructure policy permits its display, `DB_PREFIX`, `WP_ENVIRONMENT_TYPE`, `WP_HOME`, `WP_SITEURL`, `NHK_RUNTIME_ENVIRONMENT`, `NHK_RUNTIME_MODE`.
3. `REQUIRED_SECRET_CONFIG`: existing `DB_USER`, `DB_PASSWORD`, WordPress salts and provider-managed MCP authentication material, stored outside Git and never printed.
4. `OPTIONAL_CONFIG`: existing `DB_CHARSET`, `DB_COLLATE`, `WP_DEBUG` (false for operational runtime), and `NHK_YOUTUBE_API_KEY` only when Video source capability is required.
5. `FORBIDDEN_TEST_CONFIG`: `NHK_WP_TEST_DB=nhk_v3_test`, `NHK_WP_TEST_PATH=public` for operational execution, `NHK_RUNTIME_MODE=recovery`, recovery allow-list variables, and any staging Demo binding.
6. External sequence: dedicated DB creation by infrastructure → secret-bound WordPress bootstrap → NHK Core activation → UP-only migrations → schema/read-surface verification → fresh docs bootstrap → Governance capability verification → dedicated connector registration → read-back.
7. Runtime identity proof: connector ID, site URL, deployment/build identity, runtime version, opaque `database_binding_id` or sanctioned equivalent, migration current/target, documentation version, manifest hash and write policy. The binding ID is deployment evidence, not a semantic field.
8. MCP checklist: endpoint/auth boundary, docs bootstrap, inventory/search, entity/Graph reads, Capture ingest discoverability, Proposal review, Approval, Eligibility, Controlled Apply and canonical read-back; runtime capability, connector exposure and write authorization must be recorded separately.
9. Acceptance gates A–H exactly as requested, with Gate E explicitly PLAN-only and no business Apply.
10. Backup/recovery: owner, cadence, encrypted location, restore drill, checksum/identity verification, rollback boundary and prohibition on using `nhk_v3_test` or Demo as operational targets.
11. First action handoff: Search/reuse → PLAN `Đồng hồ công cộng` → owner approval → governed Apply → read-back → separate Public Identity/Knowledge/Article/Media/Video workflows; document only, do not execute.

- [ ] **Step 4: Run the documentation test to verify it passes**

Run the same PHPUnit command and expect PASS with zero failures.

- [ ] **Step 5: Commit the runbook and test**

```bash
git add docs/architecture/OPERATIONAL_RUNTIME_PROVISIONING_RUNBOOK.md public/wp-content/plugins/nhk-core/tests/Unit/OperationalRuntimeProvisioningDocumentationTest.php
git commit -m "docs: add operational runtime provisioning handoff"
```

### Task 2: Add a read-only operational acceptance CLI

**Files:**
- Create: `tools/operational-runtime-acceptance.php`
- Modify: `composer.json` only if a non-mutating script alias is needed; otherwise do not modify it.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/OperationalRuntimeAcceptanceScriptTest.php`

**Interfaces:**
- Consumes: `--root=<WordPress checkout>`, `--expected-environment=<operational marker>`, `--expected-site=<canonical site>`, optional `--expected-database-binding-id=<opaque binding>`, optional `--expected-connector-id=<connector>`, and optional `--json`.
- Produces: JSON or line-oriented gate results with only redacted identity fields and exit code `0` only when all non-mutating gates pass; failure codes include `OPERATIONAL_TARGET_REQUIRED`, `OPERATIONAL_TARGET_FORBIDDEN`, `DATABASE_BINDING_UNAVAILABLE`, `MIGRATION_REQUIRED`, `CANONICAL_DOCUMENTATION_UNAVAILABLE`, `READ_SURFACE_UNAVAILABLE`, `CONNECTOR_ID_REQUIRED`, and `WRITE_POLICY_UNVERIFIED`.

- [ ] **Step 1: Write failing source contract tests**

Assert that the script contains no calls to `RemoteDeploymentAdapter`, `RemoteRuntimeAdapter::run`, `nhk.capture.ingest`, `wpdb->query` with mutation verbs, `update_option`, `insert`, `update`, `delete`, `truncate`, `drop`, `ControlledApply`, or migration execution. Assert that it rejects `nhk_v3_test`, `demo.1945.vn`, `staging`, `test`, and `recovery`, and that output names `database_binding_id` without outputting `DB_PASSWORD` or a DSN.

- [ ] **Step 2: Run the source tests to verify they fail**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/OperationalRuntimeAcceptanceScriptTest.php
```

Expected: FAIL because the script does not exist.

- [ ] **Step 3: Implement the minimal read-only CLI**

The script must:

1. Parse only the documented options and reject unknown options.
2. Require an explicit expected environment and site; reject Demo/staging/test/recovery before WordPress bootstrap.
3. Load the target's existing `public/wp-load.php` and NHK Core without editing options or database rows.
4. Build a redacted identity packet from existing runtime constants, `home_url('/')`, `SELECT DATABASE()` read-back only, plugin version, `McpDocumentationRegistry::bootstrap()`, and `MigrationStatus`.
5. Derive an opaque binding ID only from non-secret runtime identity inputs; never print DB credentials, DSN, salts or tokens. If an expected binding ID is supplied, compare it and fail closed on mismatch.
6. Verify current migrations and existing storage readiness for Authority, Graph, Governance, Knowledge, Media, Video, Article and Public Identity read boundaries. Use existing `MigrationStatus` methods and registered REST routes/classes; do not invent or call a mutation endpoint.
7. Report MCP capability discoverability from the existing executable catalog and, when an expected connector ID is supplied, record connector provenance as an external verification input. The script must not impersonate or register a connector.
8. Report write policy as `VERIFIED` only when an explicit non-staging operational policy input is provided by the infrastructure wrapper; otherwise return `WRITE_POLICY_UNVERIFIED` and `PERSISTENT_CANONICAL_RUNTIME_READY` must not be emitted.
9. Run no PLAN, Capture, Proposal, Approval, Eligibility or Apply call. Gate E is reported as `PLAN_ONLY_SURFACE_DISCOVERABLE`; the runbook defines the owner-run sanctioned PLAN smoke after infrastructure authorization.
10. Return a deterministic summary and exit non-zero on any unavailable/ambiguous gate. Empty collections remain distinct from unavailable storage.

- [ ] **Step 4: Run source tests and a forbidden-target smoke**

Run the PHPUnit command above and:

```bash
php tools/operational-runtime-acceptance.php --expected-environment=staging --expected-site=https://demo.1945.vn --json
```

Expected: PASS for the test suite and a non-zero `OPERATIONAL_TARGET_FORBIDDEN` result for Demo/staging, with no WordPress or database mutation.

- [ ] **Step 5: Commit the CLI and tests**

```bash
git add tools/operational-runtime-acceptance.php public/wp-content/plugins/nhk-core/tests/Unit/OperationalRuntimeAcceptanceScriptTest.php
git commit -m "feat: add read-only operational runtime acceptance"
```

### Task 3: Record execution checkpoint and regenerate canonical documentation

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Regenerate: `public/wp-content/plugins/nhk-core/resources/canonical-docs/` through the Composer generator only.

**Interfaces:**
- Consumes: the runbook, CLI contract, current target evidence and test results.
- Produces: a dated `PROVISIONING_HANDOFF_READY` or `PROVISIONING_HANDOFF_BLOCKED` checkpoint, with no claim that a runtime was provisioned.

- [ ] **Step 1: Add the checkpoint**

Record the current repository baseline, package files, exact no-mutation scope, current status `CANONICAL_RUNTIME_NOT_PROVISIONED`, and the infrastructure owner action required. Do not change historical claims or parity rows.

- [ ] **Step 2: Regenerate canonical docs**

```bash
composer generate:mcp-docs
```

Read the generated manifest and record its `documentation_version` and `manifest_hash` in the checkpoint.

- [ ] **Step 3: Verify documentation parity**

Run the documentation registry tests and verify a second generator run produces the same manifest hash. Do not deploy the snapshot.

- [ ] **Step 4: Commit the checkpoint**

```bash
git add docs/architecture/V3_EXECUTION_STATE.md
git commit -m "docs: checkpoint operational runtime handoff"
```

### Task 4: Final verification and handoff review

**Files:**
- Read-only verification of all changed files and current worktree.

**Interfaces:**
- Consumes: committed package and fresh local verification output.
- Produces: final handoff report with exact status and remaining infrastructure dependency.

- [ ] **Step 1: Run package tests**

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/OperationalRuntimeProvisioningDocumentationTest.php public/wp-content/plugins/nhk-core/tests/Unit/OperationalRuntimeAcceptanceScriptTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php
```

- [ ] **Step 2: Run lint and diff checks**

```bash
composer lint
git diff --check
```

- [ ] **Step 3: Run the CLI forbidden-target check**

```bash
php tools/operational-runtime-acceptance.php --expected-environment=staging --expected-site=https://demo.1945.vn --json
```

Verify the result is non-zero and contains no mutation receipt.

- [ ] **Step 4: Review secrets and working tree**

Inspect the diff for credentials, DSNs, tokens, private keys, generated semantic data and unrelated changes; run `git status --short --branch` and require a clean worktree after commit.

- [ ] **Step 5: Report the handoff**

Report runtime requirements, environment classes, database sequence, migration state, MCP checklist, identity proof, acceptance gates, backup/recovery, changed files, no semantic mutation, infrastructure owner action and one of `PROVISIONING_HANDOFF_READY` or `PROVISIONING_HANDOFF_BLOCKED`.

## Self-review

- Spec coverage: Tasks 1–2 cover requirements, environment categories, bootstrap runbook, identity proof, MCP registration checklist, gates A–H, backup/recovery and first-business-action handoff; Task 3 covers execution state and documentation parity; Task 4 covers verification.
- No new semantic owner, ontology term, Graph predicate, frontend component, route allocation, database provisioning adapter or writer is introduced.
- The CLI's gate E is deliberately discoverability-only; a real governed PLAN remains an infrastructure/owner operation and is not executed by this package.
- The existing recovery runtime is documented as recovery-only and is never promoted to operational use.
- The current staging connector remains read-only and is never used for provisioning or smoke mutation.
