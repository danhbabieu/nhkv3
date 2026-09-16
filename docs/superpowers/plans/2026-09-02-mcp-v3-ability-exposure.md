# MCP V3 Connector Ability Exposure Implementation Plan

> **For agentic workers:** This plan is executed inline in the current NHK V3 workspace. It does not authorize bootstrap, old snapshots, V2 data migration, database reset, database writes outside guarded tests, or push.

**Goal:** Register the existing NHK V3 read contracts as WordPress Abilities so an Easy MCP/WordPress generic connector can discover and invoke them, while keeping write exposure behind a later governed approval gate.

**Architecture:** The existing \`McpToolCatalog\` remains the source of tool schemas and dispatch semantics. A new WordPress Abilities adapter registers only an explicit read allowlist under the \`nhk-v3\` namespace, marks those abilities public/read-only, delegates execution to \`McpReadHandler\`, and uses the existing WordPress authentication/capability boundary. No taxonomy, post meta, \`wp_posts\` semantic storage, new entity type, predicate, relation, or operation is introduced.

**Tech Stack:** PHP 8.1+, WordPress 6.9 Abilities API (feature-detected for older supported WordPress), existing NHK V3 PHPUnit and guarded WordPress integration suite.

**Spec:** \`docs/mcp/MCP_V3_ABILITY_EXPOSURE.md\`

## Global Constraints

- Read \`docs/constitution/READ_FIRST.md\` and \`docs/architecture/V3_EXECUTION_STATE.md\` before every checkpoint.
- Register only existing runtime MCP read tools; do not invent semantic types, fields, predicates, relations, or operations.
- WordPress \`wp_posts\` remains editorial title/body/author/date/category/archive/search/RSS/sitemap/URL truth.
- Read abilities expose only public reader-safe handler results; no lifecycle/provenance/storage internals.
- Write abilities remain absent from the WordPress Abilities public/MCP allowlist until a separate governed exposure decision.
- Preserve all pre-existing working-tree changes; do not run destructive database operations.

### Task 1: Contract and failing tests

**Files:**
- Create: \`docs/mcp/MCP_V3_ABILITY_EXPOSURE.md\`
- Create: \`public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php\`
- Modify: \`public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php\`
- Modify: \`public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php\`

- [ ] Add unit assertions for the exact read allowlist, namespaced ability names, public/show-in-rest metadata and read-only annotations.
- [ ] Add guarded integration assertions that the runtime registry contains all eight read abilities, excludes the existing write tools, and allows an authenticated read ability to execute against the real handler.
- [ ] Run the focused tests before implementation and observe the expected missing-registration failure.

### Task 2: Register the explicit read ability boundary

**Files:**
- Create: \`public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php\`
- Modify: \`public/wp-content/plugins/nhk-core/src/Plugin.php\`

- [ ] Register the \`nhk-semantic\` ability category on \`wp_abilities_api_categories_init\`.
- [ ] Register only \`nhk.search\`, \`nhk.semantic.resolve\`, \`nhk.entity.get\`, \`nhk.media.get\`, \`nhk.video.get\`, \`nhk.knowledge.get\`, \`nhk.source.get\`, and \`nhk.evidence.get\` as \`nhk-v3/*\` abilities.
- [ ] Set \`meta.public=true\`, \`meta.show_in_rest=true\`, and annotations \`readonly=true\`, \`destructive=false\`, \`idempotent=true\`; use a \`read\` permission callback and return \`WP_Error\` for bounded execution failures.
- [ ] Attach registration to \`wp_abilities_api_init\` and feature-detect the Abilities API without changing the existing custom Streamable HTTP route.
- [ ] Reuse the existing WPDB-backed \`McpReadHandler\` construction and existing \`McpToolCatalog\` input schemas; do not duplicate semantic persistence or bypass handlers.

### Task 3: Verify and checkpoint

**Files:**
- Modify: \`docs/architecture/V3_EXECUTION_STATE.md\`

- [ ] Run focused unit and guarded integration tests, then the full unit suite and PHP lint.
- [ ] Run \`git diff --check\` and a secret review.
- [ ] Run the read-only MCP wire smoke if localhost is available; do not invoke mutation tools.
- [ ] Record the registration/exposure/permission evidence and remaining external Easy MCP deployment verification in the execution state.

