# NHK V3 Cutover Readiness Report

Date: 2026-08-31
Repository: `main` at the current local checkpoint
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
  read-only V2 inventory and a separate governed local-dev migration ledger
  are recorded; live V2 data has not been mutated.
- The dry-run report now provides per-type counts and skipped-reason buckets,
  rejects malformed records/checksums and marks explicit conflicts for review.
- The local development schema is current at 7/7; Evidence metadata migration
  and the 40-row governed backfill completed with zero conflicts.

## Quality evidence

| Gate | Result |
|---|---|
| Unit tests | PASS — 63 tests, 181 assertions |
| Plugin PHP lint | PASS |
| Theme PHP lint | PASS |
| `git diff --check` | PASS at checkpoints |
| Guarded WordPress integration | PASS — `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test composer test`; 92 tests, 381 assertions |
| Frontend route/rewrite smoke | PASS for core routes and `/hello-world/`; local-dev migration populated Authority/Media/Knowledge detail data |
| Frontend visual QA | PENDING — route HTTP smoke passes, but Playwright has no bundled browser and system Chrome aborts in the headless connector |
| V2 data inventory/counts/mappings | PARTIAL — restored 4,973-record export/dry-run; 2,559 candidates, 2,414 no-write skips; local-dev ledger: 1,608 migrated, 3,365 explicit skips, 0 conflicts, including one safe URL no-op plus 3 MediaAsset, 19 Source and 40 Evidence rows |
| V2 backup restore | PARTIAL — reviewed staging conversion restores the dump and test snapshot; original dump is not MariaDB-portable without conversion, and live field-level reconciliation remains open |

## Blocking gates

1. Complete field-level reconciliation for URLs, media delivery/usages,
   Source/Evidence public visibility, semantic projections and the 764
   domain-targeted custom/system posts; each requires a governed target or a
   documented retirement/skip decision.
2. Review the local-dev ledger counts, verify all imported semantic fields and
   relation semantics, and obtain explicit approval before any live V2
   migration. The versioned normalize/export/apply chain is evidence, not
   production authorization.
3. Run browser visual QA for homepage, Post, entity archives/details, search,
   Media, Video, 404, pagination and desktop/tablet/mobile states using a
   working browser automation runtime;
   populate V3 detail data only through the governed migration path; visual QA
   remains open because the available browser runtime cannot complete headless
   screenshots.
4. Complete external MCP transport/runtime verification and close mandatory
   red rows in `V2_V3_PARITY_MATRIX.md`.

Until every blocking gate is evidenced and the parity matrix is reconciled,
the system must remain pre-cutover. No production data, V2 live system or
production routing was changed during this work.
