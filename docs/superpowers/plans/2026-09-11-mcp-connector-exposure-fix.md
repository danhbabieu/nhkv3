# MCP Connector Exposure Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the Easy MCP `nhk.capture.ingest` tools/list descriptor preserve the canonical Capture schema through Ability registration and final REST serialization.

**Architecture:** Keep `McpToolCatalog` as the schema owner and `McpAbilityRegistration` as the Ability projection. Add only the missing Easy MCP boundary coverage needed when the upstream serializer drops NHK sibling properties or metadata. The final REST response must project the canonical descriptor for the exact Capture tool without changing semantic handlers or adding a second writer.

**Tech Stack:** PHP 8, WordPress Abilities API, Easy MCP compatibility adapter, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-11-conversational-authority-design.md`

## Global Constraints

- Do not change Authority, Graph, Governance or Article semantics.
- Do not call `nhk.capture.ingest`, deploy, or mutate live data.
- Preserve native multipart `files[]` and `_meta["openai/fileParams"]`.
- Use the canonical `McpToolCatalog` descriptor; do not create a duplicate schema owner.
- Fail closed for unsupported Easy MCP versions and unrelated tools.

---

### Task 1: Prove the current exposure boundary

**Files:**
- Read: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Read: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- Read: `public/wp-content/plugins/nhk-core/src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EasyMcpNativeFileCompatibilityAdapterTest.php`

- [ ] Step 1: Run the focused existing projection tests and record the baseline.
- [ ] Step 2: Inspect the actual Easy MCP package/fixture boundary available to the repository.
- [ ] Step 3: Identify the exact dropped fields or hook/route mismatch before editing production code.

### Task 2: Add a failing regression for the observed gap

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EasyMcpNativeFileCompatibilityAdapterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Integration/EasyMcpNativeFileCompatibilityIntegrationTest.php` when the local Easy MCP runtime is available.

- [ ] Step 1: Add assertions for `capture_id`, purpose enum, `authority_intent.mode`, approval fields, text/title/excerpt and native file metadata at the boundary that currently loses them.
- [ ] Step 2: Run the focused test and verify it fails for the missing exposure behavior, not for a fixture error.

### Task 3: Implement the minimal projection fix

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php` or the exact Easy MCP projection boundary found in Task 1.
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` only if hook registration order is proven to be the cause.

- [ ] Step 1: Preserve the complete canonical Capture `inputSchema` and connector metadata through Ability/tool serialization.
- [ ] Step 2: Keep projection scoped to `wp_ability_nhk_v3_capture_ingest` and the Easy MCP endpoint.
- [ ] Step 3: Do not alter handler dispatch, semantic ownership, permissions or multipart transport.
- [ ] Step 4: Run the regression test and verify it passes.

### Task 4: Verify all exposure layers

**Files:**
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/EasyMcpNativeFileCompatibilityIntegrationTest.php`

- [ ] Step 1: Verify catalog and Ability registration expose the canonical fields.
- [ ] Step 2: Verify the Easy MCP projection preserves fields and native file metadata.
- [ ] Step 3: Verify no unrelated tool is changed and no second schema owner is introduced.
- [ ] Step 4: Run PHP lint, focused tests, relevant regression tests and `git diff --check`.

### Task 5: Update executable state and commit

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with implementation/test status only.

- [ ] Step 1: Re-read the spec and map each exposure requirement to evidence.
- [ ] Step 2: Scan changed files for secrets and accidental placeholders.
- [ ] Step 3: Confirm unrelated worktree changes remain untouched.
- [ ] Step 4: Commit only the bounded connector-exposure fix files.
