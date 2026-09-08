# MCP Documentation Bootstrap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose canonical NHK V3 documentation and truthful runtime capability metadata through two bounded read-only MCP tools.

**Architecture:** A dedicated documentation registry owns the allowlist, source-root selection, bounded UTF-8 reads and snapshot identity. The existing MCP catalog and transport delegate to it; runtime status is projected from the executable capability manifest and is never inferred from document text.

**Tech Stack:** PHP 8+, WordPress plugin PSR-4 autoload, PHPUnit, JSON-RPC MCP transport.

**Spec:** `docs/superpowers/specs/2026-09-08-mcp-documentation-bootstrap-design.md`

## Global Constraints

- Read-only documentation surface; no semantic writes, migrations, seeds or data mutation.
- Client input is a document key, never an arbitrary filesystem path.
- Only allowlisted canonical docs and `AGENTS.md` may be read.
- Maximum document size is 512 KiB; invalid UTF-8 and unavailable files fail closed.
- `McpCapabilityManifest` remains the runtime capability source; no duplicate static tool catalog.
- Preserve all existing uncommitted Media/MCP changes.

### Task 1: Documentation registry and bootstrap projection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDocumentationRegistry.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php`

**Interfaces:**
- Produces `documentKeys(): list<string>`, `get(string $key): array`, and `bootstrap(): array`.
- `get()` returns `key`, `path`, `content`, `documentation_revision`, `source_revision`, `generated_at` and `classification` without exposing an arbitrary client path.

- [x] Write tests for allowlisted key resolution, traversal-like key rejection, size/UTF-8 fail-closed behavior, stable hash and bootstrap sections.
- [x] Run the focused PHPUnit test and observe failure before implementation.
- [x] Implement fixed key-to-relative-path metadata, fixed candidate roots, bounded reads, UTF-8 validation, content hash and git revision discovery without hardcoded commit values.
- [x] Implement bootstrap required reading, current contracts, registry gaps and runtime status from `McpCapabilityManifest::all()`.
- [x] Run the focused test until it passes.

### Task 2: MCP catalog and transport exposure

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

**Interfaces:**
- Adds read tools `nhk.docs.bootstrap` and `nhk.docs.get` with the registry key enum.
- `McpTransport` dispatches the tools using the existing `read` capability and `McpDocumentationRegistry`.

- [x] Add catalog/schema and dispatch contract tests.
- [x] Run focused tests to verify the new assertions fail.
- [x] Add tools and an optional last constructor dependency so existing callers remain source-compatible.
- [x] Dispatch bootstrap/get and wrap results in the existing MCP structured-content envelope.
- [x] Run the focused test suite.

### Task 3: MCP surface integration and documentation status

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [x] Assert both tools are present in `tools/list` and document keys are bounded.
- [x] Run PHP lint, focused/full Unit tests, integration attempt if environment permits, `git diff --check`, and a secret scan.
- [x] Record the checkpoint as code-side MCP exposure with live discovery explicitly pending unless freshly verified.
