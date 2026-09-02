# MCP V3 Connector Ability Exposure Implementation Plan

> **For agentic workers:** This plan was executed inline in the current NHK V3 workspace. It did not authorize bootstrap, V2 migration, database reset, or push.

**Goal:** Register existing NHK V3 read contracts as WordPress Abilities for Easy MCP discovery while keeping writes behind a later governed exposure gate.

**Architecture:** An explicit `nhk-v3` read allowlist maps existing `McpToolCatalog` schemas and `McpReadHandler` methods into WordPress Abilities. No new semantic contract or persistence path is introduced.

**Spec:** `docs/mcp/MCP_V3_ABILITY_EXPOSURE.md`

## Completed tasks

- [x] Add failing unit/integration coverage for the exact read allowlist, ability metadata, write exclusion and authenticated execution.
- [x] Register `nhk-semantic` on `wp_abilities_api_categories_init` and eight read abilities on `wp_abilities_api_init`.
- [x] Set `public`, `show_in_rest`, read-only annotations and the WordPress `read` permission callback; keep writes out of the ability allowlist.
- [x] Run unit tests, PHP lint and diff checks; record guarded integration/wire blockers in execution state.
