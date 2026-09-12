# ISOLATED VIDEO RECOVERY RUNTIME

## Decision

`READY_FOR_FIRST_RECOVERY_WAVE: NO`.

The repository now has a real isolated local WordPress/NHK Core runtime on
`nhk_v3_video_recovery`, with migration marker `20/20`, but it is an empty
bootstrap rather than a data-bearing restore. The governed V3 semantic
snapshot export/import boundary is implemented in code and documented in
`V3_SNAPSHOT_RECOVERY_RUNTIME.md`.
The Demo target remains read-only. No staging mutation, export, import,
deployment or push was performed in this phase.

An empty `nhk_v3_test` database is not accepted as the recovery runtime. It may
be used only after a reviewed canonical snapshot is restored into that exact
isolated database and the golden identity checks pass.

## Existing repository-supported mechanisms

| Concern | Existing boundary | Finding |
|---|---|---|
| WordPress runtime | root `wp-config.php` + `config/application.php` | Environment-driven DB/site configuration; secrets are external to Git |
| Development DB | `nhk_v3` | Schema/runtime smoke target; not a backlog substitute and not a recovery write target |
| Integration isolation | `NHK_WP_TEST_DB=nhk_v3_test` + `TestDatabaseGuard` | Exact destructive-test guard exists; current DB is not a restored Video dataset |
| Schema safety | `MigrationDatabaseGuard` + `nhk-core-maintenance.php --operation=migration-up` | UP-only recovery allow-list is live for `nhk_v3_video_recovery`; no DOWN/drop/reset permitted |
| Remote maintenance | `RemoteRuntimeAdapter` | Allowlisted maintenance operations include `backup/snapshot`, `read-back` and `controlled-apply`, but current adapter is Demo-target-specific and connector exposure is read-only |
| Deployment | `RemoteDeploymentAdapter` / `nhk-deploy-verify` | Hardcoded `demo.1945.vn` allowlist; not an isolated recovery deployment path |
| V2 restore | `tools/v2-restore-normalize.php` | V2-only migration evidence; forbidden as a Video backlog snapshot mechanism |
| Semantic V3 snapshot import | `Application/Snapshot` export/import services and typed source/writer ports | `CODE_IMPLEMENTED`; local writer/source filters are not registered, so no direct SQL or generic writer may fill that gap |

## Required isolated configuration

These values are a provisioning specification, not live credentials and were
not written to an environment file:

| Field | Required value/policy |
|---|---|
| Environment name | `v3-video-recovery-1309` |
| Runtime mode | `recovery`, never `staging` or `production` |
| Database | Dedicated non-production database, proposed name `nhk_v3_video_recovery`; it must not be `nhk_v3`, the Demo DB, or an unproven empty test DB |
| Site URL | Dedicated hostname or isolated local URL; must not be `https://demo.1945.vn` |
| DB credentials | `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `DB_PREFIX` supplied outside Git |
| WordPress bootstrap | `WP_ENVIRONMENT_TYPE=development` or an explicitly registered recovery value, `WP_HOME`, `WP_SITEURL`, salts and `DB_*` |
| YouTube source | `NHK_YOUTUBE_API_KEY` in process/PHP-FPM secret configuration; never in the snapshot or logs |
| Schema | Run normal UP migrations only; target current repository migration level 20/20 after restore |
| Write capability | Dedicated governed MCP/Admin lifecycle only: Proposal → approval → eligibility → Controlled Apply → canonical read-back; no direct repository/SQL writer |
| Article policy | Native Posts required for provenance/context remain drafts; no Article publication |

The recovery import guard now rejects `staging`, `production`, `test`,
`demo.1945.vn`, the Demo database identity and missing snapshot proof. A
dedicated runtime still has to bind a canonical source/writer adapter before
any snapshot write. The migration guard has an explicit recovery-only branch
for the exact allow-listed DB and rejects the same recovery runtime when it is
pointed at `nhk_v3`, staging, production or another DB. This does not broaden
the existing Demo staging allowance.

## Provisioning evidence — 2026-09-12

| Check | Result |
|---|---|
| Database | `nhk_v3_video_recovery` exists on the local MySQL instance; no tables existed before bootstrap |
| WordPress | Core installed on isolated local site `http://127.0.0.1:8090` |
| NHK Core | Plugin active; recovery runtime markers read back as `v3-video-recovery-1309` / `recovery` |
| Migrations | `nhk_core_migration_current=20`, `nhk_core_migration_target=20` |
| Frontend boot | Root route HTTP 200; golden route HTTP 404 as expected for the empty runtime |
| MCP discovery | Local `/wp-json/nhk/v1/mcp` tools/list HTTP 200; authenticated tool-call connector not registered |
| Staging | No staging write, deployment or semantic mutation performed |

