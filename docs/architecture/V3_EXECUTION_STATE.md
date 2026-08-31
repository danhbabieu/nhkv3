# NHK V3 Execution State

Last updated: 2026-08-31, P11 responsive entity-route QA checkpoint.

| Field | Current value |
|---|---|
| Workspace | `/Users/imac24-2125d/Developer/nhk-v3` |
| Branch / HEAD | `main` / current local checkpoint |
| Current phase | P11 readiness audit in progress; local-dev P10 apply is checkpointed, live parity gates remain open |
| Last accepted phase | P5 Canonical Domain Foundation |
| DB migration | current 9 / target 9 on `nhk_v3`; Knowledge, Evidence metadata, Migration006/007, MediaAsset metadata/visibility and ProjectionContext009 are UP-only applied; media/video storage ready |
| Tests | Unit suite: 95 tests, 542 assertions; guarded WordPress integration: 44 tests, 352 assertions; combined current suite: 139 tests, 894 assertions; plugin/theme PHP lint, route smoke 29/29 plus two data-gated detail redirects and diff check pass |
| Blockers | Remaining route-specific screenshot QA and an active Video detail, external MCP interoperability/deployment verification, final retirement/target approval for 27 explicitly classified URL candidates (the 5 domain-targeted records now have exact but archived/non-public Knowledge identity matches, while 21 are unsupported media references and 1 is retired legacy garbage), MediaAsset publication/privacy policy and recovery of the three missing V2 source files, Source/Evidence activation/public provenance policy and 764 domain-targeted posts remain open; V2/live remains read-only |
| Working assumptions | Media/Video routes are registered only when WordPress has a usable `$wpdb`; `nhk_v3_test` is the only destructive integration target; editorial aliases render empty states without creating fixture terms |
| Next executable task | Use `V2_URL_RECONCILIATION_REVIEW_2026-08-31.md` and `V2_DOMAIN_TARGET_REVIEW_2026-08-31.md` to obtain governed retirement/target decisions for the 27 residual URLs and deterministic mappings for the 764 skipped domain records, then continue MediaAsset delivery/privacy policy, Source/Evidence activation/public provenance policy, active-Video QA and external MCP interoperability checks |
| Last parity count | V2 restored read-only inventory: 800 posts, 1,301 entities, 185 relations, 3 media assets with field-level metadata, 19 sources, 40 citation evidence rows and 1,581 semantic projections; latest local-dev apply migrated 3,961 rows and skipped 1,012 with 0 conflicts, including 1,581 non-canonical projection contexts, 367 Knowledge, 370 Authority and 34 native-post redirects |
| Pending migrations | None; `nhk_v3` is current 9/target 9 and Migration006 ledger, Evidence/MediaAsset metadata and ProjectionContext009 are active |
| Migration dry-run | Baseline full restored-backup export: 4,973 records, 3,960 candidates and 1,013 skipped; policy-normalized rerun classifies native homepage `/` as `READY_NOOP`, yielding 3,961 mapped and 1,012 skipped with 0 conflicts; projection contexts account for 1,581 mapped records |

## Checkpoint journal

- 2026-09-01: MCP semantic Knowledge search now applies the same public
  readiness gate as REST, theme archive/detail and other semantic search
  paths; explicit unverified/non-public claims no longer appear in paginated
  MCP groups or totals. The regression covers page-two slicing with six
  public and one unverified match. Guarded PHPUnit passed 139 tests/894
  assertions, route smoke remained 29/29, lint/diff-check and secret review
  passed, and no database state changed.

- 2026-09-01: Knowledge claim public readiness is now enforced across archive,
  detail, REST, semantic search and MCP public reads: explicit
  `UNVERIFIED`, `NEEDS_CONFIRMATION`, `PRIVATE` and `HIDDEN` provenance states
  are suppressed, while claims without an explicit status retain the V3
  default. Local read-only counts show 527 active repository claims but only
  66 with verified V2 metadata; browser archive runtime shows no unverified
  status leakage. Guarded PHPUnit passed 139 tests/892 assertions, route smoke
  remained 29/29, lint/diff-check and secret review passed, and no database
  state changed.

- 2026-08-31: Public Knowledge claim payloads now omit the persisted
  provenance blob in addition to Source/Evidence metadata blobs. Reader-facing
  claim text/type and approved source title/type/locator/excerpt remain
  available, while legacy status, source and verification internals stay in
  governed MCP/internal reads. Guarded runtime and contract coverage passed
  138 tests/887 assertions with no warnings; route smoke remained 29/29,
  lint/diff-check and secret review passed, and no database state changed.

