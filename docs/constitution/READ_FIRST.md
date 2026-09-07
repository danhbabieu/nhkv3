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
| Dictionary / lexical curation / auto-link | `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` plus the owning Article, Knowledge, Media/Image, Video, public-route and SEO contracts for the content being detected or linked |
| Media / Image | `docs/architecture/04_MEDIA_MODEL.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`, relevant Media contracts and runtime registries |
| Video | `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`, `docs/architecture/VIDEO_HUB_CLASSIFICATION_CONTRACT.md`, `docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md`, `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`, `docs/seo/PUBLIC_URL_SLUG_CONTRACT.md`, `docs/mcp/MCP_V3_VIDEO_WORKFLOW.md` |
| Knowledge / Claim / Source / Evidence | `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`, `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`, `docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md` when public copy is involved |
| Authority / Brand / Model / Variant / Movement / Music / Classification / Specimen / Product | `docs/architecture/02_AUTHORITY_BOUNDARY.md`, `docs/architecture/13_AUTHORITY_CORE_CONTRACT.md`, `docs/architecture/V3_BRAND_RELATIONSHIP_MATRIX.md`, `docs/architecture/PUBLIC_BRAND_NAMING_CONTRACT.md`, `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md` when public Entity detail aggregation/display is in scope |
| Graph / relation / Governance / retry | `docs/architecture/11_GRAPH_CORE_CONTRACT.md`, `docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md`, `docs/architecture/18_GOVERNANCE_FAILURE_AND_RETRY.md`, current `PredicateRegistry`, proposal repository, MCP catalog and fresh runtime read-back |
| Public Entity dossier / relation display | `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`, `docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md`, the owning Authority/Graph/Knowledge/Media/Video/Article contracts, and the applicable entity relationship matrix; reachable graph context is not automatically inherited truth |
| Public identity / route / SEO | `docs/seo/PUBLIC_URL_SLUG_CONTRACT.md`, `docs/architecture/V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md`, `docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md`, `docs/architecture/V3_FRONTEND_ROUTE_INVENTORY.md`, relevant SEO contracts, persisted-identity design/spec and the current PublicIdentity implementation/runtime evidence when in scope |
| MCP / Admin | current contract: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`; current tool/Ability availability must be checked against executable catalog/registration and fresh runtime discovery. `docs/mcp/MCP_V3_ABILITY_EXPOSURE.md` is historical/superseded evidence only |
| Deferred / unfinished / runtime research | `docs/architecture/V3_EXECUTION_STATE.md`, `docs/architecture/GRAPH_DATA_AUDIT_2026-09-07.json` and the current unresolved/research ledgers. Treat dated counters as snapshots and apply the latest resolved/current-status override before acting. |
| Storage / schema / execution | current boundaries from the relevant domain contracts plus `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`; `docs/architecture/21_P5_CANONICAL_DOMAIN_FOUNDATION.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/architecture/V3_EXECUTION_STATE.md` and `docs/architecture/V2_V3_PARITY_MATRIX.md` contain implementation/history evidence and must be interpreted by date/context |

## Mandatory semantic reconciliation before create

For any new Authority node, Knowledge claim, Source/Evidence chain or semantic
relation intent, do not begin from a create command. First read the current
canonical owners and reconcile the new material against them.

The decision must be one of:

- `EXACT_EXISTING` — reuse/update/enrich the existing canonical record;
- `MERGE_CANDIDATE` — use the governed merge/rekey/update boundary after
  identity review;
- `RELATED_BUT_DISTINCT` — preserve both identities and create only a
  registry-valid relation when justified;
- `NO_EXISTING_CANONICAL_RECORD` — creation is allowed only after this outcome
  is supported by current canonical research;
- `UNCERTAIN` — fail closed into the deferred/research ledger.

Lexical similarity, fuzzy matching, keyword search, display-name similarity,
filename similarity and AI memory are discovery aids only. They are never
canonical identity proof.

A Knowledge claim must not be deliberately created as an orphan to be attached
later. Resolve the intended canonical subject/context first. If a missing
Authority node is genuinely necessary, run its governed create flow through
canonical read-back before creating the claim. If the subject cannot be
resolved or safely created, defer the claim rather than minting detached
semantic truth.

## Current semantic mutation and completion rule

The current semantic write capabilities include Authority operations,
Knowledge, Source, Evidence, Media, Video, Graph relation and Governance
proposal operations where exposed by the executable catalog. Presence of a
writer does not mean a proposal is already canonical.

The standard lifecycle is:

`proposal_create / ingest → submit → review → approval with binding fingerprints
→ eligibility → Controlled Apply → canonical owner read-back → idempotency
verification`.

`DRAFT`, `SUBMITTED`, `APPROVED`, `ready=true` or an HTTP success response is
not `COMPLETED`. Completion requires the owning canonical repository/query to
read back the intended mutation. A second identical run must not create a
second Authority node, Knowledge claim, Source, Evidence, active Graph edge,
Video, Media or proposal for the same durable intent.

For `relation_create`, use the real typed endpoints: `source_type` plus
`source_uuid`, registered `predicate`, and `target_type` plus `target_uuid`.
The historical relation-proposal hydration defect that substituted an entity
kind for the source UUID is resolved in the current repository boundary; do not
carry that historical defect forward as a global Graph blocker. Create
proposals for a not-yet-existing Authority node are a different case: before
creation there is no canonical node UUID, so an entity-type subject marker is
not by itself evidence of the old relation bug.

The executable predicate registry currently includes `about`, `depicts`,
`model_of`, `variant_of`, `uses_movement`, `supports_music`,
`configured_with_music` and `observed_playing_music`. `classified_as` is not
registered, and Product–Specimen still has no dedicated approved persistence
relation. Do not use broad `about` as a substitute for classification
membership, configuration, structural parentage, movement use or any other
missing predicate.

## Current Video relation orchestration

`nhk.video.ingest` remains the canonical external Video intake surface. Generic
Video-derived Knowledge extraction can remain a planning seam, but the current
guided Video relation workflow is more capable than the older planning-only
checkpoint: after a canonical Video and target are resolved, the application
can resolve/reuse or deterministically create the private canonical YouTube
Source, provenance Claim and Evidence required by the relation workflow, read
them back, and then create the governed relation proposal. Normal operators do
not manually copy a Video proposal UUID or Evidence UUID into the form.

The canonical relation still follows the Governance lifecycle and Graph
read-back. Replay of the same YouTube external ID reuses the same canonical
Video; thumbnail Media, Source/Evidence, Knowledge, Article and Video remain
separate identities/bounded contexts.

## Notes and editorial workspace context

No separate canonical semantic `Note` owner is established by the current
registry/contracts. Editorial notes, research notes, draft annotations and
workspace context remain editorial/planning input. If a note contains a fact
worth promoting, that fact must re-enter the normal canonical subject
resolution, reconcile, Source/Evidence/Knowledge and Governance lifecycle before
it can become semantic truth.

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
“No Article Ability” must not be used as current capability truth. Likewise,
historical statements that semantic write surfaces, governed relation apply,
relation source binding or Graph canonical read-back are unavailable must be
checked against the current executable/runtime status before being treated as a
blocker.

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
the semantic/enrichment target. Generic extraction remains planning-only until
its own governed mutation runs, while the guided relation workflow may
resolve/reuse/create the canonical provenance chain required for that relation.
Dictionary detection must not broaden the target.

For any workflow that drafts, generates, edits, projects or publishes public
promotional/commercial copy — including WordPress Article text, Product copy,
MediaUsage caption/alt or image/thumbnail text, Video editorial copy, SEO/meta,
Open Graph, structured promotional copy, cards or comparison copy — also read
`docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md` before
implementation or publication. This requirement is cross-cutting; no public
channel may bypass the same claim/evidence compliance policy.
