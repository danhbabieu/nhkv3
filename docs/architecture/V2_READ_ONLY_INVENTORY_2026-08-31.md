# V2 Read-only Inventory — 2026-08-31

This inventory was collected from the restored local V2 backup in the guarded
database `nhk_v3_test` and is read-only after restore. No V2 source row is
claimed as migrated.

## Source counts

| Source surface | Count | Evidence |
|---|---:|---|
| WordPress posts table rows | 800 | `nhkv2_posts` exact `COUNT(*)` |
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

The read-only exporter is `tools/v2-read-only-export.php`; it selects only
identity, status, type and route fields and never bootstraps V2 WordPress.
Piped through `tools/v2-dry-run.php`, the report was:

| Dry-run result | Count |
|---|---:|
| Source records | 3,086 |
| Mapped candidates | 1,917 |
| Skipped candidates | 1,169 |
| URL mappings ready | 1 |
| Conflicts | 0 |
| Invalid relations | 0 |

Skipped reason buckets were `INVALID_URL_MAPPING` 799 and
`UNSUPPORTED_LEGACY_TYPE` 370. These are no-write
reconciliation results, not approval to apply them.

## Required follow-up

Posts/categories, all Authority types, media state, external videos,
relations, semantic projections and URL redirects still require field-level
mapping review against V3 contracts. The exporter intentionally does not
convert legacy custom post types into editorial body projections.