- 2026-08-31: Guarded runtime integration now asserts that public Source
  responses and nested public Evidence responses omit persisted metadata blobs,
  while the authenticated MCP ingest/read lifecycle remains intact for
  internal governance. The full guarded suite passed 138 tests/884 assertions;
  no database state changed beyond disposable `nhk_v3_test` fixtures.

- 2026-08-31: Public Knowledge payloads now omit persisted Source/Evidence
  metadata blobs, which may contain legacy IDs, verification internals or
  visibility controls; reader-facing title/type/locator/excerpt fields remain
  available, while MCP/internal repositories retain the full metadata for
  governed review. Contract and query tests cover the boundary. Guarded
  PHPUnit passed 138 tests/882 assertions with no warnings, route smoke
  remained 29/29, lint/diff-check and secret review passed, and no database
  state changed. Public provenance approval remains a cutover gate.

- 2026-08-31: The public REST Media serializer now omits internal usage
  endpoint type/key values while retaining only the usage identity, reader-
  relevant role and ordering. A focused contract prevents Graph endpoint
  identifiers from crossing the public boundary; MCP/internal application
  serializers remain unchanged. Guarded PHPUnit passed 137 tests/879
  assertions, composer lint and diff checks passed, and no database state
  changed. Source/Evidence public provenance policy remains intentionally
  open and was not bypassed.

- 2026-08-31: Source/Evidence public reads now fail closed when an active
  record carries explicit `visibility=PRIVATE` (or any non-PUBLIC value) in
  its persisted metadata. The same gate is applied to Knowledge evidence
  filtering, Source REST detail and MCP Knowledge reads; records without a
  visibility field retain the existing V3-compatible public default. Unit
  and guarded integration coverage passed 137 tests/879 assertions, route
  smoke remained 29/29, and no database state changed. This safety gate does
  not approve the outstanding public provenance policy.

- 2026-08-31: Browser QA extended the mobile route evidence to nine remaining
  archive/detail/alias surfaces (`brand`, `model`, `music`, `component`,
  `specimen`, `product`, `/hien-vat/`, `/am-nhac/` and Góc chia sẻ page 2).
  At 390px each had document width equal to the viewport, a bounded main
  column and no detected internal public terminology; populated Brand/Model
  details also received visual inspection. A read-only local database check
  found no active Video row, so active Video detail QA remains an evidence
  blocker and no fixture was created. No database state changed.

- 2026-08-31: The public REST Media serializer now returns only reader-safe
  asset fields (id, kind, MIME, dimensions and size), omitting storage keys,
  checksums, visibility state and internal metadata. The focused contract
  covers the asset boundary; guarded PHPUnit passed 136 tests/877 assertions,
  route smoke passed 29/29 and composer lint/diff checks passed. No database
  state changed.

- 2026-08-31: Media detail public rendering no longer exposes internal
  `storage_key` or operational labels; it now presents reader-facing resource,
  profile-code, display-status and usage labels while retaining fail-closed
  asset delivery. Runtime media detail verification confirmed the sensitive
  storage key is absent and the honest empty state remains visible. Guarded
  PHPUnit passed 135 tests/873 assertions, route smoke passed 29/29 and
  composer lint/diff checks passed. No database state changed.

- 2026-08-31: The reader-facing field-label contract was extended to
  `model_uuid`, `brand_uuid` and `serial_number`, preventing technical UUID
  keys from leaking into populated Model/Specimen pages. Guarded PHPUnit
  passed 135 tests/841 assertions, route smoke passed 29/29, and composer
  lint/diff checks passed. No database state changed.

- 2026-08-31: Public entity field labels now translate Product/Specimen
  payload keys such as `specimen_uuid`, `vendor`, `price`, `url` and
  `availability` into reader-facing Vietnamese labels without changing
  payload identity or values. The frontend contract covers the mapping;
  guarded PHPUnit passed 135 tests/838 assertions, route smoke passed 29/29,
  and theme lint/diff checks passed. No database state changed.

- 2026-08-31: A post-fix browser sweep covered 13 known public routes at
  390px and 768px (26 route/viewport checks), including homepage, editorial
  archives, Authority page-two routes, Knowledge/Media/Video page-two,
  Comparison, Product/Specimen empty states and 404. Every document/body/main
  width matched its viewport; paginated archives retained `noindex,follow`
  and 404 retained `index,follow`. No new responsive defect was found.

- 2026-08-31: Guarded PHPUnit was rerun with the required external local
  network access using `NHK_WP_TEST_DB=nhk_v3_test` and
  `NHK_WP_TEST_PATH=public`: 135 tests and 833 assertions passed. The earlier
  connection failure was sandbox TCP isolation rather than a MySQL/data
  regression; no V2 or development database was reset.

