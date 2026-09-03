# MCP V3 Ability Exposure

> **NON-NORMATIVE.** Đây là implementation checkpoint. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Status: implementation checkpoint, 2026-09-03.

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

## Governed workflow bridge

The connector-facing bridge now exposes the minimum governed Video workflow:

| Existing MCP tool | WordPress ability | Capability |
|---|---|---|
| `nhk.video.ingest` | `nhk-v3/video-ingest` | `nhk_create_proposals` |
| `nhk.proposal.create` | `nhk-v3/proposal-create` | `nhk_create_proposals` |
| `nhk.proposal.submit` | `nhk-v3/proposal-submit` | `nhk_submit_proposals` |
| `nhk.proposal.approve` | `nhk-v3/proposal-approve` | `nhk_approve_proposals` |
| `nhk.proposal.reject` | `nhk-v3/proposal-reject` | `nhk_approve_proposals` |
| `nhk.proposal.eligibility` | `nhk-v3/proposal-eligibility` | `nhk_view_governance` |
| `nhk.proposal.apply` | `nhk-v3/proposal-apply` | `nhk_apply_proposals` |

`nhk.proposal.eligibility` is deliberately a read-only Ability with a
`nhk_view_governance` capability gate; it is not marked governed merely for
discoverability. Each governed Ability delegates to the registered `/nhk/v1/mcp` transport. It
does not write WordPress directly, create a second proposal path or bypass
MCP validation, capability checks, Proposal → Approval → Eligibility →
Controlled Apply, audit or Graph execution. The remaining semantic ingest
writers stay on the custom MCP endpoint until separately reviewed.

`nhk.media.ingest` is explicitly excluded from the Ability bridge because its
canonical file path is multipart and an Ability callback cannot carry the file
part. Its metadata/file contract remains on the existing MCP transport; no
base64, data URL, URL adapter or persistence path is introduced. Every other
catalog tool is either mapped to an Ability or carries an explicit exclusion
reason in `McpAbilityRegistration`.

No `wp_create_post`, taxonomy, post meta, direct SQL semantic mutation or ungoverned ability was introduced.

These read-only abilities do not constitute Article Ingest. A generic
WordPress Post write or these abilities alone cannot be reported as a completed
V3 knowledge Article workflow; the approved semantic-preflight → draft →
governed-apply → read-back → publish boundary remains a future implementation
contract. No Article ability is added by this documentation checkpoint.

## Verification boundary

Unit tests assert the exact read and governed allowlists. Guarded integration
tests assert runtime registration, metadata, capability boundaries and
authenticated read execution, but the current local WordPress/DB bootstrap is
unavailable. The custom MCP wire smoke and external Easy MCP deployment call
verification remain environment-dependent. On 2026-09-03 the live Easy MCP
Browser showed `Nhk-v3` as 32/32 enabled (12 read, 20 write), implying 32 Easy
MCP exposed tools. A separate actual connector/client MCP-29 `tools/list`
evidence still returned only 8 read/status tools and none of `video-ingest` or
the Proposal lifecycle; this is an exposure-state blocker (stale snapshot,
endpoint/profile or authorization scope), not a Video backend failure.