The local bootstrap is infrastructure provisioning evidence only. It is not a
backlog snapshot and must not be used as a substitute for the actual Demo
dataset.

## Snapshot/restore plan

The source is the read-only canonical target already proven by the golden #372
read-back. The source artifact must be produced by a versioned, governed
canonical snapshot/export boundary and accompanied by a manifest containing
source runtime identity, documentation/build checkpoint, object counts,
per-object UUID/stable-key hashes, dependency order and snapshot checksum.

The restore sequence is:

1. Provision the dedicated DB/site and verify the environment marker is
   non-staging and the site host is not Demo.
2. Obtain an approved read-only snapshot artifact from the canonical runtime.
   A public connector inventory is insufficient because Capture rows, private
   provenance and complete Proposal history are not exposed there.
3. Verify checksum, source identity and manifest before opening the restore
   boundary.
4. Restore canonical objects through the governed snapshot importer in
   dependency order: Authority; Captures and addenda; native draft Posts;
   Proposals, approval/history/audit and ApplyAttempts; Source, Claim and
   Evidence; Videos and required Media references; Graph; Public Identity;
   completion/projection state.
5. Preserve every canonical UUID, stable key, Proposal ID, revision, audit
   event, Evidence/Source ID, Graph edge ID, Public Identity ID and route
   history. No UUID regeneration or identity merge is allowed.
6. Run normal UP migrations after restore and record the migration ledger.
   Never use `DROP`, `TRUNCATE`, `reset`, direct SQL repair or the V2 restore
   converter for this dataset.
7. Read back counts and dependency closure, then run the golden acceptance
   below before granting the recovery write capability.

The implemented maintenance export was invoked read-only and returned
`SNAPSHOT_SOURCE_ADAPTER_UNAVAILABLE`; no artifact was generated. The actual
Demo source adapter is not registered in the deployed source runtime, and this
workspace has no approved read-only source deployment configuration or
authenticated export connector. The plan is therefore not an executed restore
and cannot be used to claim that the local runtime contains the historical
backlog.

## Golden acceptance gate

Before any backlog recovery, the isolated runtime must return all of the
following unchanged:

| Object | Expected identity |
|---|---|
| Capture | `01a094df-6e43-7226-a3a5-78a6c4c05c7e` |
| Video | `01a094df-6ff2-7872-9bbe-ca4e843a68ef`, external `iqbGOL967t4` |
| Variant | `852da54d-457a-4397-a16d-52d9452ba766`, `nhk:variant:odo.36.8` |
| Graph | active Video → `about` → Variant edge |
| Public Identity | `01a094df-72cb-7a30-bb1e-bc783f014fd6` |
| Route | `/video/so-372-odo-36-8-con-nguyen-ban-am-thanh-hay/` |
| Frontend | detail resolves and `/video/` collection contains the same Video; content matches the source read-back |
| Warning | `TRANSCRIPT_UNAVAILABLE` only; it is not a blocker under the current contract |

Any mismatch is `GOLDEN_IDENTITY_MISMATCH` and stops the workflow. The local
local runtime currently returns HTTP 200 for its root and MCP discovery
surface, but the golden Video route returns 404 because the source snapshot has
not been imported. It therefore cannot pass this gate, and no first-wave Apply
was attempted.

## Content enrichment architecture

The implementation uses `VideoEditorialEnrichmentContext` and
`VideoEditorialEnrichmentService` as an application-level projection seam.
The context is immutable and bounded to:

- `SPECIMEN` facts for the exact recorded object;
- `SOURCE_FACT` values from source metadata;
- `CANONICAL_CONTEXT` from the exact subject and bounded direct neighborhood;
- existing related Knowledge and Entity IDs for reuse.

The service returns the existing editorial package shape, SEO title/description
and a derived `content_quality` packet. It never creates Knowledge, Evidence,
Graph edges, Authority nodes, Media or Public Identity. Existing Knowledge is
reused by canonical ID; generated prose is never Evidence. The quality policy
rejects missing/trivial/cut-off copy, unsupported universal language, missing
specimen scope, unused available canonical context and missing related
references.

`CONTENT_COMPLETE` is distinct from technical/public/frontend completion. The
final derived state is:

`TECHNICAL_COMPLETE + CONTENT_COMPLETE + PUBLIC_COMPLETE = COMPLETE_VERIFIED`.

## First recovery wave — plan only

These are the five safest canonical owners from the actual target. They are all
already-applied historical Video owners, so their original Proposals remain
immutable. The five applied P4 orphan Proposals and all duplicate/conflict
groups are excluded.