- 2026-08-31: Responsive browser QA found horizontal overflow from the
  unwrapped `.entity-pagination` rule in the later-loaded `entity.css`:
  Knowledge page 2 reached 1,057px and Media page 2 reached 447px at 390px.
  The owning stylesheet now wraps and bounds pagination, and its enqueue
  version was bumped to `1.0.2` for cache invalidation. Browser recheck at
  390px and 768px reports document widths equal to the viewport for both
  routes; mobile screenshots were visually inspected. Focused frontend
  contract is green at 15 tests/233 assertions. No data changed.

- 2026-08-31: The route smoke harness gained explicit data-gated
  `--brand-alias=/legacy/|/canonical/` and
  `--model-alias=/legacy/|/canonical/` redirect checks. Against the local
  WordPress runtime, `/odo/` → `/brand/nhk:brand:o-do/` and
  `/odo/odo-39/` → `/model/nhk:model:o-do.39/` both returned HTTP 301; the
  existing 29 default routes remained green. No fixture or database state
  changed.

- 2026-08-31: The focused frontend contract passed at 14 tests/230
  assertions, PHP lint passed and the expanded runtime smoke passed 31/31.
  A guarded full-suite rerun was attempted after a local MySQL service
  restart, but MySQL exited again before WordPress could connect over
  `127.0.0.1:3306`; the prior accepted 134-test/827-assertion result remains
  the latest complete suite evidence, and this infrastructure gap is kept
  open for the next checkpoint.

- 2026-08-31: Cutover readiness and master-plan evidence were synchronized
  with the current guarded suite (89 unit tests/461 assertions; 133 combined
  tests/808 assertions) and the policy-normalized migration checkpoint
  (3,961 mapped, 1,012 skipped, 0 conflicts, 27 residual URL candidates).
  No implementation or database state changed in this documentation-only
  checkpoint; the repository remains pre-cutover.

- 2026-08-31: Media, Video and Knowledge archive templates now render bounded
  page links from their query-service totals, covering the semantic archive
  pagination contract without introducing a second data source. The focused
  frontend contract is green at 13 tests/211 assertions; full suite evidence
  is 133 tests/808 assertions. Local route smoke was retried after an Apache
  graceful restart but localhost:80 still had no listener, so prior 21/21
  runtime evidence remains the latest successful route checkpoint.

- 2026-08-31: Guarded PHPUnit was rerun with `NHK_WP_TEST_DB=nhk_v3_test` and
  `NHK_WP_TEST_PATH=public`: 133 tests and 808 assertions passed. The same
  checkpoint's route smoke was rerun outside the sandbox against Apache and
  passed all 21/21 declared routes, including the semantic archive page-two
  routes.

- 2026-08-31: Pagination links for Search, Authority, Media, Video and
  Knowledge now expose `aria-current="page"` only on the active page. The
  focused accessibility contract is green at 14 tests/216 assertions; guarded
  PHPUnit is green at 134 tests/813 assertions, PHP lint is green, and the
  external-sandbox localhost route smoke is 21/21.

- 2026-08-31: Public Knowledge detail evidence now carries the approved
  source title/type and falls back to the source locator when an evidence
  locator is absent; inactive sources remain filtered out. Guarded PHPUnit
  is green at 134 tests/816 assertions and the source presentation contract
  is covered by the KnowledgePageQuery unit test.

- 2026-08-31: The route smoke harness now includes `/media/page/2/`,
  `/video/page/2/` and `/knowledge/page/2/`; the expanded external-sandbox
  runtime smoke passed 24/24 checks. The harness contract and guarded suite
  are green at 134 tests/819 assertions; no route changes or data mutations
  were introduced by this coverage extension.

- 2026-08-31: The route smoke harness now also includes `/tri-thuc/page/2/`
  and `/goc-chia-se/page/2/`, matching the V2 editorial archive pagination
  contract. External-sandbox runtime smoke passed 26/26 checks; guarded
  PHPUnit passed 134 tests/821 assertions and the smoke script passed PHP
  syntax validation. No data or production route state changed.

- 2026-08-31: SEO smoke now validates native `/wp-sitemap.xml` payloads
  contain `<sitemapindex` and `/feed/` payloads contain `<rss`, in addition
  to HTTP 200 status. External-sandbox runtime smoke passed 28/28 checks;
  guarded PHPUnit passed 134 tests/825 assertions and plugin/theme lint
  passed. No custom sitemap alias or production routing change was introduced.

- 2026-08-31: SEO smoke now verifies the V2 `/tim-kiem/?q=odo` compatibility
  redirect returns HTTP 301 and a `Location` containing `/?s=odo`, preserving
  the native WordPress search owner. Expanded runtime smoke passed 29/29;
  guarded PHPUnit passed 134 tests/827 assertions. No production routing or
  V2 data was modified.

