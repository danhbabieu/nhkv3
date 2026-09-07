# Read-Only Semantic Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add canonical inventory, Graph inventory, and relation backfill dry-run capabilities without semantic mutation.

**Architecture:** Reuse current repositories and registries behind focused read-side application services. Extend the existing MCP read catalog/handler only after service contracts are proven by tests; no new canonical writer or relation vocabulary is introduced.

**Tech Stack:** PHP 8.2+, PHPUnit, WordPress plugin runtime, Composer.

**Spec:** `docs/superpowers/specs/2026-09-07-readonly-semantic-inventory-design.md`

## Global Constraints

- Read-only: zero writes to canonical stores, Graph, Governance or demo data.
- WordPress `wp_posts` remains editorial truth; no legacy article body parsing or migration.
- Runtime registries are authoritative; do not add `classified_as` or `Model → uses_movement`.
- Filter before pagination; ambiguity and unavailable dependencies fail closed.
- Preserve existing working-tree changes.

### Task 1: Canonical inventory reader

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Inventory/CanonicalInventoryService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Inventory/InventoryPage.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CanonicalInventoryServiceTest.php`

**Interfaces:**
- Consumes the existing AuthorityTypeRegistry plus Authority/Media/Video/Knowledge/Source/Evidence repositories.
- Produces `inventory(array $filters, int $limit, ?string $after): InventoryPage` and normalized rows with `type`, `uuid`, `stable_key`, `revision`, `state`, `active`, `provenance`, and `visibility`.

- [ ] Write a failing test for filtering by type/state before pagination and for evidence deduplication.
- [ ] Run the focused test and verify it fails because the service is absent.
- [ ] Implement the smallest normalized reader; use repository list methods only and do not add writers.
- [ ] Run the focused test and verify it passes.
- [ ] Refactor only after green, then commit the focused slice.

### Task 2: Graph inventory reader

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Inventory/GraphInventoryService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Inventory/GraphInventoryReport.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GraphInventoryServiceTest.php`

**Interfaces:**
- Consumes `GraphRepository`, `EndpointTypeRegistry`, and `PredicateRegistry`.
- Produces `inventory(array $filters, int $limit, ?string $after): GraphInventoryReport` with edge rows, direction, and diagnostics.

- [ ] Write failing tests for outbound/inbound enumeration, filter-before-pagination, dangling endpoints, invalid endpoint types, and duplicate logical edges.
- [ ] Run the focused test and confirm the expected failure.
- [ ] Implement read-only enumeration using existing node/edge reads; classify every unresolved endpoint without throwing away the diagnostic.
- [ ] Run focused tests, then refactor with all tests green.
- [ ] Commit the slice.

### Task 3: Relation dry-run report

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/RelationBackfillService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/RelationBackfillReport.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/RelationBackfillDryRunContractTest.php`

**Interfaces:**
- `dryRun()` remains the only exposed operation for this capability and returns counters for `EXISTING`, `MISSING_DETERMINISTIC`, `AMBIGUOUS`, `RELATION_PENDING`, `REGISTRY_GAP`, `EVIDENCE_GAP`, `ORPHAN`, `DUPLICATE_CANDIDATE`, `INVALID_ENDPOINT`, and `NOT_APPLICABLE` plus candidate rows.
- No apply method is added to the capability boundary.

- [ ] Write failing tests proving resolver precedence, status counters, registry rejection, and zero repository mutation calls.
- [ ] Run them and verify the expected failure.
- [ ] Implement fail-closed status normalization and machine-readable output while preserving the existing generic service behavior where compatible.
- [ ] Run focused tests and then the full Unit suite.
- [ ] Commit the slice.

### Task 4: MCP capability exposure

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php` only if existing read registration requires it.
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpSemanticInventoryContractTest.php`

**Interfaces:**
- Add read-only names `nhk.canonical.inventory`, `nhk.graph.inventory`, and `nhk.relation.backfill.dry_run` with bounded filter/page schemas.
- Handler methods delegate to the read services and return machine-readable payloads; no governed operation is added.

- [ ] Write failing catalog/dispatch tests, including rejection of mutation-shaped input.
- [ ] Run them and confirm failure.
- [ ] Wire dependencies in the plugin composition root and delegate through the established MCP transport.
- [ ] Run focused MCP tests and full Unit suite.
- [ ] Commit the exposure slice.

### Task 5: Runtime gates, deployment read-back and checkpoint

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with a dated checkpoint only after verification.
- No demo data files are changed.

- [ ] Run focused tests.
- [ ] Run Unit and guarded Integration suites; preserve explicit environment-blocked results.
- [ ] Run PHP lint, Composer validate, `git diff --check`, and secret review.
- [ ] Inspect the project deployment procedure and deploy only if the documented permission/credentials path is available.
- [ ] On demo, run canonical inventory, Graph inventory, then dry-run; capture totals and confirm no mutation.
- [ ] Run final diff/status review and record the commit SHA; stop before governed apply.
