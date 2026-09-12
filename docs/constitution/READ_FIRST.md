# NHK V3 Mandatory Read-First Router

This file is a short non-normative router, not a second Constitution.

## Canonical documentation source and operator startup

Canonical documentation remains file-based under `docs/` (with `AGENTS.md` as
the explicit bootstrap root document). MCP V3 is only a read-only runtime
projection of the exact deployed documentation snapshot; it is not a database
documentation store, an editable rule source or a code-generated substitute.
When a deployment does not carry repository `docs/`, the build must generate
`nhk-core/resources/canonical-docs/` and its deterministic manifest from these
files. The generated snapshot is immutable and is never edited by hand.

An operator starts `CONNECT MCP → documentation-bootstrap → read READ_FIRST,
Status Index and Execution State → documentation-list/get the ACTIVE contracts
needed for the task → checkpoint → Capture`. GitHub is not required for this
read path. A mutation-capable actor must be able to call the documentation
read surface through the same MCP connection.

The canonical documentation abilities are `nhk-v3/documentation-bootstrap`,
`nhk-v3/documentation-get` and `nhk-v3/documentation-list`. `documentation-get`
accepts only a manifest-allowlisted repository-relative path and bounded line
ranges. Unknown, traversing, encoded-traversing, absolute, symlink-escaping or
tampered paths fail closed.

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
| Editorial Capture / one-submission Article flow | `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`, `docs/architecture/ARTICLE_INGEST_CONTRACT.md`, the owning Media/Video/Knowledge/Graph/Governance contracts involved by the submission, and the current executable `EditorialCaptureCoordinator`/Capture repository boundary |
| Dictionary / lexical curation / auto-link | `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` plus the owning Article, Knowledge, Media/Image, Video, public-route and SEO contracts for the content being detected or linked |
| Media / Image | `docs/architecture/04_MEDIA_MODEL.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`, relevant Media contracts and runtime registries |
| Video | `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`, `docs/architecture/VIDEO_HUB_CLASSIFICATION_CONTRACT.md`, `docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md`, `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`, `docs/seo/PUBLIC_URL_SLUG_CONTRACT.md`, `docs/mcp/MCP_V3_VIDEO_WORKFLOW.md` |
| Knowledge / Claim / Source / Evidence | `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`, `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`, `docs/architecture/COLLECTOR_PROFILE_CONTRACT.md` when Collector Profile/facet maintenance is in scope, `docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md` when public copy is involved |
| Authority / Brand / Model / Variant / Movement / Music / Classification / Clock Type | `docs/architecture/02_AUTHORITY_BOUNDARY.md`, `docs/architecture/13_AUTHORITY_CORE_CONTRACT.md`, `docs/architecture/ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md`, `docs/architecture/V3_BRAND_RELATIONSHIP_MATRIX.md`, `docs/architecture/PUBLIC_BRAND_NAMING_CONTRACT.md`, `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md` when public Entity detail aggregation/display is in scope |
| Public Entity dossier / relation display | `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`, the owning Authority/Graph/Knowledge/Media/Video/Article contracts, and the applicable entity relationship matrix; reachable graph context is not automatically inherited truth |
| Public identity / route / SEO | `docs/seo/PUBLIC_URL_SLUG_CONTRACT.md`, `docs/architecture/V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md`, `docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md`, `docs/architecture/V3_FRONTEND_ROUTE_INVENTORY.md`, relevant SEO contracts, persisted-identity design/spec and the current PublicIdentity implementation/runtime evidence when in scope |
| MCP / Admin | current contract: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`; all MCP ingest also follows Constitution §20.1 bounded post-ingest reconciliation and completion gate; current tool/Ability availability must be checked against executable catalog/registration, fresh target runtime discovery and the actual client/connector surface. `docs/mcp/MCP_V3_ABILITY_EXPOSURE.md` is historical/superseded evidence only |
| Storage / schema / execution | current boundaries from the relevant domain contracts plus `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`; `docs/architecture/21_P5_CANONICAL_DOMAIN_FOUNDATION.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/architecture/V3_EXECUTION_STATE.md` and `docs/architecture/V2_V3_PARITY_MATRIX.md` contain implementation/history evidence and must be interpreted by date/context |

For Media upload specifically, read the Media model, P6 foundation, Admin Media
guidance and the Media section of the current MCP Content Operations contract
together. The current file flow is `nhk.media.upload-batch` multipart → native
WordPress attachment lifecycle → canonical read-back / `media-attachment-get`
→ separate governed `media-ingest` → MediaAsset → Media → MediaUsage. Do not
use the historical Ability exposure document or a JSON-only Ability as the
multipart binary contract.

For Editorial Capture specifically, treat the coordinator as orchestration over
existing owners, never as a new semantic owner. The accepted shared path is
text-only, multipart images or the registered Video adapter: one new submission
→ one durable Capture → one native WordPress draft by default; each physical
image and external Video keeps its own canonical identity and owner boundary.
Knowledge-only text uses the same path. Replays with the same idempotency key
and unchanged payload resume the same Capture; a changed payload conflicts
instead of creating a duplicate Article or re-uploading completed assets.

`nhk.capture.ingest` is the only normal entry point for new content. Direct
Media, Video, Knowledge, Source/Evidence, Article, relation and publication
writers are internal/admin compatibility boundaries only and must fail closed
without `nhk_internal_content_operations`; never substitute one of them when
Capture is unavailable.

The semantic core is `interpret → resolve canonical subjects → bounded Graph
neighborhood → retrieve candidate Claims → evaluate scope/provenance/evidence/
relevance → governed semantic write-back/review → compose Article`. Graph
reachability discovers candidates only. A relation path never authorizes Claim
reuse by itself; selected Claims must retain canonical ID/revision, original
semantic subject and explainable path, and must pass the applicable scope,
provenance, evidence and relevance checks. Generated Article prose, image
observations, captions, OCR and transcripts are not Evidence merely because the
Capture can see them.

The current accepted Capture adapter covers text-only and multipart image
submissions. Video remains a distinct canonical external-reference intake until
a registered shared Capture adapter exists. Do not manufacture a unified result
by creating a duplicate Article, duplicate Video, convenience relation or a
generic WordPress fallback.

Runtime capability and client/connector exposure are separate facts. A tool or
Ability can exist in the executable runtime catalog and still be unavailable to
a particular connector/session. Treat that as `CLIENT_EXPOSURE_GAP`, not proof
that the runtime capability is absent. Conversely, when the current client
cannot invoke a required boundary, fail closed for that step; never substitute a
generic Post/Media writer or a historical Ability assumption. Fresh target
runtime discovery/read-back determines runtime availability, while fresh client
connector discovery determines callability from the current session.

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

The public Entity dossier layer is governed by
`docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`. It is a
read-only composition over canonical owners, not a new semantic store. Direct
Knowledge remains subject-scoped. Longer Brand context is exposed only through
explicit registered path recipes and retains direct/derived provenance; do not
raise generic traversal depth or persist shortcut relations merely to make a
page richer.

The Dictionary lexical layer is governed by
`docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`. Dictionary
Concept/Label/Candidate/Mention records are lexical curation state only: they do
not become Authority, Knowledge, Evidence or Graph truth. Detection during
Article, Knowledge, Media/Image or Video workflows must therefore be read with
the corresponding owning-domain contract. Research/preflight is preview-only;
persistence of Dictionary observations happens only after an owning content
write or an explicit human curation action.

Specs, plans, audits, parity matrices, READMEs and historical V2 material are
subordinate evidence or implementation guidance. If any source conflicts with
the Constitution, mark `CONSTITUTION_CONFLICT` and stop at the applicable human
gate.

For an approved V3 knowledge Article workflow, also read
`docs/architecture/ARTICLE_INGEST_CONTRACT.md`,
`docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md`,
`docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` when lexical
detection/linking is in scope, and the current
`docs/mcp/MCP_V3_CONTENT_OPERATIONS.md` contract before implementation.

For MCP/Admin content operations, also read
`docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`. For Article research or
SEO work, read the shared and applicable projection contracts first:
`docs/seo/NHK_V3_SEO_CORE_CONTRACT.md`,
`docs/seo/PUBLIC_URL_SLUG_CONTRACT.md`,
`docs/seo/ENTITY_SEO_PROJECTION_CONTRACT.md`,
`docs/seo/MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`, and
`docs/seo/SITEMAP_INDEXABILITY_CONTRACT.md`. Then read
`docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md`,
`docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` when relevant, and
`docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md` before implementation.

For Media/image upload, storage, attachment projection or Article image work,
also read `docs/architecture/04_MEDIA_MODEL.md`,
`docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`,
`docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`,
`docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` when lexical
observation is enabled, and the Media section of
`docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`. The source-original/derivative,
Media/MediaAsset/MediaUsage and WordPress-attachment boundaries must be read as
one contract set; historical checkpoint wording never overrides the current
Constitution. OCR, filename, caption, alt or recognition may be lexical
observations only; they do not become semantic truth automatically.

For Video intake or Video-derived Knowledge planning, also read
`docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`,
`docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`,
`docs/mcp/MCP_V3_VIDEO_WORKFLOW.md`,
`docs/seo/PUBLIC_URL_SLUG_CONTRACT.md`,
`docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md` and
`docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` when lexical
observation is enabled. An explicit validated Video `about` target must remain
the semantic/enrichment target; Video-derived Knowledge output is planning-only
unless separately governed and applied. Dictionary detection must not broaden
that target.

For any workflow that drafts, generates, edits, projects or publishes public
promotional/commercial copy — including WordPress Article text, Product copy,
MediaUsage caption/alt or image/thumbnail text, Video editorial copy, SEO/meta,
Open Graph, structured promotional copy, cards or comparison copy — also read
`docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md` before
implementation or publication. This requirement is cross-cutting; no public
channel may bypass the same claim/evidence compliance policy.

For any Media, Knowledge/note, Article, Video, Media annotation or public
projection work that names a visually explainable technical or recognition
feature, also read `docs/architecture/VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md`.
This ACTIVE contract governs the application-level requirement ledger, exact
Media reuse, bounded reverse reconciliation, public fail-closed rules and the
separation between feature illustration and representative coverage.