- 2026-08-31: Public entity payload rendering now maps technical field labels and
  filters internal phrases such as canonical, stable key, external reference and
  atomic claim at the theme presentation boundary without changing source data.
  The frontend contract is now 87 unit tests/452 assertions; guarded full suite
  passes 131 tests/799 assertions. Browser QA confirms the active Odo detail has
  no internal payload terminology and no horizontal overflow. Route smoke was
  attempted after this checkpoint but the shell could not reach the local HTTP
  listener; this remains an environment evidence gap, not a route assertion.

- 2026-08-31: Read-only `nhk_v3` MediaAsset inventory verified all three imported
  assets remain PRIVATE and their storage keys still reference the old V2
  absolute upload tree. None of the three source files is present under the V3
  upload root, so checksum/byte verification cannot pass and public delivery
  remains correctly fail-closed; no database or file state was changed.

- 2026-08-31: Local Apache was available again and the read-only frontend route
  smoke was re-run outside the sandbox: all 21/21 checks passed, including
  public archives, V2 aliases, semantic search page two, comparison and 404.
  The earlier shell-listener evidence gap is closed; active Video data and
  broader screenshot coverage remain separate gates.

- 2026-08-31: Browser route sweep found and corrected a missing whitespace in
  the homepage hero headline. The frontend contract now asserts the rendered
  headline boundary; browser textContent is `Mỗi chiếc đồng hồ mang một câu
  chuyện.` and the full guarded suite passes 131 tests/799 assertions.

- 2026-08-31: Added `V2_DOMAIN_TARGET_REVIEW_2026-08-31.md`, a read-only
  breakdown of all 764 skipped V2 WordPress records: 742 domain records, 21
  attachments and one global-styles record. The restored export lacks a
  deterministic legacy-post-to-semantic-ID field, so name/slug joins remain
  prohibited; governed target mappings or retirement decisions are still
  required before redirects or body migration.

- 2026-08-31: Read-only title reconciliation found exact one-to-one candidates
  for all five residual `DOMAIN_TARGETED` URLs in
  `V2_URL_RECONCILIATION_REVIEW_2026-08-31.md`, mapping them to existing
  `nhk:knowledge:editorial.article.*` claims. The matches reduce ambiguity but
  remain pending UUID/revision/provenance and governed redirect-or-retire
  decisions; no migration or redirect was applied.

- 2026-08-31: Field-level verification found UUID and revision 2 for each of
  the five candidates, but all are `ARCHIVED`, `UNVERIFIED`,
  `ARCHIVED_OPERATIONAL_NOT_PUBLIC_KNOWLEDGE` and have no active target. They
  are identity matches, not public redirect targets; governed retirement or a
  separately approved active target is required.

- 2026-08-31: Native homepage URL V2 ID 758 (`/`) was normalized to `/` and
  applied locally as `READY_NOOP`; route smoke confirms HTTP 200 with no
  redirect or duplicate editorial record. URL reconciliation now has 27
  residual candidates; policy-normalized dry-run totals are 3,961 mapped,
  1,012 skipped and 0 conflicts. The change is limited to local `nhk_v3` and
  does not modify V2 or production.

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
- 2026-08-31: MCP tool definitions now expose protocol input schemas and a
  capability-gated Streamable HTTP POST endpoint. The local runtime accepts
  modern JSON-RPC `2026-07-28` metadata, retains legacy `2025-11-25`
  initialization compatibility, validates Origin and mirrored headers, and
  delegates all calls to the existing read/Governance handlers. Guarded
  transport tests and local HTTP smoke pass; external client/deployment
  interoperability remains open.
- 2026-08-31: P11 residual-gate audit re-ran the current quality gates after
  restoring the local MySQL runtime: unit 82/286, guarded WordPress
  integration 41/260, plugin/theme PHP lint and route smoke 20/20 all pass.
  The Cutover Report was corrected to record the current 12-tool MCP catalog;
  V2 field-level/policy decisions, active Video coverage and external MCP
  interoperability remain open, so production cutover stays unauthorized.
- 2026-08-31: Browser visual QA added real-data mobile checks for the homepage,
  editorial empty archive, native Post, active Authority detail and active
  Media detail; DOM inspection confirmed one main landmark and one footer on
  the long homepage. Local read-only DB inspection confirms 242 Media rows,
  3 assets and 0 Video rows, so no artificial Video detail fixture was added.
  Route inventory and Cutover evidence now reflect the healthy local runtime;
  broader route screenshots, active Video detail and policy/data gates remain
  open.
