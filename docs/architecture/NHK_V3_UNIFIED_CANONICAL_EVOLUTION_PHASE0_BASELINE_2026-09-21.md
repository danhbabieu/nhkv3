# NHK V3 Unified Canonical Evolution Phase 0 Baseline

## Purpose

This document records the read-only Phase 0 baseline for the attached
`NHK_V3_UNIFIED_CANONICAL_EVOLUTION_MASTER_PLAN_v1.0.docx`. It is execution
evidence, not a new architectural authority. The Constitution remains the
sole normative authority.

## Evidence identity

| Field | Value |
|---|---|
| Phase | 0 — Baseline and Canonical Bootstrap |
| Baseline revision | `340c7493d06135d589b3f8e61310ac40080981f1` |
| Branch | `main` |
| Upstream relationship | `main...origin/main`, ahead/behind `0/0` |
| Worktree | Clean at inspection; no pre-existing uncommitted work |
| Inspection date | 2026-09-21, Asia/Ho_Chi_Minh |
| Attached plan | `/Users/imac24-2125d/Downloads/NHK_V3_UNIFIED_CANONICAL_EVOLUTION_MASTER_PLAN_v1.0.docx` |
| Mutation policy | Read-only; no code, schema, data, deployment or commit mutation |

## Authority and current contracts

The bootstrap chain was read directly and in order:

1. `AGENTS.md`
2. `docs/constitution/READ_FIRST.md`
3. `docs/constitution/NHK_V3_CONSTITUTION.md`
4. `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
5. `docs/architecture/V3_EXECUTION_STATE.md`
6. `docs/architecture/V2_V3_PARITY_MATRIX.md`

The Phase 0 current-contract set is the Constitution plus the ACTIVE contracts
selected by the router and runtime documentation registry: Authority, Article
Ingest, Article research/preflight and SEO, Media, Visual Support, P6 Media and
Video foundation, Video semantic/relationship/Hub/YouTube/SEO/workflow,
Knowledge/Source/Evidence, Dictionary/Living Knowledge/Collector Profile,
Graph and related projection, Governance and failure/retry, MCP Content
Operations and Control Plane, Public Identity/route/URL/SEO, deployment and
snapshot recovery, public-claim compliance, parity and frontend inventories.
`MCP_V3_ABILITY_EXPOSURE.md` is historical evidence and was not treated as
current capability authority.

The attached Master Plan is distinguished from repository instructions as
follows: it defines this program's phases, principles, evidence format and
exit gates; `AGENTS.md` and the Constitution constrain what work is permitted;
ACTIVE contracts and executable registries define domain vocabulary and
runtime boundaries; dated execution state and parity documents are evidence,
not timeless law.

## Repository and runtime baseline

### Local repository/runtime

- Composer autoload, `symfony/uid`, NHK runtime classes and canonical
  documentation bootstrap are available locally.
- Local documentation bootstrap is internally consistent:
  - source revision: `340c7493d06135d589b3f8e61310ac40080981f1`
  - documentation version:
    `5129662cd44be50ea52123f35c8f59e446c3377e580d5b5ceed6245e358d7d04`
  - manifest hash:
    `4ff48ed899db08a904defabbe0d7bd72740efca553889817b86023e4eda68ddd`
  - build identity:
    `eb33796c296a12c8e09e8839e1e4fb6944e272fc61caef4e5fdf44c7336626cd`
  - ACTIVE documents: `49`
- Code-side MCP catalog reports `58` catalog/executable tools and `13`
  governed operations. This is not live connector exposure evidence.
- The schema migration code targets version `22` and is UP-only guarded by
  `MigrationDatabaseGuard`; the current database state was not asserted because
  WordPress could not bootstrap.

### Runtime verification

The read-only deployment preflight produced:

- PASS: Git HEAD, Composer lock/autoload, Symfony UID, NHK runtime classes and
  canonical documentation.
- FAIL: WordPress bootstrap, NHK Core bootstrap through WordPress, schema
  readiness, Authority hydration and REST bootstrap — all due to
  `WORDPRESS_BOOTSTRAP_FAILED` in this checkout.

The read-only MCP wire smoke failed before protocol negotiation because
`http://localhost:80` refused the connection. Therefore no claim is made about
deployed MCP tools, live build identity, live schema, live public routes or
live data.

## Current schema and owner map

