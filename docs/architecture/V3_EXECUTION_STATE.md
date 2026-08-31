# NHK V3 Execution State

Last updated: 2026-08-31, Cutover Readiness audit checkpoint pushed.

| Field | Current value |
|---|---|
| Workspace | `/Users/imac24-2125d/Developer/nhk-v3` |
| Branch / HEAD | `main` / `86e5838` |
| Current phase | P11 readiness audit in progress; P7/P8/P9/P10 gates remain open |
| Last accepted phase | P5 Canonical Domain Foundation |
| DB migration | current 4 / target 5 on `nhk_v3`; Migration005 is pending integration gate; media/video storage ready |
| Tests | Unit suite: 58 tests, 155 assertions; plugin/theme PHP lint and diff check pass; WP integration requires `NHK_WP_TEST_PATH` |
| Blockers | None for local code work; unit suite is green, but WordPress integration bootstrap fails with “Error establishing a database connection” even with `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test`; V2/live remains read-only |
| Working assumptions | Media/Video routes are registered only when WordPress has a usable `$wpdb`; `nhk_v3_test` is the only destructive integration target; route inventory is source-level until runtime returns |
| Next executable task | Resolve local WordPress/test DB, run mandatory integration/runtime smoke, then obtain V2 read-only export and backup/restore evidence before any mapping or migration |
| Last parity count | Not yet inventoried; matrix initialized as NOT ASSESSED |
| Pending migrations | None for P4; future P5 migrations require their own gate |
| Migration dry-run | No-write service and CLI are ready; no V2 export has been provided, so no source counts or mappings are claimed |

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
