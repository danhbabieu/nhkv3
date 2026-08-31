# V2 Domain-targeted Post Review — 2026-08-31

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
