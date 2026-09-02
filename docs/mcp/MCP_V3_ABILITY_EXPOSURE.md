# MCP V3 Ability Exposure

Status: implementation checkpoint, 2026-09-02.

## Root cause

`nhk-core` had the governed local Streamable HTTP endpoint and a 19-entry `McpToolCatalog`, but it did not register any NHK ability on WordPress's `wp_abilities_api_init` hook. The existing `nhk_mcp_register_tools` action has no consumer in this repository, so an Easy MCP/WordPress generic connector could discover only generic WordPress abilities.

WordPress Abilities use a namespaced `namespace/action` name. Client exposure uses `public=true`; REST discovery/execution additionally uses `show_in_rest=true`. Registration is feature-detected because the plugin declares WordPress 6.8 compatibility while the Abilities API is available in WordPress 6.9+.

## Read allowlist

The adapter registers only these existing read tools:

| Existing MCP tool | WordPress ability | Permission | Exposure |
|---|---|---|---|
| `nhk.search` | `nhk-v3/search` | WordPress `read` | public + REST/MCP |
| `nhk.semantic.resolve` | `nhk-v3/semantic-resolve` | WordPress `read` | public + REST/MCP |
| `nhk.entity.get` | `nhk-v3/entity-get` | WordPress `read` | public + REST/MCP |
| `nhk.media.get` | `nhk-v3/media-get` | WordPress `read` | public + REST/MCP |
| `nhk.video.get` | `nhk-v3/video-get` | WordPress `read` | public + REST/MCP |
| `nhk.knowledge.get` | `nhk-v3/knowledge-get` | WordPress `read` | public + REST/MCP |
| `nhk.source.get` | `nhk-v3/source-get` | WordPress `read` | public + REST/MCP |
| `nhk.evidence.get` | `nhk-v3/evidence-get` | WordPress `read` | public + REST/MCP |

Each ability delegates to `McpReadHandler` and reuses the existing catalog input schema. Results remain reader-safe; no raw Graph storage, lifecycle fields, provenance internals or second persistence path is exposed.

## Write boundary

`nhk.media.ingest`, `nhk.video.ingest`, `nhk.knowledge.ingest`, `nhk.source.ingest`, `nhk.evidence.ingest` and all `nhk.proposal.*` tools are intentionally absent from the WordPress Abilities public/MCP allowlist in this checkpoint. The existing custom MCP writes remain Governance-backed and capability-gated. A separate exposure review is required before registering them through another connector.

No `wp_create_post`, taxonomy, post meta, direct SQL semantic mutation or ungoverned ability was introduced.

## Verification boundary

Unit tests assert the exact eight-name allowlist and reject a write mapping. Guarded integration tests assert runtime registration, metadata, write exclusion and authenticated read execution, but the current local WordPress/DB bootstrap is unavailable. The read-only wire smoke also remains blocked by `localhost:80`; external Easy MCP deployment discovery/call verification remains pending.
