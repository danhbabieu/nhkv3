# MCP V3 Content Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Audit the current MCP V3 bridge and harden only the already-registered contract surface for ChatGPT/Codex content operations.

**Architecture:** Keep MCP as JSON-RPC transport/orchestration. All semantic writes continue through the existing Governance proposal lifecycle and existing Application/Domain services. Post CRUD/publish, binary Media upload, Graph read MCP, Product–Specimen canonical resolution and Album remain explicitly gated where the current contracts do not authorize them.

**Tech Stack:** PHP 8+, WordPress REST, PHPUnit, existing NHK V3 registries/services, Markdown runtime documentation.

**Spec:** `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`

## Global Constraints

- Do not bootstrap, process old snapshots, migrate V2 data, investigate MySQL/Homebrew unless it directly blocks MCP, or push.
- Do not invent entity types, predicates, relation types, fields, operations, taxonomy, metadata Graph truth, or Album contracts.
- Preserve the pre-existing working-tree Brand/Graph changes; this task must not revert or overwrite them.
- WordPress native `wp_posts` remains editorial title/body/author/date/category, URL and publish truth.
- Product is a listing/offer; Specimen is a concrete physical object.
- All semantic writes remain Governance-backed, revision-safe, idempotent, audited and fail-closed.

### Task 1: Record the runtime audit

**Files:**
- Read: `McpToolCatalog.php`, `McpTransport.php`, registries, services and endpoint contracts.
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Capture the exact 19-tool catalog, effective operation allowlist, endpoint types, predicate state at HEAD versus the pre-existing working tree, Product/Specimen conflict and Album gap.
- [ ] Map every requested use case to an existing tool or an explicit `PARTIAL`, `BLOCKED`, `SEMANTIC_GAP` or `CONSTITUTION_CONFLICT` status.

### Task 2: Add contract-first MCP hardening

**Files:**
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`

- [ ] Add an exact ordered 19-tool assertion and assert the existing operation vocabulary is an enum on generic proposal creation.
- [ ] Run the focused test and observe the expected failure before changing production code.
- [ ] Add the existing effective operation strings only; do not add a new operation registry or new operation.
- [ ] Re-run focused tests and keep the validator fail-closed for unsupported operations before proposal persistence.

### Task 3: Verify and close out

**Files:**
- Read/verify: `tools/mcp-wire-smoke.php`, health/preflight tools and existing read-only smoke paths.

- [ ] Run targeted and full PHPUnit, PHP lint, `git diff --check`, MCP wire smoke, tools/list, health-check and read-only domain smokes.
- [ ] Preserve unavailable HTTP/WordPress runtime as an evidence blocker; do not create production fixtures.
- [ ] Report exact status, gaps/conflicts, changed files, test evidence and local HEAD without pushing.
