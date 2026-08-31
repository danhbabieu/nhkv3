# NHK V3 Execution State

Last updated: 2026-08-31, canonical projection URL-target checkpoint.

| Field | Current value |
|---|---|
| Workspace | `/Users/imac24-2125d/Developer/nhk-v3` |
| Branch / HEAD | `main` / current local checkpoint |
| Current phase | P11 readiness audit in progress; local-dev P10 apply is checkpointed, live parity gates remain open |
| Last accepted phase | P5 Canonical Domain Foundation |
| DB migration | current 8 / target 8 on `nhk_v3`; Knowledge, Evidence metadata, Migration006/007 and MediaAsset metadata/visibility are UP-only applied; media/video storage ready |
| Tests | Unit suite: 63 tests, 182 assertions; guarded WordPress suite: 95 tests, 392 assertions; plugin/theme PHP lint and diff check pass |
| Blockers | Visual QA (browser connector unavailable), external MCP transport, current 765 URL mappings pending Mapper 6.9 rerun, media delivery/usages, Source/Evidence activation/public provenance policy, semantic projections and 764 domain-targeted posts remain open; V2/live remains read-only |
| Working assumptions | Media/Video routes are registered only when WordPress has a usable `$wpdb`; `nhk_v3_test` is the only destructive integration target; editorial aliases render empty states without creating fixture terms |
| Next executable task | Rerun the restored-backup export/dry-run/apply with Mapper 6.9, verify the 370 explicit active Authority URL targets and resulting ledger, then continue field-level media delivery/usages, Source/Evidence activation/public provenance policy, semantic projection and domain-targeted post reconciliation before visual QA and external MCP transport checks |
| Last parity count | V2 restored read-only inventory: 800 posts, 1,301 entities, 185 relations, 3 media assets with field-level metadata, 19 sources, 40 citation evidence rows and 1,581 semantic projections; local-dev ledger imported 1,642 rows with 3,331 explicit skips, including 34 native-post redirects |
| Pending migrations | None; `nhk_v3` is current 8/target 8 and Migration006 ledger plus Evidence and MediaAsset metadata are active |
| Migration dry-run | Full restored-backup export: 4,973 records; 2,593 candidates and 2,380 skipped; local-dev apply: 1,642 migrated, 3,331 skipped, 0 conflicts |

## Checkpoint journal

- 2026-08-31: Preflight completed. HEAD `2247c87`; existing governance edits
  preserved. Governance documents being bootstrapped.
- 2026-08-31: P4 acceptance completed on `nhk_v3_test`; Migration003 applied
  UP-only to `nhk_v3`; runtime health reported migration 3/3 and Graph,
  Authority, Governance storage ready. P5 is now active.
- 2026-08-31: P4 governance/docs checkpoint committed as `49b6d47` and pushed
  to `origin/main`; P5 catalog/registry implementation is next.
- 2026-08-31: P5 canonical catalog added for nine target types with explicit
  field schemas and validation; unit/integration evidence is 60 tests, 234
  assertions, 0 skipped. P5 is ready to close and P6 is next.
- 2026-08-31: P6 domain contracts and Migration004 added; `P6MigrationIntegrationTest`
  passes on `nhk_v3_test`.
- 2026-08-31: MediaMigration004 applied UP-only to `nhk_v3`; runtime health
  reports migration 4/4 and media/video storage ready. P6 persistence services
  and Graph relations remain the next executable work.
- 2026-08-31: P6 domain/schema checkpoint committed as `51ff8bf` and pushed to
  `origin/main`; P6 remains active for persistence services and shared Graph
  endpoint integration.
- 2026-08-31: The autonomous UI/logic/database/data-parity directive was
  merged into the operating documents. Frontend may proceed in parallel once
  contracts are stable; actual V2 migration remains backup/restore-gated.
- 2026-08-31: P6 persistence slice added for Media/Asset/Usage and Video,
  including optimistic repository updates, idempotent external references and
  Media/Video Graph endpoint resolvers. Focused and all-unit evidence passed;
  WordPress integration is environment-gated by `NHK_WP_TEST_PATH`.
- 2026-08-31: P7 Knowledge Claim, Source and Evidence contracts, UP-only
  Migration005, WPDB repositories, service boundary and shared Graph endpoint
  resolvers were added. Post links use the single `about` Graph predicate and
  never duplicate WordPress editorial body. Unit evidence remains green;
  Migration005 is pending WordPress integration environment.
