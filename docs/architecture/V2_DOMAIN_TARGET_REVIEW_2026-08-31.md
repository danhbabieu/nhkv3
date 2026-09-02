# V2 Domain-targeted Post Review — 2026-08-31

> **NON-NORMATIVE.** Đây là V2 read-only evidence. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

This is a read-only reconciliation aid for the 764 V2 `wp_post` records that
the migration runner intentionally did not copy into native V3 editorial
posts. It does not create redirects, migrate bodies, retire records, or
change V2/V3 data.

Source artifact: `/private/tmp/nhk-v3-v2-full-export-url-6.14.json`  
Source SHA-256: `061b2b647407c888de890b3f34bc3be7c80803f3c1e923372de409d278e5deac`

## Read-only classification

| V2 `legacy_type` | Count | V3 semantic boundary | Current disposition | Required decision |
|---|---:|---|---|---|
| `nhk_brand` | 4 | Authority / Brand | Body not copied; identity rows exist in the Authority registry | Verify deterministic legacy-post → stable-key mapping, then decide whether old detail URLs need governed redirects |
| `nhk_model` | 30 | Authority / Model | Body not copied; identity rows exist in the Authority registry | Verify deterministic legacy-post → stable-key mapping, then decide whether old detail URLs need governed redirects |
| `nhk_variant` | 42 | Authority / Variant | Body not copied; identity rows exist in the Authority registry | Verify deterministic legacy-post → stable-key mapping, then decide whether old detail URLs need governed redirects |
| `nhk_movement` | 18 | Authority / Movement | Body not copied; identity rows exist in the Authority registry | Verify deterministic legacy-post → stable-key mapping, then decide whether old detail URLs need governed redirects |
| `nhk_music` | 11 | Authority / Music | Body not copied; identity rows exist in the Authority registry | Verify deterministic legacy-post → stable-key mapping, then decide whether old detail URLs need governed redirects |
| `nhk_component` | 91 | Authority / Component | Body not copied; identity rows exist in the Authority registry | Verify deterministic legacy-post → stable-key mapping, then decide whether old detail URLs need governed redirects |
| `nhk_classification` | 174 | Authority / Classification | Body not copied; identity rows exist in the Authority registry | Verify deterministic legacy-post → stable-key mapping, then decide whether old detail URLs need governed redirects |
| `nhk_knowledge` | 372 | Knowledge | Body not copied; 655 Knowledge claim identities exist in V3 | Verify deterministic legacy-post → claim mapping; publish only through Knowledge readiness/provenance policy or retire |
| `attachment` | 21 | MediaAsset | No binary was inferred or copied | Governed MediaAsset mapping requires source-file recovery and checksum verification; otherwise retire each legacy attachment URL |
| `wp_global_styles` | 1 | None | Non-editorial implementation record | Approve permanent retirement; never create an editorial post or semantic identity |

The first eight rows total 742 domain records. Together with 21 attachments
and one global-styles record they account for all 764 skipped V2 `wp_post`
records. The export contains semantic identity records and WordPress-post
records as separate rows; it does not expose a deterministic
`legacy_post_id` field on the semantic identity rows. Therefore an automatic
name/slug join would be identity-risking and is intentionally not performed.

## Read-only candidate audit — 2026-09-01

The reproducible audit tool
`php tools/v2-domain-target-audit.php /path/to/export.json` compares each
domain post only with canonical records of the same domain type. It emits
candidate evidence, never a migration mapping or redirect. Against the
restored 4,973-record export it found one unique same-domain candidate for all
742 domain posts and no ambiguous candidate:

| Legacy post type | Domain posts | None | One candidate | Ambiguous |
|---|---:|---:|---:|---:|
| `nhk_brand` | 4 | 0 | 4 | 0 |
| `nhk_model` | 30 | 0 | 30 | 0 |
| `nhk_variant` | 42 | 0 | 42 | 0 |
| `nhk_movement` | 18 | 0 | 18 | 0 |
| `nhk_music` | 11 | 0 | 11 | 0 |
| `nhk_component` | 91 | 0 | 91 | 0 |
| `nhk_classification` | 174 | 0 | 174 | 0 |
| `nhk_knowledge` | 372 | 0 | 372 | 0 |
| **Total** | **742** | **0** | **742** | **0** |

This is useful review evidence, not deterministic identity proof. The match is
based on normalized canonical title and/or slug; the export still lacks a
legacy post ID link, revision/provenance binding or governed approval. Every
candidate therefore remains an explicit mapping review item, and no URL/body
or semantic identity was changed.

## Reproducible five-lane classification — 2026-09-01

The read-only classifier
`php tools/v2-domain-post-classify.php /path/to/export.json` processes only
the 764 non-editorial domain-post records, leaving the 36 editorial
`nhk_article`/native post/page records in their separate migration lane.
Against the retained full export it reports 742 `STRUCTURE_REFERENCE`
records, 21 `REQUIRES_REVIEW` attachment records and one `RETIRE`
`wp_global_styles` record. Each item carries a bounded reason code and
`mapping_applied=false`; the tool performs no body import, identity mapping,
redirect creation or data mutation.

Each classified item also emits a machine-readable policy packet: V3 target
boundary, required identity proof, allowed relation shape, migration action and
retirement rule. Authority rows require a legacy-post-to-canonical UUID
binding and governed `about` edges; Knowledge rows require a claim UUID;
attachments require checksum/provenance verification before MediaAsset reuse;
and `wp_global_styles` has no semantic target. This is review evidence, not an
applied mapping or retirement decision.

## Editorial boundary

The 34 `nhk_article` records are handled separately as native editorial posts
when their fields are safe to import. The 742 domain records above are not
editorial body candidates: their domain identity belongs in Authority or
Knowledge, while WordPress `wp_posts` remains the sole source of editorial
body and URL truth. Any proposed redirect from a legacy domain-post URL must
therefore carry an explicit legacy-post → canonical stable-key/claim mapping,
revision/provenance evidence and a governed apply decision.

## Decision checklist

1. Obtain a read-only source that links each legacy domain post ID to its
   canonical Authority stable key or Knowledge claim ID; do not infer identity
   from display name alone.
2. For each linked record, verify active state, revision and provenance before
   proposing a redirect.
3. For unlinked or contradictory records, record a reason-coded retirement or
   explicit skip decision; do not create a generic domain redirect.
4. Treat attachment records separately: recover the original bytes, verify
   MIME/size/SHA-256, then create a governed MediaAsset mapping or retire the
   URL.

Until this checklist is completed, the 764 records remain explicit local-dev
skips and Cutover stays `NOT READY`.
