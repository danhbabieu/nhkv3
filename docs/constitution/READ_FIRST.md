# NHK V3 Mandatory Read-First Router

This file is a short non-normative router, not a second Constitution.

Before architectural or implementation work, read in this order:

1. `AGENTS.md`.
2. `docs/constitution/READ_FIRST.md`.
3. `docs/constitution/NHK_V3_CONSTITUTION.md`.
4. `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` to distinguish
   current contracts/runtime sources from dated implementation evidence.
5. The relevant approved contracts and current executable/runtime boundary.

## Authoritative contract map

After the Constitution and current-status index, read the contracts relevant to
the operation:

| Concern | Required documents |
|---|---|
| Article / News / editorial | `docs/architecture/ARTICLE_INGEST_CONTRACT.md`, `docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md`, `docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md` |
| Media / Image | `docs/architecture/04_MEDIA_MODEL.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`, relevant Media contracts and runtime registries |
| Video | `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`, `docs/architecture/VIDEO_HUB_CLASSIFICATION_CONTRACT.md`, `docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md`, `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`, `docs/mcp/MCP_V3_VIDEO_WORKFLOW.md` |
| Knowledge / Claim / Source / Evidence | `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`, `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`, `docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md` when public copy is involved |
| Authority / Brand / Model / Variant / Movement / Music | `docs/architecture/02_AUTHORITY_BOUNDARY.md`, `docs/architecture/13_AUTHORITY_CORE_CONTRACT.md`, `docs/architecture/V3_BRAND_RELATIONSHIP_MATRIX.md`, `docs/architecture/PUBLIC_BRAND_NAMING_CONTRACT.md` |
| Public identity / route / SEO | `docs/architecture/V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md`, `docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md`, `docs/architecture/V3_FRONTEND_ROUTE_INVENTORY.md`, relevant SEO contracts, persisted-identity design/spec and the current PublicIdentity implementation/runtime evidence when in scope |
| MCP / Admin | current contract: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`; current tool/Ability availability must be checked against executable catalog/registration and fresh runtime discovery. `docs/mcp/MCP_V3_ABILITY_EXPOSURE.md` is historical/superseded evidence only |
| Storage / schema / execution | current boundaries from the relevant domain contracts plus `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`; `docs/architecture/21_P5_CANONICAL_DOMAIN_FOUNDATION.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/architecture/V3_EXECUTION_STATE.md` and `docs/architecture/V2_V3_PARITY_MATRIX.md` contain implementation/history evidence and must be interpreted by date/context |

## Current versus historical evidence

The Constitution is the only normative authority. Current approved contracts
are subordinate to it. Executable registries/catalogs define the currently
implemented vocabulary/capability inside that constitutional boundary, and
fresh runtime discovery/read-back determines actual environment availability.

The following are **dated evidence**, not timeless current law unless explicitly
reaffirmed by a current contract/runtime source:

- exact MCP tool or WordPress Ability counts;
- historical Ability allowlists;
- test/assertion counts;
- old `READY` / `PARTIAL` / `BLOCKED` statements;
- migration/probe outcomes;
- numbered phase checkpoint conclusions;
- parity/audit snapshots;
- older sections of `V3_EXECUTION_STATE.md`.

In particular, the historical statements in `MCP_V3_ABILITY_EXPOSURE.md` about
a 19-tool catalog, limited Video-only governed bridge, fixed exposure counts or
“No Article Ability” must not be used as current capability truth.

The persisted Public Identity service/repository/history implementation and
migration 014 now exist in code. That implementation evidence must not be
confused with live activation: guarded migration execution, persisted row/data
coverage, current-route consumer parity and target-environment read-back still
need verification before claiming durable Public Identity is live everywhere.
Compatibility name-derived routing is not a second durable identity writer.

Specs, plans, audits, parity matrices, READMEs and historical V2 material are
subordinate evidence or implementation guidance. If any source conflicts with
the Constitution, mark `CONSTITUTION_CONFLICT` and stop at the applicable human
gate.

For an approved V3 knowledge Article workflow, also read
`docs/architecture/ARTICLE_INGEST_CONTRACT.md` and the current
`docs/mcp/MCP_V3_CONTENT_OPERATIONS.md` contract before implementation.

For MCP/Admin content operations, also read
`docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`. For Article research or
SEO work, read the shared and applicable projection contracts first:
`docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`,
`docs/seo/ENTITY_SEO_PROJECTION_CONTRACT.md`,
`docs/seo/MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`, and
`docs/seo/SITEMAP_INDEXABILITY_CONTRACT.md`. Then read
`docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md` and
`docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md` before implementation.

For Media/image upload, storage, attachment projection or Article image work,
also read `docs/architecture/04_MEDIA_MODEL.md`,
`docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`,
`docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md` and the Media section of
`docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`. The source-original/derivative,
Media/MediaAsset/MediaUsage and WordPress-attachment boundaries must be read as
one contract set; historical checkpoint wording never overrides the current
Constitution.

For Video intake or Video-derived Knowledge planning, also read
`docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`,
`docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`,
`docs/mcp/MCP_V3_VIDEO_WORKFLOW.md` and
`docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`. An explicit validated
Video `about` target must remain the semantic/enrichment target; Video-derived
Knowledge output is planning-only unless separately governed and applied.

For any workflow that drafts, generates, edits, projects or publishes public
promotional/commercial copy — including WordPress Article text, Product copy,
MediaUsage caption/alt or image/thumbnail text, Video editorial copy, SEO/meta,
Open Graph, structured promotional copy, cards or comparison copy — also read
`docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md` before
implementation or publication. This requirement is cross-cutting; no public
channel may bypass the same claim/evidence compliance policy.