- 2026-08-31: Canonical Video ingest is now a governed vertical slice: the
  executor delegates validated YouTube URL ingestion, update, retire and
  reactivate to VideoService; MCP exposes `nhk.video.ingest` with capability
  gating, and Admin exposes a labelled Video URL control. Unit and guarded
  integration evidence now passes at 83/298 and 42/279 respectively, including
  Video create → submit → approve → apply; active public Video data is still
  absent locally, so no browser fixture was created.
- 2026-08-31: The guarded Video lifecycle test now also verifies the active
  canonical Video through `GET /nhk/v1/video/{uuid}` after apply. Current
  evidence is 83 unit tests/300 assertions and 42 integration tests/282
  assertions; combined evidence is 125 tests/582 assertions. Public active
  Video browser QA remains data-gated because `nhk_v3` has zero Video rows.
- 2026-08-31: Local HTTP verification after Video wiring returned 20/20
  expected public route statuses; a real `/wp-json/nhk/v1/mcp` POST returned
  the 13-tool catalog and included `nhk.video.ingest`. Unit/integration/lint
  evidence remains green, while external deployment interoperability and V2
  reconciliation are still separate cutover gates.
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
  projections as `DOMAIN_TARGETED`; the guarded export/dry-run/apply rerun
  completed at 2,012 migrated, 2,961 skipped and 0 conflicts. The URL ledger
  now has 405 migrated rows (370 entity redirects, 34 native-post redirects
  and one `READY_NOOP`), 372 `DOMAIN_TARGETED` rows and 23 invalid mappings;
  the rerun was idempotent and staging was restored to a V3-only snapshot.
- 2026-08-31: Mapper 6.11 added governed redirects for 75 archived Knowledge
  URLs with active consolidation targets. The restored export/dry-run/apply
  reached 3,330 mapped, 1,643 skipped, 2,379 migrated and 0 conflicts; URL
  reconciliation is now 772 migrated (367 Knowledge, 370 Authority, 34
  native-post and one no-op), with 5 archived/no-target Knowledge URLs and 23
  malformed/system URLs explicitly skipped. Knowledge evidence remains
  fail-closed unless both Evidence and its Source are active; staging was
  restored to V3 migration 8/8.
- 2026-08-31: V2 media usage audit confirmed exactly 0 rows in
  `nhkv2_nhk_media_usage`; no usage rows require migration. Media parity is
  therefore recorded as usage-contract PASS, while the three imported PRIVATE
  MediaAsset rows remain gated on delivery/privacy policy approval.
- 2026-08-31: Mapper 6.12 retained the 3,330/1,643 dry-run and 2,379/2,594/0
  apply counts while splitting the 28 residual URL skips into 5
  `DOMAIN_TARGETED`, 21 `UNSUPPORTED_MEDIA_REFERENCE`, 1
  `RETIRED_LEGACY_GARBAGE` and 1 `INVALID_URL_MAPPING`; the full rerun stayed
  idempotent.
- 2026-08-31: Mapper 6.13 classified the remaining WordPress global-style URL
  as `RETIRED_LEGACY_GARBAGE`. Full-artifact dry-run remained 3,330 mapped /
  1,643 skipped; local-dev apply and rerun remained 2,379 migrated /
  2,594 skipped / 0 conflicts with URL skips now 5 `DOMAIN_TARGETED`, 21
  `UNSUPPORTED_MEDIA_REFERENCE` and 2 `RETIRED_LEGACY_GARBAGE`. Apply output
  hashes matched across both runs.
- 2026-08-31: Mapper 6.14 made `legacy_semantic_projection` stable keys
  collision-safe by retaining the historical semantic key for the first row
  and suffixing later duplicates with their `projection_id`. Dry-run now
  mirrors apply boundaries for custom/system posts, categories and relation
  predicates: 2,379 mapped / 2,594 skipped / 0 conflicts. Batch 14 contains
  all 4,973 source keys with matching reason buckets, and its apply rerun
  hash matched exactly. Staging was restored to 0 V2 tables, 17 V3 tables and
  migration 8/8.
- 2026-08-31: Browser visual QA succeeded for desktop homepage, Knowledge
  archive/detail, Authority detail and 404 surfaces. Responsive/tablet/mobile
  coverage remains pending; the browser connector is available for follow-up.
- 2026-08-31: Browser verification found Knowledge cards in unified Search
  rendering placeholder `#` links despite active claim data. The theme now
  maps active Knowledge results to canonical `/knowledge/claim/{UUID}/` URLs,
  with a frontend contract regression assertion; Search `Odo` has zero
  Knowledge `#` links and the read-only route smoke passes 16/16.
