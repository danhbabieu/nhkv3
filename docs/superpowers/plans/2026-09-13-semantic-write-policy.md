# Semantic Write Policy / Project Build Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fail-closed runtime semantic-write policy that enables owner-authorized canonical Project Build workflows without changing ontology, Governance ownership, or live deployment state.

**Architecture:** A small domain resolver converts deployment environment and `NHK_SEMANTIC_WRITE_POLICY` into a typed policy decision. MCP authenticates and resolves runtime identity before a shared semantic gate admits Authority Capture planning; existing Governance capabilities and lifecycle remain independent. MCP runtime identity and documentation bootstrap project the same resolved state.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress plugin runtime, existing MCP transport/catalog, Authority Capture/Planner, Governance audit sink, documentation registry.

**Spec:** `docs/superpowers/specs/2026-09-13-semantic-write-policy-design.md`

## Global Constraints

- Missing, malformed, or unsupported policy configuration resolves to `READ_ONLY`.
- Production always fails closed for `PROJECT_BUILD` with `PROJECT_BUILD_FORBIDDEN_IN_PRODUCTION`.
- Runtime policy is not inferred from a URL.
- `PROJECT_BUILD` requires `nhk_project_build_semantic`; this does not replace existing Governance, publication, upload, or internal capabilities.
- Authority vocabulary remains the registered nine types; Clock Type is `classification` with exact `family=clock_type`; `family=clock-type` is not a new write target.
- Project Build uses Search/Reuse and `nhk.capture.ingest` → PLAN → confirmation → Proposal → Approval → Eligibility → Controlled Apply → canonical read-back.
- Direct SQL, generic writers, direct repositories, Graph shortcuts, fake UUIDs, manual stable-key insertion, legacy backfill, PR7, and bulk Graph backfill are forbidden.
- Reuse the existing Governance audit owner; do not add an audit table.
- Do not modify the existing Media/Image worktree changes.
- Do not change deployment configuration or perform live mutation.

---

### Task 1: Add typed runtime semantic-write policy resolver

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Runtime/SemanticWritePolicy.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Runtime/SemanticWritePolicyResolver.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticWritePolicyTest.php`

**Interfaces:**
- Produces `SemanticWritePolicy::READ_ONLY`, `PROJECT_BUILD`, `LOCKED_OPERATIONAL`, `SemanticWritePolicyResolver::resolve()`, `environment()`, and `decision(bool $authenticated, callable $can)`: array decision packets.
- The resolver reads `NHK_SEMANTIC_WRITE_POLICY` from a supplied config reader, reads environment from a supplied environment reader, and never accepts URL input.

- [ ] **Step 1: Write failing tests** for missing/invalid config, all three values, production fail-closed, allowed non-production Project Build, capability absence, and read-only/locked behavior.
- [ ] **Step 2: Run the focused test** and confirm failure because the policy classes do not exist.
- [ ] **Step 3: Implement the enum-like value object and resolver** with safe defaults, exact error codes, allowed Project Build environments (`development`, `staging-build`, and explicitly enabled `staging`), and a production override.
- [ ] **Step 4: Run the focused test** and confirm all policy decisions pass.
- [ ] **Step 5: Commit only the new policy files and tests** with `feat: add runtime semantic write policy`.

### Task 2: Add Project Build capability and shared MCP semantic gate

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernanceCapabilities.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminShell.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/SingleEntryPointPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticWritePolicyMcpGateTest.php`

**Interfaces:**
- Consumes `SemanticWritePolicyResolver` from Task 1.
- Produces capability registration for `nhk_project_build_semantic` and a single semantic gate invoked after MCP authentication/capability resolution and before Authority Capture planning.

- [ ] **Step 1: Write failing transport tests** for READ_ONLY Brand/Model/Clock Type PLAN blocking, Project Build missing capability, Project Build allowed PLAN, production blocking, LOCKED convenience blocking, and direct-writer blocking.
- [ ] **Step 2: Run the focused MCP tests** and confirm the expected policy gate failures.
- [ ] **Step 3: Add the capability constant/registration** without changing existing proposal, apply, publication, upload, or internal capabilities.
- [ ] **Step 4: Inject the resolver into `McpTransport`** and call the gate only for canonical semantic Authority Capture planning/continuation; keep compatibility writers under `SingleEntryPointPolicy::guard` and existing capability checks.
- [ ] **Step 5: Run focused policy and MCP contract tests** and confirm the matrix passes.
- [ ] **Step 6: Commit the gate and capability changes** with `feat: gate canonical semantic project build path`.

