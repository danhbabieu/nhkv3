# NHK V3 Execution State

Last updated: 2026-08-31, P8 search/proposal read and governed surfaces are pushed.

| Field | Current value |
|---|---|
| Workspace | `/Users/imac24-2125d/Developer/nhk-v3` |
| Branch / HEAD | `main` / `c9ac981` |
| Current phase | P8 Admin/API + P7/P9 vertical slices in parallel |
| Last accepted phase | P5 Canonical Domain Foundation |
| DB migration | current 4 / target 5 on `nhk_v3`; Migration005 is pending integration gate; media/video storage ready |
| Tests | Unit suite: 48 tests, 110 assertions; plugin/theme PHP lint and diff check pass; WP integration requires `NHK_WP_TEST_PATH` |
| Blockers | None for local code work; V2/live remains read-only; WP integration environment absent |
| Working assumptions | Working tree was clean at checkpoint; `nhk_v3_test` is the only destructive integration target |
| Next executable task | Add MCP adapter/read contract and migration inventory tooling; browser/DB integration remains environment-gated |
| Last parity count | Not yet inventoried; matrix initialized as NOT ASSESSED |
| Pending migrations | None for P4; future P5 migrations require their own gate |
| Migration dry-run | Not applicable to code-only/P4 bootstrap; required before real V2 data migration |

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
