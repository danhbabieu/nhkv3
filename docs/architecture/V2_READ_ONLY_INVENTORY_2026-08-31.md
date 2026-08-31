# V2 Read-only Inventory — 2026-08-31

This inventory was collected from the restored local V2 backup in the guarded
database `nhk_v3_test`. The source database was read-only during export. A
separate local-dev apply was later run against `nhk_v3`; it is recorded in the
migration ledger and is not a production parity or cutover claim.

## Source counts

| Source surface | Count | Evidence |
|---|---:|---|
| WordPress posts table rows | 800 | `nhkv2_posts` exact `COUNT(*)` |
| Published `nhk_*` editorial/entity post rows | 776 | `nhkv2_posts` grouped by post type/status; one published page and one system style row are separate |
| Native taxonomy rows | 2 | `nhkv2_terms`/`nhkv2_term_taxonomy` |
| Canonical/legacy entity rows | 1,301 | `nhkv2_nhk_entities` exact `COUNT(*)` |
| Graph relation rows | 185 | `nhkv2_nhk_relations` exact `COUNT(*)` |
| Media asset rows | 3 | `nhkv2_nhk_media_assets` |
| Media usage rows | 0 | `nhkv2_nhk_media_usage` |
| Knowledge facts | 0 | `nhkv2_nhk_knowledge_facts` |
| Knowledge evidence | 19 | `nhkv2_nhk_knowledge_evidence` |
| Knowledge citations | 40 | `nhkv2_nhk_knowledge_citations` |
| Knowledge relations | 242 | `nhkv2_nhk_knowledge_relations` |
| Semantic projections | 1,581 | `nhkv2_nhk_semantic_projections` |
| Visual video rows | 0 | `nhkv2_nhk_visual_videos` |

Entity types present are `brand` (4), `model` (30), `variant` (42),
`movement` (18), `music` (11), `component` (91), `classification` (174),
`media` (242), `knowledge` (655) and legacy `article` (34). The current V3
canonical registry has direct contracts for the nine Authority types and
first-class Media/Knowledge, but the 34 legacy editorial entities still need
native `wp_posts`/category mapping rather than a body projection.

## No-write dry-run

The read-only exporter is `tools/v2-read-only-export.php`; it selects bounded
identity, status, type, route and field-level media metadata and never
bootstraps V2 WordPress.
Piped through `tools/v2-dry-run.php` from the full normalized backup, the
report was:

| Dry-run result | Count |
|---|---:|
| Source records | 4,973 |
| Mapped candidates | 3,330 |
| Skipped candidates | 1,643 |
| URL mappings ready | 772 |
| Conflicts | 0 |
| Invalid relations | 0 |

Skipped reason buckets were `DOMAIN_TARGETED` 5,
`UNSUPPORTED_MEDIA_REFERENCE` 21, `RETIRED_LEGACY_GARBAGE` 1,
`INVALID_URL_MAPPING` 1 and `UNSUPPORTED_LEGACY_TYPE` 1,615. These are no-write
reconciliation results, not approval to apply them.

## Local development apply checkpoint

The governed `tools/v2-migrate.php` runner applied the Mapper 6.12 4,973-record
export to `nhk_v3` after the backup/restore gate. The ledger contains 2,379
migrated records and 2,594 skipped records: `DOMAIN_TARGETED` 769,
`INVALID_RELATION` 1, `INVALID_URL_MAPPING` 1,
`RETIRED_LEGACY_GARBAGE` 1, `UNSUPPORTED_MEDIA_REFERENCE` 21 and
`UNSUPPORTED_LEGACY_TYPE` 1,682; conflicts were 0. The one proven identical
URL candidate is recorded as a `READY_NOOP`; 34 `nhk_article` source paths are
stored as native postmeta aliases, 370 Authority projection paths and 367
Knowledge claim paths are stored in the entity redirect registry, all verified
through local HTTP 301 redirect behavior; one canonical entity target returned
HTTP 200.
The three V2 MediaAsset rows were imported with checksum, MIME, dimensions,
field-level metadata and PRIVATE visibility; the local public API/query
boundary therefore returns no asset delivery for those rows. Public Knowledge
REST also returns 404 for inactive PRIVATE Source/Claim identities. Nineteen Source rows and 40 citation Evidence
rows were also imported with their V2 PRIVATE state, verification state and
citation metadata preserved; runtime MediaAsset delivery/privacy and public
provenance presentation remain open reconciliation work. The V2 media usage
inventory is exactly zero, so no usage rows require migration.
Read-only projection metadata analysis found 776 `_nhk_projection_source_id`
links, all matching canonical entity UUIDs: 370 active Authority entities,
292 active Knowledge claims and 80 archived Knowledge claims. Mapper 6.12 now
exports the 370 Authority links, 292 active Knowledge links and 75 archived
Knowledge links with active consolidation targets as deterministic canonical
route targets; the remaining 5 archived/no-target Knowledge links are recorded
as `DOMAIN_TARGETED`. The Mapper 6.12 apply rerun was idempotent with the same
2,379/2,594/0 counts, and the
current ledger counts above are the accepted local-dev checkpoint.
Subsequent runs were idempotent after the 40-row Evidence metadata backfill,
the safe URL no-op classification, the 34 native-post redirect aliases and
the three-row MediaAsset metadata reconciliation.
Target verification found
36 native WordPress posts, 4/30/42/18/11/91/174 Authority rows, 242 Media
rows, 3 MediaAsset rows, 655 Knowledge claims, 19 Sources, 40 Evidence rows
and 241 Graph edges imported from the ledger.
This checkpoint is reversible in local development from
`/private/tmp/nhk_v3-before-v2-apply.sql`; it does not authorize live V2 or
production mutation.

## Required follow-up

URL redirects not covered by the new explicit Authority links, MediaAsset delivery/privacy, external videos without a supported
reference and semantic projections remain explicitly unmigrated or require a
governed target mapping. Source/Evidence rows are stored with private state
until their public visibility/provenance policy is reviewed. The exporter and
runner intentionally do not convert legacy custom post types into editorial
body projections or merge identities by name.
