# MCP V3 Content Operations Implementation Plan

> **For agentic workers:** This plan is executed inline in the current NHK V3 workspace. It does not authorize bootstrap, old snapshots, V2 data migration, database reset, or push.

**Goal:** Audit the live MCP V3 boundary, document its exact capabilities, correct stale 18-tool assertions, and stop at the existing Product/Specimen conflict and missing Album contract.

**Architecture:** MCP remains a transport/orchestration adapter. Authority, Knowledge, Media, Video and Graph application services remain the validation and mutation boundaries; durable semantic writes continue through Governance. The audit records capabilities that cannot be exposed safely because the current runtime has no Post/Album/upload/read-back contract.

**Tech Stack:** PHP 8.5, WordPress REST, PHPUnit, Composer autoload, existing NHK V3 domain/application/infrastructure contracts.

**Spec:** `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`

## Global Constraints

- Read `docs/constitution/READ_FIRST.md` and `docs/architecture/V3_EXECUTION_STATE.md` before every checkpoint.
- Do not invent an entity type, endpoint type, predicate, relation type, canonical field, knowledge profile or governance operation.
- Do not bootstrap, import old snapshots, migrate V2 article bodies, modify V2/live data, or push.
- `nhk_v3_test` is the only destructive integration database; no production data is created by smoke checks.
- WordPress `wp_posts` remains the editorial title/body/URL source of truth.

### Task 1: Runtime audit

- [x] Enumerate the 19 entries from `McpToolCatalog::tools()` and verify the wire smoke expectation.
- [x] Read the Authority catalog, endpoint registrar, predicate registry, executor branches, and domain contracts.
- [x] Audit Post, Product/Specimen, Video, Media and Album boundaries.
- [x] Record the use-case capability matrix and Graph relationship matrix.

### Task 2: Contract-safe implementation

- [x] Update the two stale integration assertions from 18 to the current 19-tool contract.
- [x] Preserve `nhk.semantic.resolve`; do not remove it to satisfy stale tests.
- [x] Add the operational documentation and explicit semantic-gap/conflict records.

### Task 3: Verification

- [x] Run focused MCP/Graph/Knowledge/Media/Video unit tests.
- [x] Run the full unit suite and attempt guarded integration tests.
- [x] Run PHP lint, diff checks, wire smoke, tools/list and health/read-only smoke where the local HTTP/DB runtime is available.
