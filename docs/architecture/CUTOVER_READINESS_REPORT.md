# NHK V3 Cutover Readiness Report

Date: 2026-08-31
Repository: `main` at checkpoint `3e3a914`
Decision: **NOT READY — production cutover is not authorized or performed.**

## What is ready

- WordPress `wp_posts` remains the editorial source of truth.
- Authority, Knowledge, Graph, Governance, Media and external Video boundaries
  are implemented with canonical identity, revision, provenance/readiness and
  fail-closed storage checks.
- Public entity archive/detail routes cover all nine Authority types.
- Public Media and Video archive/detail routes and responsive templates exist;
  Video embeds only validated YouTube external references.
- Homepage discovery is query-driven: featured/latest/category/topic modules
  use WordPress services, while Authority/Media/Video modules come from the
  plugin semantic query boundary and disappear when storage is unavailable.
- Search now has a theme query boundary that combines native WordPress Post
  results with grouped active semantic results from Authority, Media, Video and
  Knowledge repositories.
- NHK Admin exposes health, lookup, governed proposal creation and lifecycle
  actions, including Graph relation proposals, through REST with capability and
  nonce checks.
- MCP exposes governed eligibility and Controlled Apply handlers in addition to
  proposal lifecycle operations.
- Native editorial aliases preserve `/tri-thuc/` and `/goc-chia-se/` route
  contracts while continuing to query WordPress categories/posts.
- A transport-neutral MCP registration seam exists; read adapters are real and
  mutations delegate to Governance.
- The V2 dry-run tool is no-write and emits bounded reason codes. A local
  read-only V2 inventory is recorded; no V2 data has been migrated.
- The dry-run report now provides per-type counts and skipped-reason buckets,
  rejects malformed records/checksums and marks explicit conflicts for review.

## Quality evidence

| Gate | Result |
|---|---|
| Unit tests | PASS — 63 tests, 181 assertions |
| Plugin PHP lint | PASS |
| Theme PHP lint | PASS |
| `git diff --check` | PASS at checkpoints |
| Guarded WordPress integration | PASS — `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test composer test`; 88 tests, 351 assertions |
| Frontend route/rewrite smoke | PASS for core routes and `/hello-world/`; no active V3 Authority detail rows yet |
| V2 data inventory/counts/mappings | PARTIAL — restored 3,086-record read-only export/dry-run; 1,917 mapped, 1,169 skipped with reason codes |
| V2 backup restore | PARTIAL — reviewed staging conversion restores the dump; original dump is not MariaDB-portable without that conversion, and field-level migration evidence remains open |

## Blocking gates

1. Complete field-level read-only V2 inventory for posts, categories, attachments/media,
   all Authority types, Knowledge, Sources, Evidence, relations, Videos and
   URLs; feed it to `tools/v2-dry-run.php`.
2. Promote the reviewed V2 restore conversion into a versioned, reproducible
   migration input, then reconcile counts, identity mappings, relations, media
   state and URL redirects using the migration ledger.
3. Run browser smoke and visual QA for homepage, Post, entity archives/details,
   search, Media, Video, 404, pagination and desktop/tablet/mobile states;
   populate V3 detail data only through the governed migration path.
4. Complete external MCP transport/runtime verification and close mandatory
   red rows in `V2_V3_PARITY_MATRIX.md`.

Until every blocking gate is evidenced and the parity matrix is reconciled,
the system must remain pre-cutover. No production data, V2 live system or
production routing was changed during this work.
