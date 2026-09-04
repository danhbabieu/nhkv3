# MCP V3 Ability Exposure

> **HISTORICAL / SUPERSEDED CHECKPOINT — 2026-09-03.**
> The fixed MCP tool counts, limited Ability allowlists and the statement that no
> Article Ability existed below describe an earlier implementation checkpoint.
> They MUST NOT be used as the current MCP/Abilities capability surface.
>
> For current downstream work, read:
> - `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`;
> - `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`;
> - the current `McpToolCatalog` / Ability registration implementation;
> - and fresh runtime tool/Ability discovery plus read-back when capability
>   availability matters.
>
> `nhk.media.ingest` remains a special multipart path on the custom MCP
> transport. This historical file does not authorize a base64, data-URL, remote
> URL or alternate Ability upload writer.

> **NON-NORMATIVE HISTORICAL EVIDENCE.** If any historical statement below
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, a current approved
> contract, the executable registry/catalog, or fresh runtime discovery, the
> current authority controls.

Status: superseded implementation checkpoint originally recorded 2026-09-03.

## Historical root cause

`nhk-core` had the governed local Streamable HTTP endpoint and a 19-entry `McpToolCatalog`, but it did not register any NHK ability on WordPress's `wp_abilities_api_init` hook. The existing `nhk_mcp_register_tools` action had no consumer at that checkpoint, so an Easy MCP/WordPress generic connector could discover only generic WordPress abilities.

WordPress Abilities use a namespaced `namespace/action` name. Client exposure uses `public=true`; REST discovery/execution additionally uses `show_in_rest=true`. Registration is feature-detected because the plugin declares WordPress 6.8 compatibility while the Abilities API is available in WordPress 6.9+.

## Historical read allowlist

At this checkpoint the adapter registered only these existing read tools:

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

Each ability delegated to `McpReadHandler` and reused the then-existing catalog input schema. Results remained reader-safe; no raw Graph storage, lifecycle fields, provenance internals or second persistence path was exposed.

## Historical governed workflow bridge

At this checkpoint the connector-facing bridge exposed the minimum governed Video workflow:

| Existing MCP tool | WordPress ability | Capability |
|---|---|---|
| `nhk.video.ingest` | `nhk-v3/video-ingest` | `nhk_create_proposals` |
| `nhk.proposal.create` | `nhk-v3/proposal-create` | `nhk_create_proposals` |
| `nhk.proposal.submit` | `nhk-v3/proposal-submit` | `nhk_submit_proposals` |
| `nhk.proposal.approve` | `nhk-v3/proposal-approve` | `nhk_approve_proposals` |
| `nhk.proposal.reject` | `nhk-v3/proposal-reject` | `nhk_approve_proposals` |
| `nhk.proposal.eligibility` | `nhk-v3/proposal-eligibility` | `nhk_view_governance` |
| `nhk.proposal.apply` | `nhk-v3/proposal-apply` | `nhk_apply_proposals` |

`nhk.proposal.eligibility` was deliberately a read-only Ability with a
`nhk_view_governance` capability gate; it was not marked governed merely for
discoverability. Each governed Ability delegated to the registered `/nhk/v1/mcp` transport. It
did not write WordPress directly, create a second proposal path or bypass
MCP validation, capability checks, Proposal → Approval → Eligibility →
Controlled Apply, audit or Graph execution. The remaining semantic ingest
writers stayed on the custom MCP endpoint until separately reviewed.

`nhk.media.ingest` was explicitly excluded from the Ability bridge because its
canonical file path is multipart and an Ability callback cannot carry the file
part. Its metadata/file contract remained on the existing MCP transport; no
base64, data URL, URL adapter or persistence path was introduced.

No `wp_create_post`, taxonomy, post meta, direct SQL semantic mutation or ungoverned ability was introduced by this checkpoint.

At that checkpoint these read-only abilities did not constitute Article Ingest,
and no Article Ability had yet been added. **That last sentence is historical
only and is explicitly superseded by the current MCP/Article contracts and
runtime catalog/Ability registration.**

## Historical verification boundary

Unit tests at the checkpoint asserted the then-current read and governed
allowlists. Guarded integration tests covered runtime registration, metadata,
capability boundaries and authenticated read execution, but the local
WordPress/DB bootstrap was unavailable. The custom MCP wire smoke and external
Easy MCP deployment verification remained environment-dependent. On 2026-09-03
the live Easy MCP Browser showed `Nhk-v3` as 32/32 enabled (12 read, 20 write),
while a separate connector/client MCP-29 `tools/list` returned only 8
read/status tools and none of `video-ingest` or the Proposal lifecycle. Those
counts are retained solely as historical deployment evidence and are not a
current catalog contract.
