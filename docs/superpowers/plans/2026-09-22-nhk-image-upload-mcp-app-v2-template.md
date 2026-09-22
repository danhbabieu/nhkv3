# NHK Image Upload MCP App v2 Template Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the NHK Image Upload MCP App discoverable and fetchable through the actual Easy MCP tools/list/resources path using a cache-busting fixed v2 resource URI.

**Architecture:** Keep the canonical NHK tool catalog and upload behavior unchanged except for the widget opener's UI resource identity. Make the Easy MCP projection merge the v2 UI metadata into any existing `_meta`, register the v2 URI in the fixed resource registry, and read the existing bundled uploader HTML from disk. Preserve existing authentication, security, schemas, and upload result envelopes.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress REST/MCP transport, TypeScript/Vite bundled HTML, Node test runner.

**Spec:** User request in the current task.

## Global Constraints

- Do not deploy, rsync, purge remote caches, modify production, or claim live success.
- Do not modify Capture, Article, Semantic, Public URL, database migrations, or upload/Media semantics.
- Use exact fixed URI `ui://nhk/image-upload/v2.html` in catalog, projection, resource list, and resource read.
- Preserve all existing `_meta`, OAuth/security metadata, permissions, ability visibility, schemas, and upload result behavior.
- Unknown UI resource URIs fail closed.

## Review Focus

- Existing nested `_meta.ui` and sibling security/auth keys survive projection; test in `EasyMcpNativeFileCompatibilityAdapterTest`.
- Easy MCP's final REST echo boundary performs the same merge as direct projection; test final `tools/list` data.
- Fixed resource is listed/readable while `resources/templates/list` is not required; test registry and transport.
- The bundled HTML path remains readable and contains no direct external origin; test artifact markers and source scan.
- Existing media widget upload descriptor and result envelope remain unchanged; retain current widget upload tests.

### Task 1: Add failing v2 metadata/resource regression tests

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EasyMcpNativeFileCompatibilityAdapterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/McpAppsImageUploadTest.php`

- [ ] Add assertions for exact v2 URI, `ui.visibility`, `openai/outputTemplate`, preservation of existing `_meta` security/auth fields, exact resource read, MIME, non-empty HTML, and unknown URI failure.
- [ ] Run the focused tests and confirm they fail because production still emits the old URI/metadata.

### Task 2: Implement the v2 fixed resource and metadata merge

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAppsResourceRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php`
- Modify: `tools/mcp-apps/image-upload/acceptance/host.html`

- [ ] Change only the widget resource constant/reference to `ui://nhk/image-upload/v2.html` while preserving the existing bundled file path and fixed resource semantics.
- [ ] Merge UI metadata into existing connector metadata recursively, retaining unrelated `_meta` and security/auth keys; set `ui.resourceUri`, `ui.visibility`, and `openai/outputTemplate` exactly.
- [ ] Keep `resources/list` and `resources/read` fixed-resource behavior and fail closed for unknown URIs.
- [ ] Verify the bundle contains no direct external script/style/image origin requiring CSP metadata; add none unless a real origin is found.

### Task 3: Run verification and update execution evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Run focused PHP and frontend tests, then relevant MCP/Media suites, PHP lint, project preflight, diff check, and secret review.
- [ ] Confirm no remote deployment or live mutation occurred and report the exact commit.