- 2026-08-31: A field-level review artifact was added for all 28 residual URL
  candidates from the hashed Mapper 6.14 export. It records the five
  domain-targeted posts, 21 unsupported media references and two retired
  legacy paths without changing their explicit-skip status or V2/V3 data.
- 2026-08-31: A fail-closed MediaAsset delivery boundary was added. It serves
  only PUBLIC assets whose MIME type is allowlisted, whose resolved file stays
  under the configured current storage root, and whose size and SHA-256 match
  persisted metadata; PRIVATE/HIDDEN, legacy absolute-path and missing assets
  return 404. Unit evidence is 70 tests/205 assertions and route smoke is
  17/17, including an unknown asset route.
- 2026-08-31: Guarded WordPress integration passed after the delivery boundary
  and rewrite registration changes: `nhk_v3_test` remains migration 8/8 and
  the suite is 103 tests/419 assertions with no V2 tables restored.
- 2026-08-31: Desktop Authority archive QA found the archive context omitted
  its entity type, producing the duplicate heading “Khám phá khám phá”, a
  generic document title and malformed stable-key links. The archive context
  now preserves `type` for the theme/query boundary; the fix is covered by a
  frontend contract assertion and browser verification.
- 2026-08-31: Authority archive title handling now uses the preserved entity
  type for localized document titles and canonical SEO title output; the
  previous generic site title is removed from this surface.
- 2026-08-31: Authority archive browser verification now reports the localized
  title “Khám phá thương hiệu — Đồng Hồ Nhà Kho”, a non-duplicated heading,
  canonical `/brand/nhk%3Abrand%3Ajunghans/` card links and route smoke 17/17.
- 2026-08-31: Guarded WordPress integration was rerun after the Authority
  archive fix and passed on `nhk_v3_test`: 103 tests/421 assertions.
- 2026-08-31: REST Entity/Media/Video details and MCP Entity/Media/Video reads
  now fail closed for retired records, matching the active-only public page
  boundary. Unit evidence is 70 tests/210 assertions; guarded integration
  remains green at 103 tests/424 assertions.
- 2026-08-31: Local REST smoke verified active entity/media/knowledge/search
  reads (`200`), wrong or missing entity routes (`404`) and unauthenticated
  Governance create/eligibility/apply (`401`). Runtime MCP registration
  captured 11 tools, 5 governed tools and both read/governance handlers; at
  that checkpoint, an external MCP transport was not present or inferred.
- 2026-08-31: Local MCP transport smoke returned `tools/list` 200 with 11
  definitions, rejected an unauthenticated governed proposal call with 403,
  and rejected an invalid Origin with 403. The endpoint is local-runtime
  evidenced, not production or external-client approval.
- 2026-08-31: Post single now requests Graph-derived related entities,
  articles, Media and Video through `nhk_v3_post_related_content`; the theme
  renders the groups only when active/public query results exist. The filter
  wiring was browser-verified after catching and fixing a real argument-order
  fatal on `/hello-world/`; desktop Post visual QA is clean, route smoke is
  17/17, unit evidence is 72 tests/215 assertions and guarded integration is
  108 tests/440 assertions.
- 2026-08-31: Read-only V2 reference QA confirmed archive contracts for
  `/thuong-hieu/`, `/hien-vat/` and `/am-nhac/`. V3 now registers these as
  compatibility aliases into canonical brand/specimen/music contexts; alias
  route smoke is 20/20 and `/thuong-hieu/` emits canonical `/brand/` metadata.
  V2 detail slugs such as `/odo/odo-39/` remain mapped only through verified
  ledger evidence and are not guessed by name.
- 2026-08-31: V2 detail QA confirmed `/odo/` and `/odo/odo-39/` as canonical
  discovery paths. A fail-closed compatibility resolver now redirects only a
  unique active Brand or Brand/Model public-slug match to the canonical
  stable-key route, while native WordPress content and ambiguous names win or
  remain unresolved. Unit evidence is 74 tests/223 assertions; local HTTP
  verification is pending because MySQL/Apache are currently stopped.
- 2026-08-31: V2 search QA confirmed `/tim-kiem/?q=Odo` as a grouped search
  contract. `PublicEditorialRoutes` now preserves `q` while redirecting to
  native WordPress `/?s=Odo`; no duplicate search persistence is introduced.
  Unit evidence is 75 tests/226 assertions; current HTTP verification remains
  blocked by stopped MySQL/Apache.
- 2026-08-31: The previously linked `/comparison/` discovery surface was
  completed as a read-only Authority comparison route. It accepts two
  `type/stable-key` references, resolves only active canonical entities through
  `EntityPageQuery`, and renders semantic payload facts without a duplicate
  persistence model. Unit evidence is 77 tests/237 assertions; local runtime
  verification is now available.
