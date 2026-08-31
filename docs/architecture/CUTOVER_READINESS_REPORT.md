# NHK V3 Cutover Readiness Report

Date: 2026-08-31  
Repository: `main` at checkpoint `16ea31a`  
Decision: **NOT READY — production cutover is not authorized or performed.**

## What is ready

- WordPress `wp_posts` remains the editorial source of truth.
- Authority, Knowledge, Graph, Governance, Media and external Video boundaries
  are implemented with canonical identity, revision, provenance/readiness and
  fail-closed storage checks.
- Public entity archive/detail routes cover all nine Authority types.
- Public Media and Video archive/detail routes and responsive templates exist;
  Video embeds only validated YouTube external references.
- NHK Admin exposes health, lookup, governed proposal creation and lifecycle
  actions through REST with capability and nonce checks.
- A transport-neutral MCP registration seam exists; read adapters are real and
  mutations delegate to Governance.
- The V2 dry-run tool is no-write and emits bounded reason codes. No V2 data
  has been migrated.

## Quality evidence

| Gate | Result |
|---|---|
| Unit tests | PASS — 58 tests, 155 assertions |
| Plugin PHP lint | PASS |
| Theme PHP lint | PASS |
| `git diff --check` | PASS at checkpoints |
| Guarded WordPress integration | BLOCKED — `NHK_WP_TEST_PATH=public` and `NHK_WP_TEST_DB=nhk_v3_test` bootstrap stopped with “Error establishing a database connection” |
| Frontend browser/rewrite smoke | PENDING — no working local WordPress runtime |
| V2 data inventory/counts/mappings | PENDING — no read-only V2 export/API/database source |

## Blocking gates

1. Restore a working local WordPress/test database and run Migration005 plus
   all mandatory integration tests against the exact guarded database
   `nhk_v3_test`.
2. Complete read-only V2 inventory for posts, categories, attachments/media,
   all Authority types, Knowledge, Sources, Evidence, relations, Videos and
   URLs; feed it to `tools/v2-dry-run.php`.
3. Produce backup, readability and documented restore evidence before any
   real-data mutation. Reconcile counts, identity mappings, relations, media
   state and URL redirects using the migration ledger.
4. Run browser smoke and visual QA for homepage, Post, entity archives/details,
   search, Media, Video, 404, pagination and desktop/tablet/mobile states.
5. Complete external MCP transport/runtime verification and close mandatory
   red rows in `V2_V3_PARITY_MATRIX.md`.

Until every blocking gate is evidenced and the parity matrix is reconciled,
the system must remain pre-cutover. No production data, V2 live system or
production routing was changed during this work.