- 2026-08-31: P9 responsive editorial theme scaffold was expanded on the
  existing user-owned theme files: NHK shell/navigation/search, discovery
  homepage, editorial archive/search, Post, 404 and reusable article cards.
  Warm NHK design tokens, mobile navigation, two-column desktop feed/sidebar,
  accessible labels and empty states are present; browser smoke/visual QA and
  semantic entity routes remain pending.
- 2026-08-31: P8 read API and Admin health surface added. Read endpoints expose
  Media, Video, Knowledge Claim and Source with nested evidence/assets/usages,
  returning 503 until their migration storage is ready. Admin is capability
  protected and intentionally read-only for now; governed proposal mutations
  and MCP remain next.
- 2026-08-31: Governed proposal REST create/submit/approve/reject and unified
  semantic search were added. Search keeps native WordPress Post search and
  groups active Authority, Media, Video and Knowledge results under one API;
  capability checks remain fail-closed for mutation routes.
- 2026-08-31: Canonical entity list/detail REST endpoints were added for the
  nine Authority types with active-only pagination and type-safe 404 handling,
  providing the initial data source for domain-specific frontend pages.
- 2026-08-31: MCP tool catalog and Governance handler were added. Read tools
  are explicitly non-mutating; every mutation tool is marked governed and
  delegates to `GovernanceService` for authorization, idempotency and lifecycle
  policy. External MCP transport wiring remains pending.
- 2026-08-31: Graph read REST routes were wired to all registered endpoint
  resolvers with cursor pagination and public retired-edge suppression. Graph
  reads no longer materialize missing graph nodes. A no-write V2 dry-run CLI
  and reason-code service were added; checksum collisions remain review-only
  duplicate candidates. Checkpoint `27ce072` is pushed to `origin/main`.
- 2026-08-31: Governance REST now exposes capability-protected eligibility and
  Controlled Apply. Authority proposal execution supports create/ingest,
  rename, update, retire and reactivate through the existing transaction,
  revision, idempotency and audit boundaries. Checkpoint `74ed7eb` is pushed to
  `origin/main`; WP integration remains environment-gated.
- 2026-08-31: MCP read adapter now exposes real Authority, Media, Video,
  Knowledge and native WordPress Post query methods, while the mutation bridge
  remains delegated to GovernanceService. A `nhk_mcp_register_tools` hook
  provides a transport-neutral registration seam. Checkpoint `6ea8362` is
  pushed to `origin/main`; external transport is still not fabricated.
- 2026-08-31: Canonical entity frontend routes now cover archive, filtered
  archive pagination and stable-key/UUID detail for all nine Authority types.
  `EntityPageQuery` owns repository access; the theme only presents the
  context, with responsive empty states and semantic facts. Checkpoint
  `dea84fd` is pushed to `origin/main`; runtime route smoke and related Graph,
  media and video modules remain pending.
- 2026-08-31: NHK Admin now provides capability-gated entity/proposal lookup,
  health, proposal state/revision/dependency visibility, eligibility and
  submit/approve/reject/Controlled Apply actions through REST with WP nonce;
  apply attempt history is visible. Checkpoint `59bb952` is pushed to
  `origin/main`; runtime browser smoke remains environment-gated.
- 2026-08-31: Theme SEO hooks now emit canonical, description, OpenGraph,
  BreadcrumbList and Article metadata for editorial/entity surfaces, while
  WordPress remains the sitemap/RSS owner. Checkpoint `4e0252c` is pushed to
  `origin/main`; runtime metadata validation remains environment-gated.
- 2026-08-31: Media/Video public query services and rewrite/template routes
  were added for `/video/`, `/video/{uuid}`, `/thu-vien/`, `/media/` and
  `/media/{uuid}`. Media renders readiness-aware asset metadata and Video
  renders a YouTube privacy embed from its canonical external reference;
  local MP4 copying is not introduced. Unit evidence is 58 tests/155
  assertions; runtime route smoke remains WordPress-environment gated.
