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
- Public REST and MCP detail reads now suppress retired Authority, Media and
  Video records even when a caller knows their UUID.
- NHK Admin exposes health, lookup, governed proposal creation and lifecycle
  actions, including Graph relation proposals, through REST with capability and
  nonce checks.
- MCP exposes governed eligibility and Controlled Apply handlers in addition to
  proposal lifecycle operations.
- Native editorial aliases preserve `/tri-thuc/` and `/goc-chia-se/` route
  contracts while continuing to query WordPress categories/posts.
- V2 search `/tim-kiem/?q=...` now redirects with its query preserved to the
  native WordPress search parameter `/?s=...`.
- The `/comparison/` discovery surface is now a real read-only comparison
  route over two active canonical Authority references; it does not create a
  duplicate comparison data model.
- Comparison pages now emit dedicated document title, canonical, description
  and breadcrumb metadata instead of inheriting the site default.
- V2 archive aliases `/thuong-hieu/`, `/hien-vat/` and `/am-nhac/` now resolve
  to canonical V3 Authority archive contexts while emitting canonical V3 links
  and metadata.
- V2 detail slugs now have a fail-closed compatibility resolver: a unique active
  Brand slug redirects to `/brand/{stable-key}/`, and a unique active
  Brand/Model pair redirects to `/model/{stable-key}/`; native WordPress
  content and ambiguous names are never overridden.
- Post single pages now consume Graph-derived related entities, articles, Media
  and Video through an application query boundary; empty or unavailable
  related groups are omitted without changing the editorial body.
- A transport-neutral MCP registration seam exists; read adapters are real and
  mutations delegate to Governance.
- A local Streamable HTTP MCP endpoint exposes protocol tool schemas, validates
  modern request metadata and Origin, and rejects governed calls without the
  matching WordPress capability; external client/deployment interoperability
  is still a separate gate.
- The V2 dry-run tool is no-write and emits bounded reason codes. A local
  read-only V2 inventory and a separate governed local-dev migration ledger
  are recorded; live V2 data has not been mutated.
- The dry-run report now provides per-type counts and skipped-reason buckets,
  rejects malformed records/checksums and marks explicit conflicts for review.
- The local development schema is current at 8/8; Evidence and MediaAsset
  metadata migrations and their governed backfills completed with zero
  conflicts. V2 PRIVATE media assets remain suppressed by public reads; the
  public asset route is fail-closed on visibility, MIME, storage-root,
  checksum and byte-size checks.
- Public Knowledge reads now fail closed for inactive PRIVATE Source and Claim
  identities; the activation/public provenance policy remains a cutover gate.

## Quality evidence

| Gate | Result |
|---|---|
| Unit tests | PASS — 77 tests, 237 assertions |
| Plugin PHP lint | PASS |
| Theme PHP lint | PASS |
| `git diff --check` | PASS at checkpoints |
| Guarded WordPress integration | PASS — `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test composer test`; 113 tests, 462 assertions |
| Frontend route/rewrite smoke | PASS 20/20 for core routes, V2 archive aliases, `/comparison/`, `/hello-world/`, Knowledge archive/detail and unknown MediaAsset 404; local HTTP also verified V2 detail 301 redirects, query-preserving search redirect and comparison title/canonical metadata |
| REST/MCP runtime boundary | PASS — active Entity/Media/Knowledge/Search reads returned 200, invalid entity routes returned 404, unauthenticated Governance mutations/eligibility returned 401, local MCP `tools/list` returned 200 with 11 protocol definitions, unauthenticated governed MCP call returned 403 and invalid Origin returned 403; external interoperability/deployment remains pending |
| Frontend visual QA | PARTIAL — desktop homepage, Post single, Search, Comparison, active Media detail/archive, Video empty state, Authority archive/detail and 404 were visually inspected; tablet/mobile responsive coverage and active Video detail remain pending |
| V2 data inventory/counts/mappings | PARTIAL — restored 4,973-record export/dry-run; 2,379 candidates, 2,594 no-write skips with buckets matching apply; local-dev ledger: 2,379 migrated, 2,594 explicit skips, 0 conflicts, including 367 Knowledge claim redirects, 370 entity-registry redirects, 34 native-post URL redirects, one safe URL no-op, 3 field-level PRIVATE MediaAsset rows, 19 Source and 40 Evidence rows |
| V2 backup restore | PARTIAL — reviewed staging conversion restores the dump and test snapshot; original dump is not MariaDB-portable without conversion, and live field-level reconciliation remains open |

## Blocking gates

1. Complete field-level reconciliation and final retirement/target approval using `V2_URL_RECONCILIATION_REVIEW_2026-08-31.md` for the 28 residual URLs (now explicitly classified as 5
   `DOMAIN_TARGETED`, 21 `UNSUPPORTED_MEDIA_REFERENCE` and 2
   `RETIRED_LEGACY_GARBAGE`), MediaAsset
   delivery/privacy policy,
   Source/Evidence public visibility, semantic projections and the 764
   domain-targeted custom/system posts; each requires a governed target or a
   documented retirement/skip decision.
2. Review the local-dev ledger counts, verify all imported semantic fields and
   relation semantics, and obtain explicit approval before any live V2
   migration. The versioned normalize/export/apply chain is evidence, not
   production authorization.
3. Complete browser visual QA for homepage, Post, entity archives/details,
   search, Media, Video, 404, pagination and tablet/mobile states; desktop
   homepage, Knowledge, Authority and 404 surfaces are already inspected.
   populate V3 detail data only through the governed migration path; visual QA
   remains open because the available browser runtime cannot complete headless
   screenshots.
4. Complete external MCP client/deployment interoperability verification and close mandatory
   red rows in `V2_V3_PARITY_MATRIX.md`.

Until every blocking gate is evidenced and the parity matrix is reconciled,
the system must remain pre-cutover. No production data, V2 live system or
production routing was changed during this work.
