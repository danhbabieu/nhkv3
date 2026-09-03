# NHK V3 Content Operations — Final Completion Plan

Status: implementation plan, 2026-09-03. The Constitution remains the sole
normative authority. This plan introduces no entity type, endpoint, predicate,
field, or live-data operation.

## Baseline and constraints

- Continue on the current working tree; preserve the existing execution-state
  and acceptance-matrix changes.
- Reuse `ArticleIngestCoordinator`, `GovernanceService`, `ControlledApplyService`,
  `CategoryGateway`, `EditorialDraftGateway`, `ArticleResearchPreflight`,
  `ArticleMediaCoordinator`, and the current MCP catalog.
- Never migrate/import legacy article bodies, mutate V2/staging/production, or
  seed/repair semantic records. `nhk_v3` is non-destructive; only an exact,
  guarded `nhk_v3_test` may be used for destructive integration setup.
- Every slice has RED → minimal implementation → focused GREEN → regression,
  lint, Composer validation, diff/secret review, execution-state update and a
  logical checkpoint commit.

## Checkpoint map

### CP1 — Article operation and publication boundary

Files: `src/Application/Article/*`, `src/Contracts/Article/*`,
`src/Domain/Article/*`, `src/Application/WordPress/*`,
`src/Infrastructure/WordPress/*`, and corresponding Unit/Contract tests.

RED: prove publication is blocked unless research, category, semantic
read-back, media usage, SEO/public route, and claim checks are all verified;
prove an exact draft with a matching CAS token is the only publish target;
prove trash/restore semantics remain idempotent and permanent delete is absent.

Implementation: add the smallest `ArticlePublicationGate` and explicit
publication evidence/result contracts; extend the existing editorial store
only for approved native publish/trash/restore operations; keep the receipt
body-free and retry/read-back safe. Do not duplicate preflight or Governance
rules.

GREEN: focused gate/writer/CAS/idempotency tests, then Article regressions.
Document the sequence in the Article Ingest and MCP contracts.

### CP2 — Typed semantic operation parity

Files: existing Knowledge/Source/Evidence and Authority application services,
MCP typed facade/transport/catalog/manifest, Admin consumers, and focused
parity tests.

RED: catalog/manifest/handler mismatch tests and fail-closed lifecycle tests.
Implementation: expose only operations already backed by Governance and
read-back; add no generic semantic writer and keep Merge explicitly partial
until every reference adapter and verification path exists.

GREEN: MCP contract, governance, authority, knowledge and manifest tests.

### CP3 — MediaUsage and editorial album orchestration

Files: existing MediaUsage contracts/repositories/coordinator, Article media
application path, native editorial adapter, Admin/MCP consumers, and tests.

RED: exactly one distinct featured/inline primary, supporting usages bounded by
contract, placeholder incompleteness, reuse-before-ingest, no Graph inference,
and Post + gallery representation without an Album semantic entity.

Implementation: extend the current coordinator and native WordPress boundary;
do not add a second media persistence path or legacy repair.

### CP4 — Graph read exposure and relation lifecycle parity

Files: `RelatedSemanticQuery`, `GraphService`, MCP read facade/catalog only if
the existing contract allows the smallest reader-safe surface, Admin query
consumer, and tests.

RED: direction, two-hop maximum, cycle safety, deduplication, direct
precedence, explainable path and public eligibility checks.

Implementation: delegate to the shared registry-driven reader; never expose
raw edges, taxonomy/postmeta fallback, inverse predicates, or technical IDs in
public serialization.

### CP5 — Projection, claim compliance and rendered verification

Files: existing SEO/public projection contracts and services, claim-compliance
boundary, theme/REST serializers, rendered verification adapters, tests and
docs.

RED: unsupported superiority/uniqueness claims, scope mismatch, channel drift,
fabricated structured data and stored-vs-rendered mismatch.

Implementation: reuse the shared contract and fail closed when policy/evidence
or runtime is unavailable; generated copy is never Evidence.

### CP6 — Admin operational parity and module configuration audit

Files: `Infrastructure/Admin/AdminPage.php`, shared application consumers,
capability manifest, Admin permission tests and documentation.

Implementation: organize practical read/write sections around existing
services; keep raw proposal composition advanced/debug-only; modules remain
functional projections and never semantic entities.

### CP7 — Guarded integration and acceptance evidence

Files: integration/end-to-end tests, `TestDatabaseGuard`, deployment/runtime
diagnostics and execution state.

Diagnose service/socket/credentials/bootstrap and exact `nhk_v3_test` only.
If unavailable, retain `IMPLEMENTATION_READY_RUNTIME_BLOCKED`; never redirect
to `nhk_v3` or claim runtime readiness.

### CP8 — Documentation and final readiness report

Cross-link the router/contracts, capability manifest, parity matrix, execution
state and this plan. Produce the consolidated report with Git/MCP/Admin/
Article/Semantic/Media/Test/Infrastructure/gaps/final classification. Do not
claim production readiness or external publish.

## Self-review

- Coverage: Article, Authority, Knowledge, Source, Evidence, Graph, Media,
  Video, Category, Post, Album, Product/Specimen, modules, Admin and runtime
  evidence are mapped above.
- Ownership: all writes route through existing bounded services and Governance;
  WordPress remains editorial truth; Graph remains the relation store.
- No duplicate persistence path: the plan explicitly reuses current gateways.
- Advertised operations: CP2/CP4 add no catalog entry unless a handler,
  permission, capability, idempotency/read-back semantics and tests exist.
- Known human/runtime gates: live data operations, final cutover, unresolved
  constitutional conflicts, and an unavailable exact integration runtime stop
  only the affected verification/operations, not safe unit implementation.