- 2026-08-31: Checkpoint `e8c4c27` was pushed with public Media/Video
  templates, route wiring, query-service tests and the source-level frontend
  route inventory. Unit evidence is 58 tests/155 assertions. The guarded full
  WordPress command was attempted with `NHK_WP_TEST_DB=nhk_v3_test` and
  `NHK_WP_TEST_PATH=public`, but local WordPress stopped at a database
  connection error; no V2 migration or production action was performed.
- 2026-08-31: NHK Admin gained a capability-gated governed proposal composer
  for create/ingest/rename/update/retire/reactivate. The form sends only to
  the Governance REST boundary with a WP nonce; it does not write domain
  tables directly. Checkpoint `16ea31a` is pushed; runtime lifecycle smoke is
  still blocked by the local WordPress database connection.
- 2026-08-31: P11 readiness audit started. `CUTOVER_READINESS_REPORT.md`
  records the green local unit/lint gates and the unresolved WordPress DB,
  browser smoke, V2 inventory, backup/restore, URL reconciliation and
  external MCP transport gates. Decision is NOT READY; production cutover was
  not performed.
- 2026-08-31: Cutover Readiness Report checkpoint `86e5838` is pushed to
  `origin/main`. The repository is clean and remains explicitly pre-cutover;
  external/runtime gates are documented rather than inferred as passed.
- 2026-08-31: Governed relation proposals now support Graph create, retire and
  reactivate with endpoint/predicate validation and edge revision checks;
  Controlled Apply records Graph edge IDs and avoids nested transaction commits.
  MCP exposes governed `proposal.apply`; the Admin composer can author relation
  proposals. Checkpoint `9ba07a5` is pushed to `origin/main`.
- 2026-08-31: Homepage data access moved into `NHK_V3_Home_Page_Query`, with
  featured/latest/category/topic modules and a plugin semantic filter for real
  Authority/Media/Video data. Empty storage hides semantic modules. Checkpoint
  `ee09ad4` is pushed; browser smoke remains blocked by the local DB.
- 2026-08-31: Native category aliases now preserve `/tri-thuc/` and
  `/goc-chia-se/` with pagination while keeping WordPress as editorial source;
  Admin semantic lookup now covers Media, Video, Knowledge, Source and Graph
  endpoints. Checkpoint `41cc81a` is pushed; runtime rewrite/REST smoke is
  still gated by the local database connection.
- 2026-08-31: Route/Admin readiness documentation checkpoint `a694a89` and
  state closure `6f65b4a` are pushed to `origin/main`; runtime rewrite/REST
  smoke remains pending until the local WordPress database is available.
- 2026-08-31: Media/Video SEO now has document titles, canonical/OpenGraph,
  breadcrumbs and `VideoObject`; frontend contract tests enforce the
  HomePageQuery boundary and these metadata surfaces. Checkpoint `e9ea590` is
  pushed; unit evidence is 61 tests/170 assertions.
- 2026-08-31: Unified semantic search now has a theme `SearchPageQuery` and
  plugin `SearchSemanticQuery`; native WordPress Post results remain the
  editorial source while active Authority/Media/Video/Knowledge results are
  grouped and linked. Checkpoint `668cb28` is pushed; browser/REST smoke is
  still gated by the local database connection.
- 2026-08-31: Search readiness documentation checkpoint `5601aef` is pushed
  to `origin/main`; the repository remains pre-cutover with all unresolved
  runtime and V2-data gates explicitly recorded.
- 2026-08-31: Read-only frontend route smoke harness was added at
  `tools/frontend-route-smoke.php`; its localhost attempt reported connection
  refused for all expected routes, with no false pass. Checkpoint `eee6ede`
  is pushed; unit evidence remains 62 tests/173 assertions.
- 2026-08-31: P10 dry-run reconciliation now reports source/mapped counts by
  type, skipped reasons, malformed records and explicit conflict review while
  preserving no-write behavior and checksum non-merge semantics. Checkpoint
  `350e189` is pushed; unit evidence is 63 tests/181 assertions.
- 2026-08-31: Local MySQL/MariaDB TCP and Apache runtime were restored for V3;
  the guarded suite passed 88 tests and 351 assertions. A standard local
  WordPress rewrite file and empty-editorial alias handling made core frontend
  smoke pass, including a real `/hello-world/` post route.