| Boundary | Canonical owner | Local code evidence |
|---|---|---|
| Editorial Article/Post | WordPress native `wp_posts` | `Domain/Article`, `Application/Article`, `Infrastructure/Article`, `Infrastructure/WordPress` |
| Capture | Capture record and coordinator; orchestration only | `Domain/Capture`, `Application/Capture`, `Infrastructure/Capture` |
| Authority | Canonical semantic entity identity/lifecycle | `Domain/Authority`, `Application/Authority`, `Infrastructure/Authority` |
| Graph | Typed registered relations | `Domain/Graph`, `Application/Graph`, `Infrastructure/Graph` |
| Governance | Proposal, approval, eligibility, apply and audit | `Domain/Governance`, `Application/Governance`, `Infrastructure/Governance` |
| Knowledge | Atomic claims | `Domain/Knowledge`, `Application/Knowledge`, `Infrastructure/Knowledge` |
| Source/Evidence | Provenance and support | `Domain/Knowledge`, `Infrastructure/Knowledge` |
| Media | Semantic media identity | `Domain/Media`, `Application/Media`, `Infrastructure/Media` |
| MediaAsset/MediaUsage | Binary/derivative and placement/context | `Domain/Media`, `Infrastructure/Media` |
| Video | Canonical external-video reference | `Domain/Video`, `Application/Video`, `Infrastructure/Video` |
| Public Identity/SEO | Public route identity and read projection | `Application/PublicIdentity`, `Application/Seo`, `Infrastructure/PublicIdentity`, theme query/projection code |

The migration inventory is additive and UP-only through version `22`: Graph
001; Authority 002; Governance 003; Media 004; Knowledge 005; ledger 006;
Knowledge/Evidence metadata 007; MediaAsset metadata 008; projection context
009; Article ingest 010; Article Media 011; WordPress Media bridge 012; owner
publication decision 013; Public Identity 014; Dictionary 015; Claim
Projection 016; Editorial Capture 017 and addendum 018; Visual Support 019;
Governance subject binding 020; MediaUsage metadata 021; Media binding
operation 022. No migration was executed in this Phase 0 session.

## Golden-case inventory

These are selected existing test/runtime cases for Phase 3 regression encoding;
selection is read-only and does not imply live acceptance.

| Golden case | Existing evidence |
|---|---|
| Healthy text Article | `ArticlePublicationGateTest`, `ArticleIngestCoordinatorTest` |
| Draft Article and idempotent publication continuation | `EditorialPublicationWriterTest`, `McpPublicationContinuationTest` |
| Text Article without image | `ArticleSeoGateTest::test_text_article_without_optional_visual_support_is_not_system_blocked`, `ArticlePublicationGateTest::test_text_article_without_media_is_publication_ready_with_enrichment_debt` |
| Exact, representative and contextual Media usage | `MediaUsagePlacementTest`, `MediaServiceUsageIdentityTest`, `ArticleMediaPolicyTest` |
| Healthy Video and governed semantic attachment | `VideoCompletenessPersistenceTest`, `VideoSemanticCoreTest` and `CaptureVideoProvenancePlannerTest`; live read-back remains blocked |
| Retryable Video / continuation without duplicate owner | `EditorialCaptureContinuationTest`, `GovernedCaptureContinuationServiceTest`, Video Capture continuation tests |
| Supported Knowledge claim and review-scoped claim | `ClaimReusePolicyTest`, `ClaimRetrievalScopeTest`, `KnowledgeEnrichmentPlannerTest` |
| Brand, Model, Variant and Classification/Clock Type | `P5CanonicalDomainIntegrationTest`, `ClockTypePr1GoldenRegressionTest`, Authority/Graph unit suites |
| Public URL identity, historic redirect and collision fail-closed behavior | `PersistedPublicIdentityRouteTest`, `HistoricPublicRouteResolverTest`, `PublicEntityRoutesTest` |
| SEO/projection eligibility and sitemap policy | `ArticleSeoGateTest`, `EntitySeoProjectionTest`, `PreferredImageSeoProjectionTest`, `MediaAssetDeliveryTest` |

## Known defects and blockers

These are recorded as baseline evidence, not implementation instructions:

1. The local WordPress/MySQL runtime is unavailable, so database schema state,
   persisted fixtures, repository hydration and WordPress route behavior are
   unverified at this checkpoint.
2. The local MCP HTTP endpoint is unavailable, so deployed MCP catalog parity,
   protocol behavior, connector exposure and live build identity are unverified.
3. Integration/live acceptance cannot be claimed. Unit and Contract tests are
   not substitutes for guarded `nhk_v3_test` integration evidence.
4. Existing execution/parity evidence records open deployment, V2 parity,
   public Media/Video readiness, migration review and cutover-readiness debt;
   those items remain separate from the Phase 0 baseline and must not be
   silently converted into architectural requirements.

## Verification performed

| Check | Result |
|---|---|
| `vendor/bin/phpunit --testsuite 'NHK Unit'` | PASS — 2,025 tests, 9,950 assertions; 17 warnings, 30 deprecations, 27 PHPUnit deprecations |
| `vendor/bin/phpunit --testsuite 'NHK Contract'` | PASS — 6 tests, 48 assertions |
| `php tools/deployment-preflight.php --expected-head=340c7493...` | 6 PASS, 5 environment-blocked FAILs |
| `php tools/mcp-wire-smoke.php --base-url=http://localhost` | BLOCKED — connection refused before protocol negotiation |
| Git status/diff check | PASS — clean before Phase 0; the only current uncommitted item is this Phase 0 evidence document |

