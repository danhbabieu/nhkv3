# MCP V3 Ability Exposure

Status: implementation checkpoint, 2026-09-02.

## Root cause

`nhk-core` already has the governed local Streamable HTTP endpoint and a 19-entry `McpToolCatalog`, but it did not register any NHK ability on WordPress's `wp_abilities_api_init` hook. The existing `nhk_mcp_register_tools` action has no consumer in this repository, so an Easy MCP/WordPress generic connector can discover only its generic WordPress abilities.

WordPress Abilities are registered under a namespaced `namespace/action` name. Client exposure requires the ability metadata `public=true`; REST discovery/execution additionally requires `show_in_rest=true`. Registration is feature-detected so the plugin remains safe on the declared WordPress 6.8 floor, where the Abilities API is not available.

## Read allowlist

The first connector slice registers only these existing, read-only `McpToolCatalog` entries as `nhk-v3/*` abilities:

| Existing MCP tool | WordPress ability | Permission | Exposure |
|---|---|---|---|
| `nhk.search` | `nhk-v3/search` | authenticated user with `read` | public + REST/MCP |
| `nhk.semantic.resolve` | `nhk-v3/semantic-resolve` | authenticated user with `read` | public + REST/MCP |
| `nhk.entity.get` | `nhk-v3/entity-get` | authenticated user with `read` | public + REST/MCP |
| `nhk.media.get` | `nhk-v3/media-get` | authenticated user with `read` | public + REST/MCP |
| `nhk.video.get` | `nhk-v3/video-get` | authenticated user with `read` | public + REST/MCP |
| `nhk.knowledge.get` | `nhk-v3/knowledge-get` | authenticated user with `read` | public + REST/MCP |
| `nhk.source.get` | `nhk-v3/source-get` | authenticated user with `read` | public + REST/MCP |
| `nhk.evidence.get` | `nhk-v3/evidence-get` | authenticated user with `read` | public + REST/MCP |

Each ability delegates to `McpReadHandler`, reuses the existing runtime input schema, and returns only the handler's reader-safe result. It does not create a second persistence path, expose raw Graph storage, or expose lifecycle/provenance/storage internals.

## Write boundary

These existing governed tools are intentionally not registered in the WordPress Abilities public/MCP allowlist at this checkpoint: `nhk.media.ingest`, `nhk.video.ingest`, `nhk.knowledge.ingest`, `nhk.source.ingest`, `nhk.evidence.ingest`, and all `nhk.proposal.*` tools. Their existing local MCP transport remains capability-gated and Governance-backed; exposing them through a second connector requires a separate contract/interoperability review proving the same registry, revision, idempotency, provenance, audit and permission semantics.

This is not a write workaround. No `wp_create_post`, taxonomy, post meta, direct SQL semantic mutation, or ungoverned ability is introduced.

## Verification boundary

Local unit tests assert the exact eight-name allowlist and reject write names. Guarded WordPress integration tests assert runtime registration, category/metadata, write exclusion and an authenticated read execution. The external Easy MCP deployment still needs a live discovery/call probe against its actual adapter; local code cannot prove that external plugin's configuration or server allowlist without that runtime.

WordPress references: [Abilities API](https://developer.wordpress.org/apis/abilities-api/) and [Abilities REST endpoints](https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/).