- 2026-08-31: Browser QA of `/comparison/` found its semantic UI healthy but
  its document metadata inherited the site default. Theme SEO now emits a
  dedicated comparison title, description, canonical `/comparison/` and
  breadcrumb; the correction is covered by the frontend contract test.
- 2026-08-31: Local runtime verification passed the new discovery surfaces:
  `/comparison/` returns 200 with its dedicated title; `/odo/` and
  `/odo/odo-39/` return 301 to canonical Brand/Model stable-key routes; and
  `/tim-kiem/?q=Odo` returns 301 to `/?s=Odo`. The guarded suite passes 113
  tests/462 assertions and route smoke passes 20/20.
- 2026-08-31: Active local Media detail QA passed at
  `/media/0068236c-1033-4aef-ac97-b711a30ccb4d/` with HTTP 200, dedicated
  title/canonical metadata and the expected fail-closed empty state for its
  draft/PRIVATE asset. Desktop visual inspection passed; active Video detail
  remains unverified because no active Video record is present in local data.
- 2026-08-31: Migration009 added the non-canonical
  `nhk_legacy_projection_contexts` sink. It stores only bounded projection
  metadata and provenance, explicitly records `body_migrated=false`, and
  rejects projection bodies. The updated dry-run maps all 1,581 projection
  rows; the local-dev apply reached 3,960 migrated / 1,013 skipped / 0
  conflicts, and read-only checks confirmed 1,581 sink rows, 1,581 false body
  flags and zero projection-derived Authority entities. No V2 or production
  data was changed.
- 2026-08-31: Responsive QA found the 768px header search/nav row clipped at
  the right edge. The theme now switches the header to its accessible menu
  layout through 820px, with stylesheet cache-busting version 1.1.1. Browser
  checks at 390px and 768px found no horizontal overflow on homepage, search,
  comparison, Authority detail or Media detail; the menu toggle exposes all
  ten navigation links. Full archive/detail/pagination visual coverage remains
  open.
- 2026-08-31: The full public route inventory was checked at 390px and 768px
  (38 route/viewport combinations). Theme content had no horizontal overflow
  after the grid min-width fixes; the only remaining measured overflow was the
  logged-in WordPress admin toolbar on the Component page, outside the public
  theme shell. Pagination visual coverage and an active Video detail remain
  unavailable in the current local dataset.
- 2026-08-31: Pagination QA checked the declared archive page-2 routes at
  390px and 768px (26 route/viewport combinations); all theme content was
  overflow-free, and `/model/page/2/` received a mobile visual inspection with
  working archive heading, filter and entity cards. Remaining pagination
  visual coverage is the broader route/page-state sweep; the current local
  dataset has no active Video detail to inspect.
- 2026-08-31: Frontend accessibility/performance hardening added a skip link,
  explicit `main` targets on all public templates, a keyboard-visible menu
  control with `aria-controls`/synchronized `aria-expanded`, focus-visible
  styling and decorative-card image alt handling. Browser runtime checks at
  390px confirmed the menu exposes all ten links and no horizontal overflow;
  `node --check`, PHP lint, unit tests (80/259) and diff check pass. A fresh
  shell route/integration retry is currently blocked by the local service
  connection state and is not counted as a pass.
- 2026-08-31: SEO archive policy was made explicit through the single
  WordPress `wp_robots` output: canonical non-search pages emit
  `index,follow`, while search and paginated archive states emit
  `noindex,follow`, including custom entity/Media/Video/Knowledge page vars;
  the frontend contract test covers the policy.
- 2026-08-31: Browser runtime verification confirmed the homepage canonical is
  `http://localhost/` rather than the first editorial post, while search and
  custom archive page-two states emit one consolidated `robots` directive with
  `noindex,follow`; unit evidence is now 81 tests/265 assertions.
- 2026-08-31: Added a responsive long-title/long-key guard for article,
  semantic, entity, media, knowledge and related cards, plus a zero-width
  filter input constraint. Unit tests remain 81/265, PHP lint and diff check
  pass; full route smoke remains subject to the local service gate.
- 2026-08-31: Re-ran the guarded WordPress integration outside the sandbox
  network boundary: 38 tests/235 assertions pass on `nhk_v3_test`; the
  localhost frontend route smoke also passes 20/20. Combined current test
  evidence is 119 tests/502 assertions; no V2 live or production data was
  changed.
- 2026-08-31: Responsive QA found a real tablet overflow on the Component
  archive caused by long stable keys. The theme now wraps `.entity-card-key`
  values and bumps the stylesheet cache version to 1.1.3. Browser recheck
  covers 32 route/page-state and 390px/768px combinations with zero overflow,
  valid main/heading landmarks, and the Component archive visually inspected
  at both widths; active Video detail and broader screenshot coverage remain
  open.