| # | External / Video | Proposal state | Current blocker | Exact subject/dependencies | Planned governed operations | Planned enrichment |
|---:|---|---|---|---|---|---|
| 1 | `P4KaHX3LBOw` / `01a06815-1e51-7964-b004-1ba79e488ad1` | applied | Public Identity absent; audit `ALLOCATE`; content review | Variant `95873bfe-d978-4eda-a5a2-ce9ba79625df`; Source `01a06695-50d2-7f3b-a5a3-c27ebfe4e255`; Evidence `01a06696-acae-7083-823d-91dbb30dca7f` | Read canonical owner; reuse Source/Claim/Evidence; post-apply Video reconciliation; recompute completeness; governed Public Identity allocation; final route/frontend read-back | Source Gai-Carillon fact; Variant context; reuse Knowledge `01a06696-24ce-70be-a6c9-4fb4d7f3cfbd`; preserve source/specimen scope |
| 2 | `TsQWw2Q6-HM` / `01a072e9-45a0-7d81-8ecf-52d3e091165f` | applied | Public Identity absent; audit `ALLOCATE`; content review | Variant `95873bfe-d978-4eda-a5a2-ce9ba79625df`; Music `4b01eb30-2b44-4c9c-a000-781bb8cb9206`; Evidence `01a072e9-183c-7045-9e8a-84111260fa74` | Same post-apply reconciliation and governed identity/route completion; no Proposal repair | Quarter-hour Gai-Carillon source fact; Variant/Music context; reuse Knowledge `01a072e8-aa9d-71a2-b8bc-7012abdd813f` |
| 3 | `4d4oxh35cT8` / `01a07971-2fe3-77da-9424-998cf6f249e0` | applied | `NO_SEMANTIC_ATTACHMENT` read-model blocker despite active Graph edges; identity audit `ALLOCATE` | Variant `852da54d-457a-4397-a16d-52d9452ba766`; active Graph to Variant and four Classifications; Evidence not publicly readable | Governed attachment reconciliation using existing active Graph/Evidence; recompute completeness; allocate identity only after technical/content gates; final read-back | Exact specimen wording “36/8 máy ba vách bệt nguyên bản” as scoped source/specimen context; exact Variant context; no new Knowledge without Evidence |
| 4 | `truOChTNbwA` / `01a07af5-3303-7a73-9f15-b7f675293dc5` | applied | Public route is observed but authoritative frontend VERIFIED flag is not exposed; content review | Variant `95873bfe-d978-4eda-a5a2-ce9ba79625df`; private Source/Evidence; active Graph | Read-only verification first; post-apply content reconciliation; no identity rewrite; final frontend detail/listing verification | Raised-dial/xương-cá source/specimen wording; Variant context; reuse Knowledge `01a07af4-9cdc-702d-945d-4cfcdcfbe22f` |
| 5 | `V18Me9TdnkU` / `01a094a1-3824-7bba-9e3f-9b9fcaf20755` | applied | stale `NO_SEMANTIC_ATTACHMENT` despite attachment and active Graph; Public Identity audit `ALLOCATE` | Variant `852da54d-457a-4397-a16d-52d9452ba766`; Evidence `01a094a1-3a06-739f-b4d0-b24c227f9f18`; active edge `01a094d8-f87c-77ef-a870-47e837e6f4d3` | Governed canonical attachment read-back; recompute completeness; allocate identity through Public Identity service; final route/frontend read-back | Exact “thùng kính chuông, mặt số nổi nằm ngang” specimen/source wording; Variant context; no unsupported universal claim |

Each item must be read back after every governed operation. If a dependency,
revision, identity, Evidence visibility, collision, frontend route or content
quality check is unavailable, that item remains fail-closed and the next item
may proceed.

## Current phase acceptance

| Requirement | Status |
|---|---|
| Isolated runtime plan/config proven | `PARTIAL` — real empty local runtime is booted at `20/20`; no data-bearing restore |
| Golden #372 in isolated runtime | `NOT RUN` — local targets do not contain the source dataset |
| Enrichment implementation | `PASS` — immutable context, bounded service and quality policy added |
| Enrichment regression tests | `PASS` — focused suite 54 tests / 208 assertions |
| First recovery wave selected and planned | `YES` — five non-conflict applied owners |
| Mutation performed on staging | `NO` |
| Deploy/push | `NO` |
| READY_FOR_FIRST_RECOVERY_WAVE | `NO` |

## Next external prerequisite

Provide the source-side read-only adapter deployment path/credentials for
`demo.1945.vn` (or an approved generated `v3-semantic-snapshot/1` artifact) and
register a recovery writer plus the dedicated `@V3-Recovery` connector against
the isolated recovery site. Then run the golden gate and migration/readiness
preflight. Only a PASS on that gate permits the first five-item recovery wave.