## Gate 0 result

`EXIT_RESULT=BLOCKED_PENDING_RUNTIME`

The repository/docs/worktree baseline is recorded and no code/data/schema/live
mutation occurred. Gate 0 is not declared complete because `CURRENT_RUNTIME`
and real runtime-backed golden-case evidence cannot be verified while the
WordPress/MCP runtime is unavailable. Phase 1 must not start.

`NEXT_EXACT_ACTION=Restore or provide the approved read-only local WordPress/
MySQL and MCP runtime, then rerun deployment preflight, documentation/runtime
identity read-back, MCP wire smoke, guarded integration baseline and the
selected golden-case probes. Do not enable semantic writes or mutate data.`

## Gate 0 reconciliation addendum

The follow-up staging evidence supplied by the connected MCP operator is
recorded as externally produced environment evidence, not as repository law.
It reports staging at `https://demo.1945.vn` with source revision
`340c7493d06135d589b3f8e61310ac40080981f1`, runtime version `0.1.0`,
documentation version
`5129662cd44be50ea52123f35c8f59e446c3377e580d5b5ceed6245e358d7d04`, manifest
hash `bc1b265f42a3e6190d407fae60b296f4897972eae334587ca2096feac58bc62e`,
catalog version
`ac7bb409c18b9a3daea8e7f72aa47c95509b1d1234b082abc4d8015f99885650`, resource
version
`2644fca6bc74621085ce7271d21053ce7bff1e6011e842eee945015bb8d10c03`, build
identity `b44422e3243b593f45f0bb7dccf374be7b849f4560cd14faea2ba99df037e001`,
and release identity
`ec2fccdf068381f25ad3478a873976c021a14cc09f7e1901fc4be744cedb3dd8`.

An independent local probe using runtime version `0.1.0` produced source
revision `340c7493d06135d589b3f8e61310ac40080981f1`, documentation version
`5129662cd44be50ea52123f35c8f59e446c3377e580d5b5ceed6245e358d7d04`, manifest
hash `bc1b265f42a3e6190d407fae60b296f4897972eae334587ca2096feac58bc62e`,
catalog version
`ac7bb409c18b9a3daea8e7f72aa47c95509b1d1234b082abc4d8015f99885650`, resource
version
`2644fca6bc74621085ce7271d21053ce7bff1e6011e842eee945015bb8d10c03`, local
build identity
`eb33796c296a12c8e09e8839e1e4fb6944e272fc61caef4e5fdf44c7336626cd`, and
local release identity
`f9a5630503fb01b0003a18eae0310105163cd73db1c0eb775d17f514f6749a45`.

Identity classification:

- `SOURCE_REVISION`: `MATCH`.
- `DOCUMENTATION_VERSION`: `MATCH`.
- `MANIFEST_HASH`: `MATCH` after using the same runtime version; the earlier
  local `4ff48e...` value came from a probe explicitly using runtime version
  `local` and is an incomparable probe, not source variance.
- `CATALOG_VERSION`: `MATCH`.
- `RESOURCE_VERSION`: `MATCH`.
- `BUILD_IDENTITY`: `UNEXPLAINED_MISMATCH` pending deployment-package
  provenance; this identity hashes the installed plugin package, not only Git
  source revision.
- `RELEASE_IDENTITY`: `UNEXPLAINED_MISMATCH` because it incorporates runtime
  identity including the build identity and environment.

The supplied staging report lists successful read-only documentation, runtime,
inventory, public URL audit, WordPress post, Article, Media, Video and
Knowledge calls. This establishes `TARGET_RUNTIME_EVIDENCE=REPORTED`, but
current connector exposure is not independently callable from this Codex
session. The report must retain producer, timestamp, evidence ID and
canonical read-back payload hashes before it is promoted to fully verified
Gate 0 evidence.

The single Program Ledger and machine-readable evidence packet schemas are
defined by the follow-up Gate 0 task but are not physically created here: no
repository Program Ledger currently exists, and the task explicitly keeps
Gate 0 read-only until the identity discrepancy and evidence provenance are
resolved. `V3_EXECUTION_STATE.md` remains dated execution evidence, not a
silently created second Program Ledger.

Updated verification at this addendum:

- Unit: `2025 tests / 9951 assertions`, PASS with 17 warnings, 30
  deprecations and 27 PHPUnit deprecations.
- Contract: `6 tests / 48 assertions`, PASS.
- Local deployment preflight: 6 PASS and 5 failures caused by unavailable
  WordPress bootstrap/runtime.
- Local MCP wire smoke: blocked before protocol negotiation because
  `http://localhost:80` refused the connection.
- Current uncommitted change classification: this baseline document is
  `PROGRAM_WORK`; no source/code/schema/data/deployment mutation occurred.

`EXIT_RESULT=CONDITIONAL_PENDING_BUILD_IDENTITY_AND_EVIDENCE_PROVENANCE`