- 2026-08-31: Additional mobile screenshots passed for Media pagination,
  Video empty state, Knowledge pagination and 404. These states retain
  usable hierarchy, controls and footer layout; remaining screenshot QA is
  route-specific coverage beyond the inspected set and an active Video detail
  when a valid local record exists.
- 2026-08-31: NHK Admin operational forms now associate every lookup,
  proposal-composer and semantic/Graph control with an explicit label/id and
  expose form context through labelled/described regions. A source-level
  accessibility contract test covers the associations; the unit suite is now
  82 tests/277 assertions.
- 2026-08-31: MCP now exposes governed `nhk.media.ingest`. The tool converts
  a complete Media packet (identity, provenance, assets and usages) into a
  Governance proposal; controlled apply uses the MediaService transaction
  boundary and defaults ingested assets to PRIVATE. End-to-end integration
  coverage passes create → submit → approve → apply with the asset still
  private; current evidence is 82 unit tests/286 assertions and 40 integration
  tests/257 assertions.
- 2026-08-31: Streamable HTTP now rejects modern requests that do not advertise
  both JSON and SSE response media types, returning the protocol
  HeaderMismatch error; this guard is integration-tested. Current evidence is
  82 unit tests/286 assertions and 41 integration tests/260 assertions.
- 2026-08-31: Governed Knowledge, Source and Evidence ingest now uses the same
  Controlled Apply boundary as Authority, Media and Video. Eligibility resolves
  revisions across all canonical repositories; MCP exposes three capability-gated
  ingest tools. Guarded integration proves Source → Knowledge Claim → Evidence
  create/submit/approve/apply and public claim/source reads with nested evidence;
  current evidence is 83 unit tests/322 assertions and 43 integration tests/328
  assertions, combined 126/650. Public Source/Evidence activation policy and V2
  provenance reconciliation remain cutover gates.
- 2026-08-31: Semantic search was bounded per page in both the theme query and
  REST API, with per-group totals exposed for navigation. WordPress search page
  2 now remains HTTP 200 when native Post results are exhausted but semantic
  results continue; browser verification for `/?s=odo&paged=2` shows 12 cards
  per group, 17 navigation pages and no horizontal overflow. Unit evidence is
  84 tests/327 assertions; guarded integration is 44 tests/347 assertions after
  adding the REST bounded-page contract test; combined current suite is
  128/674.
- 2026-08-31: MCP `nhk.search` now exposes optional `page`/`per_page` inputs,
  returns semantic group totals and bounds each group per page while excluding
  retired Authority records. Unit evidence is 85 tests/332 assertions; guarded
  integration remains 44 tests/347 assertions; combined current suite is
  129/679.
- 2026-08-31: REST Search now also suppresses retired Authority records, keeping
  REST, theme and MCP semantic reads aligned on active-only visibility. The
  contract assertion is included in the 85-test/334-assertion unit suite;
  guarded integration remains 44 tests/347 assertions and combined evidence is
  129/681.
- 2026-08-31: Public templates and SEO descriptions no longer expose internal
  domain language such as Authority reference, Knowledge claim, Canonical ID,
  entity Video or Semantic search. The contract is covered by the frontend unit
  suite; current evidence is 86 unit tests/359 assertions, 44 guarded
  integration tests/347 assertions and combined 130/706.
- 2026-08-31: Public copy parity was extended across homepage, entity, Knowledge,
  Media, Video and comparison templates: technical labels such as canonical,
  semantic, atomic claim, external reference, Revision and Canonical ID were
  removed from user-facing copy while URL/schema contracts stayed intact. The
  contract remains covered by the frontend unit suite; current evidence is
  86 unit tests/404 assertions, 44 guarded integration tests/347 assertions and
  combined 130/751.
- 2026-08-31: The public terminology contract was extended to every primary
  template, including homepage, entity detail/archive and native Post related
  video cards. Technical wording is absent from rendered public copy; full
  verification is 86 unit tests/446 assertions, 44 guarded integration
  tests/347 assertions, combined 130/793, lint pass and route smoke 21/21.
- 2026-08-31: A raw HTTP client probe against the local Streamable HTTP endpoint
  returned `200 application/json` for modern `tools/list` with all 16 tools;
  modern `tools/call` for `nhk.search` page 2/per-page 5 returned JSON-RPC
  success with five items per semantic group and totals entities 76, media 143,
  videos 0 and knowledge 200. This strengthens local protocol evidence only;
  external client/deployment interoperability remains open.