- 2026-08-31: The V2 backup was restored into guarded staging with a reviewed
  MariaDB compatibility conversion. The expanded read-only export/dry-run
  produced 4,933 records: 2,180 mapped, 2,753 skipped
  (`INVALID_URL_MAPPING` 799, `UNSUPPORTED_LEGACY_TYPE` 1,954). Temporary V2
  tables were removed, the V3 test snapshot was restored, and no V2 record was
  migrated.
- 2026-08-31: Final route smoke passed 15/15 checks including `/hello-world/`.
  Visual automation remains pending because Playwright has no browser binary
  and the available system Chrome aborts in the headless connector.
- 2026-08-31: Migration006 added a durable source checksum/status ledger and
  `tools/v2-migrate.php` added guarded plan/apply with source offsets. After a
  reviewed normalized V2 restore, the full 4,933-record export was applied to
  local `nhk_v3`: 1,545 migrated, 3,388 explicit skips, 0 conflicts. A second
  run produced the same counts and no duplicate targets. The guarded test DB
  was restored from snapshot and remains free of `nhkv2_*` tables.
- 2026-08-31: MediaAsset persistence was corrected at the repository boundary:
  V3 keeps BIGINT internal Media foreign keys while repositories resolve
  canonical Media UUIDs on write/read. Focused media regression and the
  guarded full suite pass at 90 tests/367 assertions. The final governed
  local-dev apply is 1,548 migrated, 3,385 skipped and 0 conflicts; all three
  V2 MediaAsset rows are present with verified parent IDs. Checkpoint
  `da748fd` is committed locally and this documentation checkpoint is
  `3854448`; production/live migration remains blocked.
- 2026-08-31: The V2 exporter now emits 19 governed Source records and 40
  citation Evidence records, preserving source metadata, citation excerpts,
  endpoint identity and V2 PRIVATE visibility. The local-dev apply reached
  1,607 migrated, 3,366 skipped and 0 conflicts; all 40 Evidence rows join a
  migrated Knowledge claim and Source. Guarded suite is 91 tests/373
  assertions; staging test DB was restored and has no `nhkv2_*` tables.
- 2026-08-31: Evidence metadata persistence was extended with UP-only
  Migration007. Verification state, visibility, excerpt metadata and legacy
  citation IDs now survive the Evidence repository boundary; the 40 local-dev
  rows were idempotently backfilled with 0 conflicts. Guarded suite is 91
  tests/375 assertions and `nhk_v3` reports migration 7/7.
- 2026-08-31: Mapper 6.6 classified the one proven `/tim-kiem/` URL as a
  `READY_NOOP` and recorded the remaining 799 URL candidates as explicit
  `INVALID_URL_MAPPING` skips. The local-dev ledger is now 1,608 migrated,
  3,365 skipped and 0 conflicts; guarded suite is 92 tests/381 assertions.
- 2026-08-31: UP-only Migration008 added MediaAsset visibility and metadata
  persistence. Mapper 6.7 re-exported all three V2 assets with field-level
  metadata and reconciled them to PRIVATE in local development; public Media
  REST/query boundaries suppress those assets. The full guarded suite passes
  93 tests/385 assertions, route smoke passes 17/17, and the local ledger
  remains 1,608 migrated, 3,365 skipped and 0 conflicts.
- 2026-08-31: Mapper 6.8 added governed 301 redirects for 34 `nhk_article`
  source paths to their imported native WordPress posts. The local ledger now
  records 1,642 migrated, 3,331 skipped and 0 conflicts; 35 URL rows are
  migrated (34 redirects plus one safe no-op), 765 URL candidates remain
  explicit `INVALID_URL_MAPPING` skips, and local HTTP verification returned
  301 with the expected native target. Guarded suite is 94 tests/391
  assertions.
- 2026-08-31: Public Knowledge REST now fail-closes inactive PRIVATE Source
  and Claim identities with 404; internal repositories retain private rows for
  governed review. Full guarded suite passes 95 tests/392 assertions, local
  route smoke remains 17/17, and no production/V2 data was changed.
- 2026-08-31: Read-only analysis of the normalized V2 dump found explicit
  `_nhk_projection_source_id` links for 776 projected posts, all resolving to
  canonical entity UUIDs. Mapper 6.9 now emits redirects for the 370 active
  Authority entities with public V3 routes, stores entity aliases in a
  fail-closed WordPress option registry, and classifies Knowledge/no-route
  projections as `DOMAIN_TARGETED`; guarded rerun is pending local DB recovery.