### Task 3: Preserve generic Authority/Clock Type planning and fail-closed family validation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Authority/AuthorityIntentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Authority/CanonicalAuthorityStableKeyPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/AuthorityCaptureService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityIntentPlannerTest.php`

**Interfaces:**
- Consumes the policy gate from Task 2 at the Capture boundary.
- Produces generic candidate planning for all nine registered Authority types, exact `clock_type` family acceptance, legacy `clock-type` write rejection, reuse-first duplicate/ambiguity diagnostics, and the fixture stable-key preview.

- [ ] **Step 1: Add failing tests** for all nine types, `Đồng hồ công cộng` with `classification`/`clock_type`, legacy family rejection, duplicate reuse, and ambiguity review.
- [ ] **Step 2: Run those tests** and confirm the missing/incorrect behavior.
- [ ] **Step 3: Implement only generic registry-driven checks**; do not add a separate Clock Type mutation rule or client-supplied identity override.
- [ ] **Step 4: Run Authority/Capture/Clock Type focused tests** and confirm existing planner fingerprints remain deterministic.
- [ ] **Step 5: Commit with `feat: keep authority build planning registry driven`**.

### Task 4: Expose runtime identity and governed Project Build audit context

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDocumentationRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/AuthorityCaptureService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedAuthorityPlanExecutor.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyService.php`
- Create/extend: `public/wp-content/plugins/nhk-core/tests/Unit/RuntimeIdentityAndProjectBuildAuditTest.php`

**Interfaces:**
- Consumes the same `SemanticWritePolicyResolver` and existing documentation/build identity.
- Produces read-only runtime identity fields (`environment`, `semantic_write_policy`, `project_build_enabled`, build/runtime identity, `documentation_version`, `manifest_hash`) and Governance-owned audit context for Capture, Proposal, target UUID, entity type, operation, plan fingerprint, approval mode, revisions, and read-back.

- [ ] **Step 1: Write failing identity/audit tests** asserting identical policy resolution in `server/discover`, `initialize`, and documentation bootstrap, and asserting no parallel audit writer.
- [ ] **Step 2: Run the focused tests** and confirm missing identity/audit fields.
- [ ] **Step 3: Implement identity projection** using existing `McpDocumentationRegistry::buildIdentity()`/manifest values; do not add a database-binding identity because no sanctioned binding is currently exposed by the runtime.
- [ ] **Step 4: Thread bounded audit context** through existing Proposal/Apply audit calls without persisting secrets, body, tokens, or a new table.
- [ ] **Step 5: Run focused identity/audit and Governance tests** and confirm read-back fields are present.
- [ ] **Step 6: Commit with `feat: expose project build runtime identity and audit`**.

### Task 5: Update current contract, execution state, and verification evidence

**Files:**
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md` with a dated status note that this feature is code-side and does not claim parity or live deployment
- Modify/create: canonical documentation manifest source only through the existing snapshot generation command, never by hand

- [ ] **Step 1: Add the active policy contract entry** and document the exact config needed for the current `staging` deployment: `NHK_SEMANTIC_WRITE_POLICY=project_build`, with owner capability assignment and production safety caveat.
- [ ] **Step 2: Add a dated execution-state checkpoint** recording code-side implementation, test evidence, unchanged local config, preserved dirty Media/Image changes, and `NO LIVE MUTATION`.
- [ ] **Step 3: Generate the documentation snapshot using the repository command** and verify manifest parity; do not edit generated files manually.
- [ ] **Step 4: Run `git diff --check` and a secret review** over the changed scope.
- [ ] **Step 5: Commit documentation/checkpoint updates** with `docs: record semantic write policy project build mode`.

### Task 6: Run full verification and report deployment gate

**Files:**
- No production code changes expected; preserve all existing unrelated Media/Image modifications.

- [ ] **Step 1: Run focused policy tests.**
- [ ] **Step 2: Run Authority, Capture, Governance, Clock Type, MCP, Media, Video, and Knowledge tests.**
- [ ] **Step 3: Run the full Unit suite and Contract suite.**
- [ ] **Step 4: Run the relevant Integration tests; if `NHK_WP_TEST_PATH` or the guarded integration database is unavailable, record the exact environment skip and do not claim integration pass.**
- [ ] **Step 5: Run PHP lint, `git diff --check`, documentation snapshot parity, and a secret review.**
- [ ] **Step 6: Re-read `V3_EXECUTION_STATE.md` and `V2_V3_PARITY_MATRIX.md`, then produce the requested final report with `NO LIVE MUTATION: PASS` and either `PROJECT_BUILD_MODE_IMPLEMENTED` or `PROJECT_BUILD_MODE_BLOCKED` based on fresh evidence.**
