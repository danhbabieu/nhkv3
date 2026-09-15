# NHK V3 Unified Capture / Media / Editorial implementation plan

## 0. Contract, authority and execution boundary

This is an executable implementation plan derived from the canonical design
spec at
`docs/superpowers/specs/2026-09-15-unified-capture-media-editorial-design.md`.
The Constitution and ACTIVE contracts remain the authority. The attached base
design and deep-review addendum are source material; neither is a second
authority and neither authorizes implementation by itself.

This plan is documentation-only. The planning checkpoint performs no source
code change, test change, schema change, migration, deployment, SSH, server
write, staging or production mutation, V2 operation, push, publication or
runtime workaround. The server state is explicitly:

`SERVER_WORKTREE_DIRTY — OUT_OF_SCOPE — PRESERVED`

The only future implementation workspace is
`/Users/imac24-2125d/Developer/nhk-v3`. The target branch
`codex/unified-capture-media-editorial-design-20260915` was verified at the
initial preflight against the exact target base
`744704f5cdbacce0be5f7e9bb71b33df4946b516`; canonical design commit
`d39d8393` is present. A final metadata fetch observed `origin/main` at
`cdbc5b0b23781313ea60e50b6039401ed7b1cf9e` while the remote target branch
remained at `744704f5cdbacce0be5f7e9bb71b33df4946b516`. The local branch is
intentionally not rebased or merged with that newer main; this plan preserves
the exact target branch base.

### 0.1 Non-negotiable order

Every future coding session follows:

`RED test → smallest GREEN change → canonical read-back → focused regression → next slice`

The order is strict:

1. Approve the two constitutional amendments below.
2. Update the subordinate contracts and executable registries.
3. Add failing tests for the newly legal vocabulary and invariants.
4. Implement one bounded vertical slice at a time.
5. Run owner read-back, dependency/readiness checks and the applicable public
   projection checks before advancing.
6. Keep auto-public disabled until the deployed runtime proves every required
   capability and the publication gate returns `PASS`.

No migration is planned before the corresponding contract and failing tests
are approved. No live acceptance is planned until the local implementation
and release gates pass.

## 1. Constitutional amendments before implementation

### Amendment A — media-only Content Intent

Current executable reality is `VIDEO`, `IMAGE_ARTICLE`, `TEXT_ARTICLE` and
`KNOWLEDGE_DELTA` in
`public/wp-content/plugins/nhk-core/src/Domain/Capture/ContentIntent.php` and
the MCP schema in
`public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`.
`MEDIA_ENRICHMENT` is not implemented. It must not be emulated by
`KNOWLEDGE_DELTA`.

The owner amendment must update the Content Intent law in
`docs/constitution/NHK_V3_CONSTITUTION.md` in the amendment record governing
Content Intent before Article owner creation, while preserving the existing
four values and their behavior. It must also update the ACTIVE contract set:

- `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`;
- `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`;
- `docs/architecture/ARTICLE_INGEST_CONTRACT.md`;
- `docs/architecture/04_MEDIA_MODEL.md`;
- `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`;
- `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`; and
- `docs/architecture/VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md` where the
  Capture-to-visual-support handoff is described.

The amendment must state that this intent creates one Capture, may adopt
existing physical Media, never creates an Article implicitly, and completes
only after Media semantic enrichment, rights/readiness, governed
reconciliation and canonical read-back. Direct owner writers remain guarded
compatibility boundaries.

### Amendment B — one real image for `IMAGE_ARTICLE`

The current Article media law is the amendment in
`docs/constitution/NHK_V3_CONSTITUTION.md` that requires one
`FEATURED_PRIMARY` and one `INLINE_PRIMARY` with different Media identities.
The owner amendment must add the narrow exception: one eligible real Media may
satisfy the Article's featured visual and the inline visual when there is only
one submitted real image. It must not permit a fake placeholder, a duplicate
Media identity, or repeated body placement to bypass the rule. N-image input
retains normal album behavior.

The affected ACTIVE contracts are:

- `docs/architecture/04_MEDIA_MODEL.md`;
- `docs/architecture/ARTICLE_INGEST_CONTRACT.md`;
- `docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md`;
- `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`;
- `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`;
- `docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md`; and
- `docs/architecture/VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md` for mandatory
  visual dependency behavior.

The amendment must retain the distinction between Article featured state and
entity representative state. Existing records are not migrated or repaired by
this plan.

### Amendment slice card

- Purpose: make both target behaviors constitutional before any implementation
  can expose them.
- Dependencies: owner approval; Constitution review; current registry and
  contract bootstrap.
- Existing executable reality: neither amendment is implemented as a new
  runtime capability; existing Article media law remains active.
- Exact files: the Constitution and ACTIVE contract files listed above;
  `ContentIntent.php`; `McpToolCatalog.php`; `ContentIntentRouter.php`;
  `ContentIntentRouterTest.php`; `ArticleMediaPolicyTest.php`.
- Exact classes/methods: `ContentIntent`; `ContentIntentRouter::route()` and
  `assertExplicitIntentIsValid()`; `ArticleMediaCoordinator::ensureForPost()`;
  `ArticlePublicationGate::check()`.
- Contract/doc changes: record amendment rationale, compatibility, fail-closed
  invalid intent, one-image exception, and no-placeholder/no-duplicate rules.
- Schema/migration impact: none for the amendment record itself; any later
  usage revision, placement identity or title extension is a separate slice.
- Failing tests first: extend `ContentIntentRouterTest` for unknown and new
  intent behavior, and `ArticleMediaPolicyTest` for one real Media without a
  second identity; expected current failures are unknown intent rejection and
  the existing two-slot/different-identity blocker.
- Smallest implementation change: after approval, register the new enum/schema
  value and change only the Article media policy branch permitted by the
  amendment; do not touch unrelated owner services.
- Regression tests: all existing intent, capture, article-media and publication
  gate tests; explicit proof that Video and Knowledge Delta still do not create
  an Article.
- Read-back evidence: Constitution amendment, contract manifest, serialized
  MCP schema, resolved intent, MediaUsage set and Article state all show the
  same approved policy version.
- Failure modes: unresolved amendment, registry mismatch, stale documentation,
  invalid explicit intent or old runtime returns `CONSTITUTION_CONFLICT`,
  `DOCUMENTATION_CHECKPOINT_STALE` or `CONTENT_INTENT_UNAVAILABLE`.
- Rollback implications: before release, remove only the unshipped registry and
  contract changes; no existing record is rewritten. After release, use a new
  governed policy revision, never silent interpretation of old Captures.
- Completion criteria: both amendments are owner-approved, active docs agree,
  the runtime registry is updated, RED tests exist and no code slice starts
  before this gate.
- Stop conditions: any conflict with the Constitution, owner changes the
  Article/Media owner boundary, or an identity merge is proposed.

## 2. Vertical implementation slices

Each slice below is independently reviewable. The named existing files and
methods are the implementation anchors discovered during inventory. A
`CODE_GAP` means no existing owner or method was found; it is not permission
to invent a parallel owner.

### Slice 1 — Content Intent registry and Capture contract

- Purpose: add approved `MEDIA_ENRICHMENT` to the executable vocabulary and
  preserve explicit-intent precedence and fail-closed ambiguity.
- Dependencies: Amendment A; documentation checkpoint; existing four-intent
  compatibility tests.
- Existing executable reality: `ContentIntent` has four enum cases;
  `ContentIntentRouter::route()` uses explicit intent or heuristics; the MCP
  catalog repeats the enum inline.
- Exact files: `public/wp-content/plugins/nhk-core/src/Domain/Capture/ContentIntent.php`;
  `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentIntentRouter.php`;
  `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`;
  `public/wp-content/plugins/nhk-core/src/Application/Plugin.php` only if
  constructor wiring requires it; and
  `public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php`.
- Exact classes/methods: `ContentIntent`; `ContentIntentRouter::route()`;
  `signals()`; `assertExplicitIntentIsValid()`; `result()`; catalog intent
  schema builder at the registered Capture descriptor.
- Contract/doc changes: update Content Operations and Control Plane schemas,
  completion owner matrix and compatibility notes; keep `MEDIA_ENRICHMENT`
  proposed until the amendment is merged.
- Schema/migration impact: none; Capture already persists intent in context.
- Failing tests first: explicit `MEDIA_ENRICHMENT` accepted only after registry
  approval; image-less media-only input rejected; ambiguous subject returns
  review-required; current expected failure is missing enum/schema value.
- Smallest implementation change: centralize the registered values so transport
  and router cannot drift; add one `article_required=false`, `media_required`
  result branch without creating an owner service.
- Regression tests: all current `ContentIntentRouterTest` cases, Capture
  convergence tests and MCP contract serialization tests.
- Read-back evidence: router result, catalog descriptor, Capture context and
  completion children report the same intent and owner list.
- Failure modes: stale manifest, unknown intent, missing physical payload,
  client schema mismatch; return typed blocked/review state, never heuristic
  substitution.
- Rollback implications: registry-only rollback is safe before any Capture uses
  the value; after use, preserve stored intent and require a governed replay
  policy.
- Completion criteria: one canonical registry value, schema parity, RED/GREEN
  tests and no direct writer invocation.
- Stop conditions: registry value differs from approved amendment or another
  subsystem claims ownership of intent resolution.

### Slice 2 — One Capture orchestration and durable receipts

- Purpose: make all new input converge through one Capture and make phase
  completion/resume explicit across owner boundaries.
- Dependencies: Slice 1; Capture repository and addendum contracts; current
  documentation checkpoint.
- Existing executable reality: `EditorialCaptureCoordinator` already owns
  `execute()`, `continueWithAddendum()`, phase receipts, owner requirements,
  `save()` and `completionChildren()`; `WpdbCaptureRepository` has idempotency
  and revision CAS; `WpdbCaptureAddendumRepository` has addendum revision CAS.
- Exact files: `src/Application/Capture/EditorialCaptureCoordinator.php`;
  `src/Application/Capture/EditorialCaptureContinuationService.php`;
  `src/Infrastructure/Capture/WpdbCaptureRepository.php`;
  `src/Infrastructure/Capture/WpdbCaptureAddendumRepository.php`;
  `src/Domain/Capture/CaptureRecord.php`; and
  `tests/Unit/EditorialCaptureConvergenceE2ETest.php`,
  `tests/Unit/EditorialCaptureContinuationTest.php`,
  `tests/Unit/EditorialCaptureMigration017Test.php`.
- Exact classes/methods: `EditorialCaptureCoordinator::execute()`;
  `continueWithAddendum()`; `run()`; `requiredOwners()`; `save()`;
  `completionChildren()`; `startReceipt()`; `failureStatus()`;
  `EditorialCaptureContinuationService::execute()`; repository `create()`,
  `save()` and `findByIdempotencyKey()`.
- Contract/doc changes: define phase receipt schema, retry boundary, body-free
  diagnostics and one-Capture/one-Article rule for Article intents.
- Schema/migration impact: likely no new Capture table columns; verify whether
  dependency fingerprints need a JSON contract extension before proposing a
  sequential UP migration. No migration is authorized in this slice.
- Failing tests first: same key/same payload reuse, changed payload conflict,
  concurrent identical reservation, cross-owner partial state and replay from
  failed receipt; current failure is incomplete convergence or missing
  child-selection evidence.
- Smallest implementation change: extend the existing phase receipt/context
  contract and `requiredOwners()`; retain existing owner callbacks and revision
  CAS instead of introducing a global transaction.
- Regression tests: Capture idempotency, addendum replay, video-only and
  knowledge-only no-Article paths, and `EditorialCaptureMigration017Test`.
- Read-back evidence: one Capture UUID, request fingerprint, phase receipts,
  owner IDs/revisions, completion children and final status are persisted and
  reloaded from the repository.
- Failure modes: `IDEMPOTENCY_CONFLICT`, stale revision, unavailable child,
  `PARTIAL`, `SYSTEM_BLOCKED`, hydration loss; no success collapse.
- Rollback implications: retain successful child records and resume failed
  phase; do not delete Media, Article, Video or semantic owners to simulate
  rollback.
- Completion criteria: replay is idempotent, partial state is resumable, and
  `COMPLETE` requires all required owner read-backs.
- Stop conditions: a design requires a cross-database transaction, ad-hoc owner
  deletion or a new Capture semantic owner.

### Slice 3 — Physical widget transport to Capture handoff

- Purpose: separate physical upload/presentation success from semantic Capture
  completion and reuse materialized Media IDs.
- Dependencies: Slice 2; native attachment and governed Media contracts.
- Existing executable reality: `McpTransport::widgetUpload()` and
  `ImageIngestEntrypoint::ingest()` handle the physical path; widget upload
  returns attachment/Media data but does not complete Capture; `captureIngest()`
  enforces `files` versus `media_ids` mutual exclusion.
- Exact files: `src/Application/Mcp/McpTransport.php`;
  `src/Application/Media/ImageIngestEntrypoint.php`;
  `src/Application/Media/MediaBatchUploadService.php`;
  `src/Application/Capture/EditorialCaptureCoordinator.php`;
  `tests/Unit/McpWidgetUploadTest.php`;
  `tests/Unit/CaptureMediaIdsContractTest.php`;
  `tests/Unit/CaptureMediaIdsReuseTest.php`; and
  `tests/Integration/McpTransportIntegrationTest.php`.
- Exact classes/methods: `McpTransport::dispatch()`; `callTool()`;
  `widgetUpload()`; `captureIngest()`; `batchUpload()`;
  `ImageIngestEntrypoint::ingest()`; coordinator `run()` and asset identity
  handling.
- Contract/doc changes: document two distinct outcomes: physical transport
  receipt and Capture completion receipt; require ordered `media_ids` reuse.
- Schema/migration impact: none unless a transport receipt field is absent
  after contract verification; prefer existing Capture phase receipts.
- Failing tests first: widget success followed by Capture reuse with ordered
  IDs; widget-only result must not be `COMPLETE`; mutual exclusion; stale or
  empty connector result must be typed.
- Smallest implementation change: pass a body-free physical receipt and
  `media_ids` into Capture; never invoke a second uploader or infer semantic
  completion from the widget response.
- Regression tests: widget contract, batch upload, asset follow-up and MCP
  integration tests.
- Read-back evidence: attachment read-back, Media read-back, Capture asset
  manifest and final owner completion all match by ordered canonical IDs.
- Failure modes: `SERVER_TOOL_EMPTY_RESULT`, `CLIENT_EXPOSURE_GAP`, attachment
  read-back failure, Media unavailable, Capture `PARTIAL`; none is semantic
  success.
- Rollback implications: preserve already materialized physical assets and
  resume Capture; only temporary non-canonical artifacts may be cleaned by the
  existing storage owner.
- Completion criteria: one upload path, one Capture, no re-upload on replay,
  and transport/semantic statuses remain distinguishable.
- Stop conditions: client requires a writer bypass, widget result has no stable
  handoff identity, or runtime tools cannot expose the registered boundary.

### Slice 4 — Partial multi-image batch and resumable child recovery

- Purpose: retain successful per-item results while resuming only failed or
  incomplete items without publishing an unintended subset.
- Dependencies: Slice 2 and 3; upload batch policy and attachment lifecycle.
- Existing executable reality: `MediaBatchUploadService::upload()` already has
  per-item results, checksum, limits and partial outcome; physical batch is not
  one global transaction.
- Exact files: `src/Application/Media/MediaBatchUploadService.php`;
  `src/Infrastructure/Media/WpOptionMediaBatchUploadRepository.php`;
  `src/Application/Capture/EditorialCaptureCoordinator.php`;
  `src/Application/Capture/EditorialCaptureContinuationService.php`;
  `tests/Unit/MediaBatchUploadServiceTest.php`;
  `tests/Unit/EditorialCaptureContinuationTest.php`; and
  `tests/Unit/EditorialCaptureConvergenceE2ETest.php`.
- Exact classes/methods: `MediaBatchUploadService::upload()`;
  `flattenFiles()`; `normalizeItems()`; `fileFingerprint()`;
  `EditorialCaptureCoordinator::run()`; continuation `execute()`;
  `safeItems()`; `safeManifest()`.
- Contract/doc changes: define item status, batch manifest, retry selection,
  required item count and the rule that 4/5 valid items remain `PARTIAL`.
- Schema/migration impact: use existing option/repository reservation and
  Capture JSON receipts first; add a migration only if repository proof shows
  the manifest cannot be durably represented.
- Failing tests first: five files with one corrupt, replay of one child,
  successful children not re-uploaded, order retained, no album publication
  with missing required child; current failure is insufficient child status or
  accidental rerun.
- Smallest implementation change: persist per-item stable client/checksum
  identity and retry boundary; keep hard server limits aligned with the runtime
  policy registry.
- Regression tests: byte/size/type validation, duplicate idempotency, batch
  reservation race and existing partial upload tests.
- Read-back evidence: each item has attachment/Media IDs or typed diagnostic;
  Capture phase receipt lists completed and retryable child identities.
- Failure modes: corrupt file, limit exceeded, cleanup failure, idempotency
  conflict, infrastructure unavailability; `PARTIAL` is not publication.
- Rollback implications: keep four successful physical results; do not delete
  them to manufacture all-or-nothing semantics.
- Completion criteria: only failed/incomplete children retry and Capture cannot
  enter publication-ready state while a required child is missing.
- Stop conditions: batch implementation requires global transaction, silent
  subset publish or destructive cleanup of valid canonical owners.

### Slice 5 — Media schema, Asset/Usage model gaps and contextual title owner

- Purpose: preserve one Media identity while supporting contextual placement,
  safe concurrency and Article-specific image title metadata.
- Dependencies: Amendments A/B; Media model, P6, Article media and public image
  contracts; Slice 3.
- Existing executable reality: `MediaService::ingest()` and `addUsage()` reuse
  Media; `MediaUsage` has role, alt and caption but no title or revision;
  `WpdbMediaUsageRepository::update()` lacks CAS; migration 004 has a unique
  Media/endpoint/role identity.
- Exact files: `src/Domain/Media/Media.php`;
  `src/Domain/Media/MediaAsset.php`;
  `src/Domain/Media/MediaUsage.php`;
  `src/Application/Media/MediaService.php`;
  `src/Infrastructure/Media/WpdbMediaUsageRepository.php`;
  `src/Infrastructure/Migration/MediaMigration004.php`;
  `src/Contracts/Media/MediaUsageRepository.php`;
  `src/Contracts/Media/MutableMediaUsageRepository.php`;
  `tests/Unit/MediaServiceCompletionTest.php`;
  `tests/Unit/MediaUsageReconcilerTest.php`; and
  `tests/Unit/WpdbMediaAssetRepositoryTest.php`.
- Exact classes/methods: `MediaService::ingest()`; `update()`; `addUsage()`;
  `upsertUsage()`; `MediaUsage`; repository `create()`, `update()`,
  `listByMediaId()` and `retire()`.
- Contract/doc changes: decide the smallest approved extension: contextual
  Article title belongs to the Article managed placement/block metadata, not
  global attachment title; usage revision and stable placement identity must
  be explicit if repeated placement is supported.
- Schema/migration impact: `MediaUsage` title/revision/placement extension is
  `CODE_GAP`; no exact future migration file exists. After contract approval,
  create the next sequential UP migration only after rechecking the migration
  ledger; never add an unregistered field ad hoc.
- Failing tests first: usage update race, repeated placement identity, title
  isolation between two Articles, global Media canonical name fallback; current
  failure is missing usage revision/title and uniqueness rejects repeated role.
- Smallest implementation change: first store title in the existing Article
  managed block metadata and add usage CAS/placement only if the contract proves
  it is necessary; do not overload WordPress attachment title.
- Regression tests: Media reuse, contextual alt/caption, usage role registry,
  Media migration and public projection tests.
- Read-back evidence: Media UUID remains one; each Article has an independent
  placement/anchor and title; MediaUsage revision is checked before update.
- Failure modes: usage revision conflict, ambiguous placement, duplicate
  checksum with different identity, stale Media revision; fail closed.
- Rollback implications: contract/data extension must be additive and preserve
  existing usage rows; title projection can be disabled without changing Media.
- Completion criteria: title ownership is singular, usage writes have CAS where
  required, repeated placements do not depend on array index or short ID.
- Stop conditions: proposed title owner becomes global attachment metadata,
  MediaUsage is made semantic truth, or a migration is requested before contract
  approval.

### Slice 6 — Article-scoped anchors, single-image Article and album composition

- Purpose: compose one native Article for Article intents, bind stable
  Article-owned image placements, and preserve N-image order without fake Media.
- Dependencies: Amendment B; Slice 5; WordPress Article CAS; MediaUsage
  reconciliation.
- Existing executable reality: `EditorialDraftGateway` creates/updates native
  Posts with receipts and `state_token`; `ArticleMediaCoordinator::ensureForPost()`
  currently enforces two slots; `ArticleComposer::compose()` removes owned
  sections by key but no structural Gutenberg marker parser is present.
- Exact files: `src/Application/WordPress/EditorialDraftGateway.php`;
  `src/Application/Media/ArticleMediaCoordinator.php`;
  `src/Application/Semantic/ArticleComposer.php`;
  `src/Infrastructure/WordPress/WpEditorialPostStore.php`;
  `src/Domain/Article/EditorialStateToken.php`;
  `tests/Unit/ArticleMediaPolicyTest.php`;
  `tests/Unit/ArticleOperationReceiptTest.php`;
  `tests/Unit/ArticleIngestCoordinatorTest.php`;
  `tests/Unit/ArticleSemanticDossierTest.php`.
- Exact classes/methods: `EditorialDraftGateway::create()`; `update()`;
  `publish()`; `ArticleMediaCoordinator::ensureForPost()`;
  `diagnoseForPost()`; `reconcileUsage()`; `ArticleComposer::compose()`;
  `removeOwnedSections()`; `WpEditorialPostStore::createDraft()` and its
  update/read methods.
- Contract/doc changes: define Article placement identity, anchor ownership,
  one-real-image exception, one Post for N images, and native WordPress title/
  body/order authority.
- Schema/migration impact: Article body metadata can use existing managed
  section payload first. A persisted placement extension is `CODE_GAP` until
  usage data-model approval; no body migration or legacy parsing.
- Failing tests first: one eligible real image supplies both allowed Article
  visual roles without duplicate Media; N images create one Post and ordered
  placements; same Media in two Articles gets two anchors; reorder retains
  anchors; current failure is the two-distinct-Media invariant and missing
  anchor semantics.
- Smallest implementation change: amend `ArticleMediaCoordinator` policy and
  composer packet to carry stable placement identity; keep WordPress body as
  anchor owner and use structured block markers rather than raw replacement.
- Regression tests: Article CAS, operation receipt, media policy, composer and
  rendered verifier tests.
- Read-back evidence: native Post has one ID/state token, ordered managed
  blocks, featured state, MediaUsage IDs and Article-scoped anchors; no second
  Media is created.
- Failure modes: state-token conflict, human edit, placeholder-only visual,
  missing requirement, route collision; return review/blocked state.
- Rollback implications: failed composition leaves native draft and valid Media
  intact; re-run against fresh state token; no body deletion or Media deletion.
- Completion criteria: single image exception and album path both pass with
  exact Article owner read-back and stable placement identities.
- Stop conditions: Article body copied into semantic store, duplicate Post,
  Media ID used as global anchor, or raw string replacement overwrites a human
  edit.

### Slice 7 — Shared semantic research core and owner adapters

- Purpose: reuse one bounded interpretation/resolution/retrieval sequence while
  retaining Video, Media, Knowledge and Article ownership boundaries.
- Dependencies: Slice 2; Authority/Graph/Knowledge contracts; Article preflight;
  Video semantic contracts.
- Existing executable reality: Capture injects `TextInputInterpreter`,
  `SubjectResolutionService`, `ClaimRetrievalEngine`; `VideoInternalSemanticResearcher`
  and `VideoRelationCandidatePlanner` are Video-specific; `KnowledgeEnrichmentPlanner`
  and `CurrentTruthResolver` are Knowledge-specific; no god service is present.
- Exact files: `src/Application/Semantic/TextInputInterpreter.php`;
  `src/Application/Semantic/SubjectResolutionService.php`;
  `src/Application/Semantic/ClaimRetrievalEngine.php`;
  `src/Application/Article/ArticleResearchPreflight.php`;
  `src/Application/Article/ArticleIngestPreflight.php`;
  `src/Application/Video/VideoInternalSemanticResearcher.php`;
  `src/Application/Video/VideoRelationCandidatePlanner.php`;
  `src/Application/Knowledge/KnowledgeEnrichmentPlanner.php`;
  `src/Application/Knowledge/CurrentTruthResolver.php`;
  `tests/Unit/EditorialCaptureSemanticCoreTest.php`;
  `tests/Unit/ArticleResearchPreflightTest.php`; and
  `tests/Unit/CaptureVideoProvenancePlannerTest.php`.
- Exact classes/methods: `TextInputInterpreter::interpret()`;
  `SubjectResolutionService::resolve()`; `ClaimRetrievalEngine::retrieve()`;
  `ArticleResearchPreflight::research()`; `ArticleIngestPreflight::check()`;
  `VideoInternalSemanticResearcher::research()`;
  `VideoRelationCandidatePlanner::plan()`;
  `KnowledgeEnrichmentPlanner::plan()`; `CurrentTruthResolver::resolve()`;
  Capture `run()` remains the orchestration caller.
- Contract/doc changes: document shared packet fields—canonical IDs/revisions,
  scope, evidence state, provenance, dependency fingerprint—and owner-specific
  extensions.
- Schema/migration impact: none; packet/receipt JSON only unless a current
  owner contract proves durable state is required.
- Failing tests first: same resolved subject reaches Media/Video/Article
  consumers; Graph reachability alone cannot authorize Claim reuse; bounded
  claims preserve original subject/scope; current failure is duplicated or
  broadened orchestration.
- Smallest implementation change: extract only a typed read-only packet or
  adapter around existing services; do not merge owner policies or create a
  shared semantic repository.
- Regression tests: existing semantic core, Video relation, Article preflight,
  Knowledge reuse and Capture convergence tests.
- Read-back evidence: packet fingerprint and each owner read-back retain the
  original subject, claim/source/evidence IDs and revisions.
- Failure modes: ambiguous subject, weak relation, stale claim, unavailable
  dependency; remain review/blocked and never become a convenient relation.
- Rollback implications: adapter removal leaves canonical owner records and
  existing Capture receipts intact; no semantic delete.
- Completion criteria: one shared sequence, no broadened Graph traversal, no
  owner policy leakage, and all selected claims remain explainable.
- Stop conditions: a proposed service owns Article, Media, Video or Knowledge,
  creates Graph edges from similarity/keywords, or copies generated prose as
  Evidence.

### Slice 8 — External research provider and Source/Evidence handoff

- Purpose: add bounded read-only external discovery without pretending that
  snippets or generated prose are canonical evidence.
- Dependencies: Slice 7; `06_KNOWLEDGE_SOURCE_MODEL.md`;
  `GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`;
  `ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md`; claim compliance law.
- Existing executable reality: `ArticleResearchPreflight` and Knowledge
  repositories exist; no external Internet provider abstraction or durable
  research Source/Evidence ingest was found. This is a `CODE_GAP` and
  `RUNTIME_GAP`, not an available capability.
- Exact files: `src/Application/Article/ArticleResearchPreflight.php`;
  `src/Domain/Article/ArticleResearchResult.php`;
  `src/Application/Knowledge/KnowledgeService.php`;
  `src/Application/Knowledge/CanonicalDependencyValidator.php`;
  `src/Infrastructure/Knowledge/WpdbSourceRepository.php`;
  `src/Infrastructure/Knowledge/WpdbEvidenceRepository.php`;
  `src/Infrastructure/Knowledge/WpdbKnowledgeRepository.php`;
  `tests/Unit/ArticleResearchPreflightTest.php`;
  `tests/Unit/KnowledgeEnrichmentPlannerTest.php`;
  `tests/Unit/GovernedLivingKnowledgeDomainTest.php`; and
  `tests/Integration/P4GovernanceAcceptanceIntegrationTest.php`.
- Exact classes/methods: `ArticleResearchPreflight::research()`;
  `KnowledgeService::createClaim()`; `createSource()`; `cite()`;
  `citeWithId()`; `evidenceForClaim()`; `CanonicalDependencyValidator::claim()`;
  `source()`; `evidence()`; `KnowledgeRepository::findByCanonicalId()`;
  `findByStableKey()`; `create()`; `update()`; `SourceRepository::findByCanonicalId()`;
  `findByStableKey()`; `create()`; `update()`; `EvidenceRepository::findByCanonicalId()`;
  `create()`; `update()`; `listByClaim()`; `listBySource()`.
  A provider interface is `CODE_GAP` and must be named only after contract
  approval.
- Contract/doc changes: define query/result/source budgets, provider
  visibility, URL/locator privacy, conflict evaluation, and the rule that
  snippets are discovery only. External output must become Source/Evidence
  through the governed owner path before public claim use.
- Schema/migration impact: none for preview packet; Source/Evidence fields
  already support canonical provenance. Any provider receipt extension requires
  a reviewed UP migration, not a research cache table invented by Capture.
- Failing tests first: provider unavailable with sufficient internal evidence
  proceeds; provider unavailable with unsupported mandatory claim blocks;
  snippet-only output cannot satisfy Evidence; conflict is not public copy.
- Smallest implementation change: add a read-only provider adapter at the
  Article research boundary and pass validated candidates to existing
  Source/Evidence/Governance owners; do not write directly from provider.
- Regression tests: Article preflight, claim compliance, Knowledge dependency,
  Governance proposal binding and Video provenance tests.
- Read-back evidence: query receipt is body-free; any persisted Source/Evidence
  has subject scope, locator, provenance, source/claim revisions and governed
  lifecycle read-back.
- Failure modes: provider timeout, SSRF policy rejection, quota exhaustion,
  unsupported claim, source conflict; typed unavailable/review/block state.
- Rollback implications: disable provider adapter and keep internal evidence
  path; never delete existing Source/Evidence because an external provider is
  unavailable.
- Completion criteria: external research is optional when internal evidence is
  sufficient and mandatory only for claims explicitly requiring it; no snippet
  is Evidence.
- Stop conditions: missing credentials, provider would become semantic owner,
  public claim lacks Source/Evidence locator, or provider failure is silently
  treated as success.

### Slice 9 — Governed Source/Evidence, Claim trace and invalidation

- Purpose: preserve canonical Claim/Source/Evidence ownership, stale claim
  traces and governed replacement/invalidation.
- Dependencies: Slice 7 and 8; Governance contracts;
  `GOVERNANCE_FAILURE_AND_RETRY.md`.
- Existing executable reality: `KnowledgeService`, repositories,
  `CanonicalDependencyValidator`, proposal repository and controlled apply
  already exist; generated Article prose is not Evidence.
- Exact files: `src/Application/Knowledge/KnowledgeService.php`;
  `src/Application/Knowledge/CanonicalDependencyValidator.php`;
  `src/Application/Knowledge/CurrentTruthResolver.php`;
  `src/Infrastructure/Governance/WpdbProposalRepository.php`;
  `src/Application/Governance/AuthorityProposalExecutor.php`;
  `src/Application/Semantic/ArticleComposer.php`;
  `tests/Unit/GovernanceCoreTest.php`;
  `tests/Unit/GovernanceApplyContractTest.php`;
  `tests/Unit/ArticleSemanticDossierTest.php`;
  `tests/Unit/ArticleResearchPreflightTest.php`.
- Exact classes/methods: canonical Knowledge create/cite/reuse methods;
  `CanonicalDependencyValidator`; `WpdbProposalRepository::create()`;
  `save()`; `findByIdempotencyKey()`; `AuthorityProposalExecutor` apply
  entrypoint; `ArticleComposer::compose()` claim trace construction.
- Contract/doc changes: require Claim ID/revision, source/evidence IDs,
  subject/scope/provenance and composition revision in body-free trace;
  material human prose edits detach the trace.
- Schema/migration impact: reuse existing JSON trace and Governance binding;
  no Article body import or semantic backfill. New durable trace fields are
  `CODE_GAP` until a contract approves them.
- Failing tests first: conflicting claim omitted, stale claim trace detected,
  changed proposal dependency rejected, same intent idempotent, generated
  prose not converted into Evidence.
- Smallest implementation change: make composition dependency fingerprint
  explicit and route semantic writes through existing proposal lifecycle.
- Regression tests: Governance core/apply/queue, Knowledge dependency and
  Article semantic tests.
- Read-back evidence: proposal binding equals current revisions; controlled
  apply receipt, repository state and Article claim trace agree.
- Failure modes: stale proposal, conflict, ineligible Governance, unavailable
  Evidence, human edit; fail closed with retry/review state.
- Rollback implications: supersede/retire through owner lifecycle only; never
  ad-hoc delete claims, Evidence or Graph edges.
- Completion criteria: trace is explainable, stale on material prose change,
  and every semantic mutation has approval/eligibility/apply/read-back.
- Stop conditions: direct repository write from Capture, raw body copied into
  Knowledge, or claim conflict hidden from public copy.

### Slice 10 — Media semantic enrichment, representative and visual support

- Purpose: reconcile one Media to all justified direct consumers, promote the
  best scoped representative, and resolve exact visual requirements.
- Dependencies: Slice 3–5 and 7–9; Visual Support contract; Media model.
- Existing executable reality: `MediaService` manages canonical Media;
  `RepresentativeMediaReconciler::reconcile()` scores candidates; visual
  requirement storage has revision; reverse reconciliation matches exact
  subject/scope/facet/feature/intent; usage updater lacks full CAS.
- Exact files: `src/Application/Media/MediaService.php`;
  `src/Application/Media/RepresentativeMediaReconciler.php`;
  `src/Application/Media/VisualSupportRequirementService.php`;
  `src/Application/Media/VisualSupportReverseReconciliationService.php`;
  `src/Infrastructure/Media/WpdbVisualSupportRequirementRepository.php`;
  `src/Infrastructure/Media/WpdbMediaUsageRepository.php`;
  `src/Application/Media/ArticleMediaCoordinator.php`;
  `tests/Unit/RepresentativeMediaReconcilerTest.php`;
  `tests/Unit/VisualSupportRequirementTest.php`;
  `tests/Unit/VisualSupportReverseReconciliationTest.php`;
  `tests/Unit/WpdbVisualSupportRequirementRepositoryTest.php`;
  `tests/Unit/MediaUsageReconcilerTest.php`.
- Exact classes/methods: `MediaService::ingest()`; `addUsage()`;
  `RepresentativeMediaReconciler::reconcile()` and `score()`;
  `VisualSupportRequirementService::require()` and `get()`;
  `VisualSupportReverseReconciliationService::reconcile()`;
  `bindConsumers()`; `WpdbVisualSupportRequirementRepository::save()` and
  exact lookup methods.
- Contract/doc changes: preserve comparator order, exact reverse-match keys,
  rights/public eligibility behavior and representative versus evidence roles.
- Schema/migration impact: Visual Support migration 019 already exists;
  MediaUsage CAS/placement remains the Slice 5 extension gate.
- Failing tests first: better existing representative is kept; technical detail
  cannot represent broad subject; exact requirement resolves; stale requirement
  fails; ineligible Media is omitted while provenance remains.
- Smallest implementation change: reuse comparator and reverse reconciler;
  add usage/representative revision binding, without creating Graph edges from
  similarity or copying image URLs into Article truth.
- Regression tests: Media completion, representative, reverse reconciliation,
  migration and public projection suites.
- Read-back evidence: Media revision, Usage/requirement revision, selected
  MediaAsset visibility/readiness and representative decision are all recorded.
- Failure modes: wrong scope, stale revision, rights revocation, missing
  required visual, unavailable alternate; text-safe Article may proceed only
  when policy says visual is optional.
- Rollback implications: demote old suitable usage through governed update;
  preserve Media/Asset/provenance; no delete for replacement.
- Completion criteria: all direct justified nodes are reconciled, exact visual
  requirements resolve or retain an honest missing state.
- Stop conditions: variant image promoted to broad subject without support,
  mandatory visual silently ignored, or reverse match uses filename/keyword/
  checksum similarity.

### Slice 11 — Managed sections, human edits and reprojection

- Purpose: update generated Article sections idempotently while preserving
  human-authored content and detecting material edits.
- Dependencies: Slice 5–6 and 9; Article state-token contract; claim trace.
- Existing executable reality: `ArticleComposer::compose()` tracks owned
  section keys/fingerprints in packets, but there is no confirmed structural
  Gutenberg managed-section parser or persisted human-edit fingerprint.
- Exact files: `src/Application/Semantic/ArticleComposer.php`;
  `src/Application/WordPress/EditorialDraftGateway.php`;
  `src/Infrastructure/WordPress/WpEditorialPostStore.php`;
  `src/Application/Article/ArticleVerificationReader.php`;
  `tests/Unit/ArticleSemanticDossierTest.php`;
  `tests/Unit/ArticleOperationReceiptTest.php`;
  `tests/Unit/ArticleVerificationReaderTest.php`.
- Exact classes/methods: `ArticleComposer::compose()`;
  `removeOwnedSections()`; `EditorialDraftGateway::update()`;
  `WpEditorialPostStore` read/update; `ArticleVerificationReader::verify()`.
  A structural managed-section parser is `CODE_GAP`; it must be introduced
  only under the Article owner after contract approval.
- Contract/doc changes: define stable section ID, projected-content
  fingerprint, dependency fingerprint, expected state token, marker schema and
  detached claim trace behavior. Native order after manual reorder wins.
- Schema/migration impact: prefer block markers and Article metadata already
  owned by WordPress; a new persistent fingerprint field is `CODE_GAP` pending
  Article contract decision. No legacy body parsing.
- Failing tests first: second projection replaces rather than appends; human
  block remains verbatim; material generated edit returns `EDITORIAL_CONFLICT`;
  stale token/fingerprint blocks targeted update; manual reorder survives replay.
- Smallest implementation change: parse structured managed markers, compare
  stored fingerprint/token, and update only owned section through Article CAS.
- Regression tests: composer, Article update receipt, verification and Capture
  convergence.
- Read-back evidence: current Post body/markers, state token, section and
  dependency fingerprints, claim trace status and receipt are compared after
  update.
- Failure modes: human edit, stale state, malformed marker, duplicate section,
  unavailable read-back; stop targeted reprojection and request review.
- Rollback implications: never overwrite the human version; a failed update
  leaves current Post and prior receipt intact.
- Completion criteria: idempotent managed replacement, no duplicate append,
  human content preserved and stale trace visibly detached.
- Stop conditions: raw string replace is the only available path, or stable
  section ID is used without content/token verification.

### Slice 12 — Upload validation, privacy and trusted-file boundary

- Purpose: enforce server-side image security from bytes through private source
  storage and public WebP derivative.
- Dependencies: Media model; P6/Admin Media guidance; storage/runtime policy;
  Slice 3–5.
- Existing executable reality: `MediaBatchUploadService` validates batch size,
  checksum and allowlisted extensions; `TrustedProvidedFileMaterializer`
  validates HTTPS hosts, redirects, private IPs, bytes and MIME; full decoded
  pixel/resource and metadata stripping policy needs end-to-end proof.
- Exact files: `src/Application/Media/MediaBatchUploadService.php`;
  `src/Infrastructure/Mcp/TrustedProvidedFileMaterializer.php`;
  `src/Infrastructure/Media/WordPressMediaAttachmentIngestor.php`;
  `src/Application/Media/ImageIngestEntrypoint.php`;
  `src/Domain/Media/MediaAsset.php`;
  `src/Application/Media/PublicMediaAssetDelivery.php`;
  `tests/Unit/MediaBatchUploadServiceTest.php`;
  `tests/Unit/MediaAssetDeliveryTest.php`;
  `tests/Unit/MediaCanonicalDeliveryTest.php`;
  `tests/Unit/WpdbMediaAssetRepositoryTest.php`.
- Exact classes/methods: `MediaBatchUploadService::upload()`;
  `maxFileBytes()`; `fileFingerprint()`; `TrustedProvidedFileMaterializer` public
  materialization method; `WordPressMediaAttachmentIngestor::ingest()`;
  `ImageIngestEntrypoint::ingest()`; `PublicMediaAssetDelivery::resolve()`;
  `canonicalAsset()`.
- Contract/doc changes: exact format/byte/signature/decoder/pixel/memory/time
  limits, EXIF orientation, public metadata stripping, safe filenames,
  containment, cleanup and source-original `PRIVATE`.
- Schema/migration impact: no semantic schema change; asset metadata may need a
  contract-approved protected observation field, otherwise keep it transient.
- Failing tests first: corrupt MIME/decoder, compressed bomb, oversized decoded
  image, active/SVG rejection, GPS/private EXIF stripping, path containment,
  temp cleanup and 1–20/50 MB server enforcement.
- Smallest implementation change: enforce policy at server materializer and
  attachment boundary; UI hints remain non-authoritative; no remote import
  enablement.
- Regression tests: batch, trusted file, asset repository and canonical delivery
  tests.
- Read-back evidence: exact bytes/checksum, MIME, dimensions, private source
  visibility, safe storage key and derivative metadata are read back from owner.
- Failure modes: invalid signature, decoder failure, resource exhaustion,
  storage escape, cleanup uncertainty; fail closed with no public selection.
- Rollback implications: temporary file cleanup only under storage owner; valid
  source-original remains private and is not destroyed by derivative failure.
- Completion criteria: every accepted item passes server checks; every rejected
  item has a typed diagnostic; no secret/private content is logged.
- Stop conditions: client-only validation, SVG sanitizer assumed without a
  contract, or public source-original path becomes reachable.

### Slice 13 — Remote URL import decision

- Purpose: keep remote URL import explicitly excluded from this feature and
  prevent accidental fallback from trusted files to arbitrary network fetches.
- Dependencies: Slice 12; security review.
- Existing executable reality: no approved unified remote-import path; trusted
  provided-file materialization is a separate controlled boundary.
- Exact files: `src/Infrastructure/Mcp/TrustedProvidedFileMaterializer.php`;
  `src/Application/Media/ImageIngestEntrypoint.php`;
  `src/Application/Mcp/McpTransport.php`;
  `tests/Unit/McpWidgetUploadTest.php`;
  `tests/Unit/MediaBatchUploadServiceTest.php`.
- Exact classes/methods: `TrustedProvidedFileMaterializer::materialize()`;
  `validateRedirectTarget()`; `isExactAllowlistedHost()`;
  `ImageIngestEntrypoint::ingest()`; `McpTransport::mediaIngest()`;
  `fileAttachment()`; `normalizeUploadedFile()`.
- Contract/doc changes: mark remote URL import OUT OF SCOPE. A future separate
  contract must require HTTP/HTTPS only, private/loopback/link-local/metadata
  blocking, redirect revalidation, bounded redirects/bytes/time and no
  filesystem selection.
- Schema/migration impact: none.
- Failing tests first: URL-only input is rejected for this feature; trusted
  file path does not downgrade to URL fetch; future SSRF rules are documented
  but not activated.
- Smallest implementation change: preserve mutual exclusion and return a typed
  unsupported-boundary diagnostic.
- Regression tests: trusted file and transport contract tests.
- Read-back evidence: no network fetch receipt exists for a URL-only request;
  file materialization includes validated host/path policy when applicable.
- Failure modes: SSRF attempt, redirect ambiguity, unavailable URL; fail closed.
- Rollback implications: no persistent state is created by rejected URL input.
- Completion criteria: no code path silently activates URL import.
- Stop conditions: owner asks to activate remote import in this slice or
  security credentials/policy are missing.

### Slice 14 — SEO, public identity, rights and publication gate

- Purpose: require semantic, Media, compliance, route and rendered read-back
  before publication or any auto-public capability.
- Dependencies: Slice 6, 8–13; SEO contracts; claim compliance law; public
  identity implementation and route contracts.
- Existing executable reality: `ArticlePublicationGate::check()` and
  `OwnerPublicationApplicationService` govern publication; `EditorialDraftGateway`
  uses operation receipts/state token; `RenderedArticleVerifier::verify()` has
  stored/rendered checks; automated compliance across every output channel is
  not runtime-proven.
- Exact files: `src/Application/Article/ArticlePublicationGate.php`;
  `src/Application/Article/OwnerPublicationApplicationService.php`;
  `src/Application/WordPress/EditorialDraftGateway.php`;
  `src/Application/Article/RenderedArticleVerifier.php`;
  `src/Application/Compliance/PublicClaimCopyPolicy.php`;
  `src/Application/Compliance/PublicEditorialCopyGuard.php`;
  `src/Application/Media/ArticleMediaSeoProjection.php`;
  `src/Application/Media/PublicMediaAssetDelivery.php`;
  `tests/Unit/ArticlePublicationGateTest.php`;
  `tests/Unit/ArticleSeoGateTest.php`;
  `tests/Unit/RenderedArticleVerifierTest.php`;
  `tests/Unit/PublicMediaRouteGateTest.php`.
- Exact classes/methods: `ArticlePublicationGate::check()`;
  `requireTrue()`; `optionalTrue()`; `OwnerPublicationApplicationService::review()`;
  `request()`; `approveAndPublish()`; `EditorialDraftGateway::reviewPublication()`;
  `approvePublication()`; `RenderedArticleVerifier::verify()`;
  `PublicClaimCopyPolicy::containsUnsupportedSuperiority()`; `safe()`;
  `PublicEditorialCopyGuard::assertSafe()`; `assertEditorialPackage()`; and
  `PublicMediaAssetDelivery::resolveByPublicFilename()`.
- Contract/doc changes: publication sequence is stored state → semantic read-
  back → Media/rights/readiness → compliance → SEO/canonical → route →
  rendered read-back → stable dependency fingerprint → PASS → publish.
- Schema/migration impact: no new schema required; publication receipt must bind
  policy/compliance/dependency versions using existing receipt payload where
  contract permits.
- Failing tests first: compliance unavailable blocks auto-public; `OWNER_REVIEW_REQUIRED`
  never becomes PASS; revoked rights select alternate/honest missing; route
  collision and rendered failure block; optional visual does not block a
  text-safe Article.
- Smallest implementation change: expand publication evidence and gate checks;
  keep owner publication lifecycle and state-token CAS.
- Regression tests: publication gate, SEO gate, Article receipt, route gate and
  rendered verifier suites.
- Read-back evidence: stored Post, Media/Asset/Usage, canonical identity,
  compliance result, route HTTP probe and rendered HTML all bind one stable
  dependency fingerprint.
- Failure modes: `PUBLICATION_BLOCKED`, `OWNER_REVIEW_REQUIRED`, rights failure,
  route collision, compliance unavailable, rendered unavailable; no silent
  publish.
- Rollback implications: draft/review remains intact; an already published
  rights change follows explicit depublication policy and preserves source/
  provenance.
- Completion criteria: auto-public is a capability-gated final PASS only; all
  unavailable gates remain review/block states.
- Stop conditions: live compliance cannot be proven, public identity is
  ambiguous, or publication would require a generic native writer bypass.

### Slice 15 — Public WebP route, gallery, image sitemap and accessible lightbox

- Purpose: make canonical image delivery useful, indexable only when eligible,
  and accessible with a no-JavaScript fallback.
- Dependencies: Slice 6, 10 and 14; public URL, Media SEO, sitemap and frontend
  law.
- Existing executable reality: `PublicMediaAssetRoutes` serves `/anh/<filename>.webp`
  with WebP/length/immutable/nosniff headers; `PublicMediaGalleryQuery` uses
  Media canonical name; `PublicMediaArticleLinkResolver::firstPublished()` picks
  one sorted published Post; `single.php` and `album.js` have carousel behavior
  but no real modal focus trap or fallback anchor binding.
- Exact files: `src/Application/Media/PublicMediaGalleryQuery.php`;
  `src/Application/Media/PublicMediaArticleLinkResolver.php`;
  `src/Application/Media/PublicMediaAssetDelivery.php`;
  `src/Infrastructure/Http/PublicMediaAssetRoutes.php`;
  `src/Application/Media/PublicMediaAssetUrlResolver.php`;
  `public/wp-content/themes/nhk-v3/single.php`;
  `public/wp-content/themes/nhk-v3/album.js`;
  `public/wp-content/themes/nhk-v3/entity.css`;
  `public/wp-content/themes/nhk-v3/template-parts/presentation/media-grid.php`;
  `tests/Unit/PublicMediaAssetRoutesTest.php`;
  `tests/Unit/MediaCanonicalDeliveryTest.php`;
  `tests/Unit/MediaLibraryFrontendContractTest.php`;
  `tests/Unit/PublicMediaAssetProjectionTest.php`.
- Exact classes/methods: `PublicMediaGalleryQuery::archive()`; `forMedia()`;
  `card()`; `PublicMediaArticleLinkResolver::firstPublished()` and `resolve()`;
  `PublicMediaAssetDelivery::resolveByPublicFilename()`;
  `PublicMediaAssetRoutes::rewrite()`; `serve()`; `responseForFilename()`;
  theme gallery markup and `album.js` slide/keyboard handler.
- Contract/doc changes: canonical `/anh/{slug}.webp`; 1200px long-edge WebP
  projection; eligible-only sitemap; Article title link uses current Article
  anchor; ambiguous multi-Article gallery target is plain text; image click is
  direct WebP.
- Schema/migration impact: none; any image sitemap provider remains a
  projection read model.
- Failing tests first: HTTP 200/WebP/nosniff/dimensions/binding; source-original
  not exposed; direct click target; global ambiguous Article plain text; one
  Media in two Articles; no-JS anchor; modal dialog role/label/focus trap/
  Escape/return focus/keyboard caption behavior.
- Smallest implementation change: extend read models and template markup;
  implement progressive enhancement in existing `album.js`; do not add a
  standalone image HTML page or semantic relation for UI linking.
- Regression tests: public route, delivery, media library/frontend and Article
  media projection tests; browser/accessibility acceptance is required before
  live gate.
- Read-back evidence: HTTP headers/body dimensions, canonical filename/asset
  binding, rendered HTML, accessibility tree, focus sequence and no-JS anchor.
- Failure modes: ineligible asset, missing derivative, route collision,
  ambiguous target, unavailable caption; honest missing state and no indexable
  projection.
- Rollback implications: frontend can fall back to direct anchor/carousel;
  public bytes remain governed by existing slug/cache policy.
- Completion criteria: public asset and gallery are eligible-only, accessible,
  cache-consistent and Article-scoped.
- Stop conditions: standalone SEO image page, source leak, inaccessible modal,
  or arbitrary newest/oldest Article selection.

### Slice 16 — Observability, failure vocabulary and deployment wrapper

- Purpose: make failures actionable without leaking body/private data and bind
  release verification to the canonical deployment/preflight path.
- Dependencies: every preceding slice; MCP Control Plane; deployment/preflight
  contracts; documentation runtime manifest.
- Existing executable reality: Capture stores body-free diagnostics and phase
  receipts; `McpTransport::error()` maps transport errors; `scripts/nhk-deploy-verify`
  delegates to `tools/nhk-deploy-verify.php`; `scripts/nhk-demo-cutover` is the
  canonical wrapper; `NHK_DEMO_DEPLOY_CONFIG` is required for deployment.
- Exact files: `src/Application/Capture/EditorialCaptureCoordinator.php`;
  `src/Application/Mcp/McpTransport.php`;
  `src/Domain/Article/PublicationDiagnosticRegistry.php`;
  `src/Domain/Media/MediaDiagnosticCodeRegistry.php`;
  `src/Application/Completion/CompletionCoordinator.php`;
  `scripts/nhk-deploy-verify`;
  `tools/nhk-deploy-verify.php`;
  `scripts/nhk-demo-cutover`;
  `tools/nhk-demo-cutover.php`;
  `tests/Unit/EditorialCaptureConvergenceE2ETest.php`;
  `tests/Unit/McpWidgetUploadTest.php`;
  `tests/Unit/ArticleDiagnosticReaderTest.php`.
- Exact classes/methods: coordinator `withoutBody()`; `failureCode()`;
  `failureStatus()`; `completionChildren()`; `McpTransport::error()`;
  `CompletionCoordinator::finalize()`; `aggregateCapture()`;
  `tools/nhk-deploy-verify.php::runProcess()`; `validBaseUrl()`;
  `safeIdentity()`; `finish()`; and
  `DemoCutoverRunner::prepare()` from `tools/nhk-demo-cutover.php` wiring.
- Contract/doc changes: distinguish `EMPTY_RESULT`,
  `DEPENDENCY_UNAVAILABLE`, `OWNER_REVIEW_REQUIRED`, `CLIENT_EXPOSURE_GAP`,
  `EDITORIAL_CONFLICT`, `IDEMPOTENCY_CONFLICT`, `PARTIAL`, `SYSTEM_BLOCKED`,
  `SERVER_TOOL_EMPTY_RESULT`, `CAPTURE_NOT_COMPLETE` and infrastructure
  failure. Define stage, receipt, owner IDs, dependency fingerprint, retry
  boundary, read-back and rendered probe fields.
- Schema/migration impact: prefer existing diagnostics/receipt JSON; any new
  indexed observability field is `CODE_GAP` and requires separate contract and
  migration review.
- Failing tests first: empty transport result is not semantic success; body,
  secret, credential, trusted URL and temp path are absent; deployment verifier
  rejects wrong build/docs manifest/config and does not write remote state.
- Smallest implementation change: normalize diagnostics at existing boundaries
  and make verifier evidence explicit; do not add a logging store or bypass
  deployment wrapper.
- Regression tests: Capture convergence, widget, diagnostics, deployment
  preflight and MCP contract suites.
- Read-back evidence: body-free local receipt, build identity, docs manifest,
  release config and preflight result; no remote write in this plan.
- Failure modes: transport symptom, stale docs, build mismatch, missing config,
  unavailable runtime; fail closed and report the exact boundary.
- Rollback implications: diagnostic schema is additive; deployment verifier
  remains read-only until a separately authorized deployment operation.
- Completion criteria: every phase has typed observability and the canonical
  wrapper is the only future deployment path.
- Stop conditions: remote credentials missing, server worktree dirty, verifier
  requires remote mutation, or diagnostics expose private payload.

### Slice 17 — One-JPEG and multi-image live acceptance, future gate only

- Purpose: define the eventual bounded acceptance sequence without authorizing
  it in this plan.
- Dependencies: all slices, owner approval, fresh documentation bootstrap,
  deployed build identity, exact approved existing IDs and acceptance scope.
- Existing executable reality: local implementation has not been created by
  this documentation task; server is intentionally not inspected or changed.
- Exact files: `scripts/nhk-deploy-verify`; `scripts/nhk-demo-cutover`;
  MCP Content Operations contract; Control Plane contract; future acceptance
  test files from the approved implementation slices. No server file is an
  implementation target.
- Exact classes/methods: future calls must use `nhk.capture.ingest` and the
  governed Article/Media/Governance boundaries; no remote Git method is allowed.
- Contract/doc changes: document two bounded test vectors: one real JPEG
  `IMAGE_ARTICLE` and N-image album, each with idempotency, read-back, rights,
  compliance, SEO, route and rendered verification.
- Schema/migration impact: none authorized by this plan; only previously
  approved UP migrations may exist at the later gate.
- Failing tests first: local one-JPEG and multi-image acceptance tests must
  pass before any live request is considered.
- Smallest implementation change: none in this task; later acceptance invokes
  only the canonical Capture route and governed lifecycle.
- Regression tests: exact one-JPEG, album, replay, changed-payload conflict,
  partial batch, rights and public-route cases in the mapping below.
- Read-back evidence: fresh bootstrap/build identity, exact Capture/Article/
  Media IDs, canonical owner revisions, publication receipt, HTTP WebP probe,
  rendered route and final dependency fingerprint.
- Failure modes: any scope mismatch, duplicate/read-only audit failure, stale
  build/docs, client gap, dirty server, missing credentials or failed gate
  stops before mutation.
- Rollback implications: no live acceptance is run here; future governed
  lifecycle must preserve successful children and use explicit depublication
  policy for rights changes.
- Completion criteria: both vectors are local-green and a separate Cutover
  Readiness Report authorizes any later live gate.
- Stop conditions: all remote/server activity, production cutover, unapproved
  staging object, identity ambiguity or owner decision on a constitutional
  invariant.

## 3. Requirement → RED test → owner → PASS evidence mapping

The mapping uses existing test files where they already own the boundary. A
future test is an additional method in the named file, not a new unregistered
test subsystem. Every row is RED before implementation and PASS only after
the stated read-back evidence exists.

| # | Requirement | RED test file / case | Implementation owner | PASS evidence |
|---:|---|---|---|---|
| 1 | Media-only single image | `ContentIntentRouterTest` media-only | `ContentIntentRouter::route` | `MEDIA_ENRICHMENT`, no Post, Media read-back |
| 2 | Media-only multi-image | `EditorialCaptureConvergenceE2ETest` batch case | Coordinator + batch service | one Capture, ordered Media IDs |
| 3 | One-image Article | `ArticleMediaPolicyTest` single real Media | ArticleMediaCoordinator + gate | one Post, no duplicate Media |
| 4 | Album Article | `ArticleMediaPolicyTest` N images | ArticleMediaCoordinator + Composer | one Post, ordered placements |
| 5 | Image title anchor | `ArticleSemanticDossierTest` placement metadata | Composer + native Post | Article URL plus stable anchor |
| 6 | Direct WebP click | `MediaLibraryFrontendContractTest` canonical href | `single.php` + URL resolver | `/anh/{slug}.webp` |
| 7 | Single lightbox | `MediaLibraryFrontendContractTest` dialog | `album.js` | role/dialog/focus behavior |
| 8 | Album lightbox | same frontend contract album case | `album.js` + template | labelled navigation/caption |
| 9 | Idempotent replay | `EditorialCaptureContinuationTest` same key | Capture repositories/coordinator | same Capture/owners |
| 10 | Changed-payload conflict | `ContentIntentRouterTest`/Capture convergence | `EditorialCaptureCoordinator::conflict` | `IDEMPOTENCY_CONFLICT` |
| 11 | Checksum candidate only | `MediaServiceCompletionTest` reuse case | `MediaService::ingest` | no semantic auto-merge |
| 12 | Technical detail not broad representative | `RepresentativeMediaReconcilerTest` comparator | Reconciler | old broad representative `KEEP` |
| 13 | Visual support reverse reconcile | `VisualSupportReverseReconciliationTest` exact key | Reverse service | exact requirement state/revision |
| 14 | Conflicting claim omitted | `ArticleResearchPreflightTest` conflict | Research/preflight + compliance | claim excluded from copy |
| 15 | Internet unavailable, internal evidence sufficient | `ArticleResearchPreflightTest` provider absent | Article preflight | draft/review proceeds with internal claims |
| 16 | Ambiguity block | `ContentIntentRouterTest` ambiguous signals | Router + subject resolver | review-required, no owner write |
| 17 | Rights block | `ArticlePublicationGateTest` revoked Media | Publication gate | blocked, source retained |
| 18 | Corrupt image | `MediaBatchUploadServiceTest` decoder/type | Batch/materializer | per-item diagnostic |
| 19 | Route collision | `PublicMediaRouteGateTest` collision | Public identity/route gate | fail closed |
| 20 | Runtime unavailable | `McpTransportIntegrationTest` capability gap | Transport/control plane | typed unavailable |
| 21 | Managed section no duplicate | `ArticleSemanticDossierTest` replay | Composer | one owned section |
| 22 | User text preserved | `ArticleSemanticDossierTest` human block | Composer + Article CAS | verbatim human block |
| 23 | Knowledge invalidation | `GovernanceApplyContractTest` stale dependency | Knowledge/Governance | proposal rejected/review |
| 24 | One Post for N images | `EditorialCaptureConvergenceE2ETest` album | Coordinator + draft gateway | one native Post ID |
| 25 | No fake second Media | `ArticleMediaPolicyTest` single Media | ArticleMediaCoordinator | distinct identity count one |
| 26 | Rendered read-back before publication | `RenderedArticleVerifierTest` required route | Publication service | rendered verification PASS |
| 27 | No direct owner writer | `McpArticleContractTest` Capture-only path | MCP catalog/transport | guarded direct boundary |
| 28 | Widget upload reuses Media IDs | `CaptureMediaIdsReuseTest` follow-up | Widget + Capture | no second upload |
| 29 | Physical success then Capture failure/retry | `EditorialCaptureConvergenceE2ETest` partial | Coordinator receipts | reuse physical receipt |
| 30 | Partial N-image batch | `MediaBatchUploadServiceTest` 4/5 | Batch + Capture | four retained, Capture partial |
| 31 | Concurrent identical Capture | `ContentIntentRouterTest`/convergence race | Capture repository | one reservation |
| 32 | Human managed-section edit | `ArticleOperationReceiptTest` token race | Draft gateway + Composer | `EDITORIAL_CONFLICT` |
| 33 | Manual album reorder | `ArticleSemanticDossierTest` reorder replay | Native Post owner | current native order wins |
| 34 | Same Media in two Articles | `ArticleSemanticDossierTest` two endpoints | Usage/anchor projection | two anchors |
| 35 | Global ambiguous Article target | `MediaLibraryFrontendContractTest` multi-usage | Gallery/link resolver | plain text title |
| 36 | Private EXIF/GPS stripped | `MediaCanonicalDeliveryTest` metadata | Asset derivative boundary | no private metadata public |
| 37 | Pixel/decompression bomb | `MediaBatchUploadServiceTest` resource budget | Materializer | rejected before durable public state |
| 38 | Active image rejected | `MediaBatchUploadServiceTest` SVG/active | Format policy | fail closed |
| 39 | MIME + nosniff | `PublicMediaAssetRoutesTest` headers | Public route | WebP + nosniff |
| 40 | External snippet not Evidence | `ArticleResearchPreflightTest` snippet-only | Research adapter | no Evidence ID |
| 41 | Provider unavailable, sufficient evidence | `ArticleResearchPreflightTest` fallback | Preflight | internal-evidence PASS path |
| 42 | Provider unavailable, insufficient evidence | `ArticleResearchPreflightTest` mandatory claim | Preflight + gate | publication blocked |
| 43 | Automated compliance unavailable | `ArticlePublicationGateTest` compliance gap | Compliance gate | review/block, no auto-public |
| 44 | Stale claim trace | `ArticleSemanticDossierTest` material edit | Composer verifier | trace detached |
| 45 | Contextual metadata isolation | `MediaUsageReconcilerTest` two Articles | Usage/Article metadata | titles do not collide |
| 46 | Article featured != Entity representative | `ArticleMediaPolicyTest` representative separation | ArticleMedia + Reconciler | independent decisions |
| 47 | Rights revocation | `MediaServiceCompletionTest` inactive/public-ineligible | Media projection | alternate or honest missing |
| 48 | Public source-original leak prevention | `MediaCanonicalDeliveryTest` private asset | Delivery | no source response |
| 49 | No-JS image fallback | `MediaLibraryFrontendContractTest` anchor | Theme template | `<a>` canonical WebP |
| 50 | Focus return after modal close | `MediaLibraryFrontendContractTest` keyboard | `album.js` | invoker regains focus |
| 51 | Replay no duplicate partial children | `EditorialCaptureContinuationTest` manifest replay | Continuation + batch | completed children unchanged |
| 52 | Capture idempotency reservation | `EditorialCaptureConvergenceE2ETest` concurrent key | `WpdbCaptureRepository` | atomic unique/read-back |
| 53 | WordPress state-token CAS | `ArticleOperationReceiptTest` update race | `EditorialDraftGateway` | stale token rejected |
| 54 | MediaUsage revision/CAS | `MediaUsageReconcilerTest` concurrent update | Usage repository | stale update rejected |
| 55 | Representative revision race | `RepresentativeMediaReconcilerTest` concurrent | Reconciler/usage updater | no lost update |
| 56 | Visual requirement revision | `WpdbVisualSupportRequirementRepositoryTest` race | Requirement repository | CAS conflict |
| 57 | Governance proposal binding | `GovernanceApplyContractTest` fingerprint | Proposal repository/apply | eligibility rejects drift |
| 58 | Public identity collision | `PublicUrlArchitectureRegressionTest` collision | Public identity gate | no suffix invention |
| 59 | Managed dependency fingerprint | `ArticleOperationReceiptTest` stale dependency | Composer + gateway | targeted apply blocked |
| 60 | Server result empty is transport symptom | `McpWidgetUploadTest` empty result | `McpTransport::error`/adapter | not semantic success |
| 61 | HTTP 200 WebP | `PublicMediaAssetRoutesTest` response | `PublicMediaAssetRoutes::responseForFilename` | status/type/length |
| 62 | Canonical asset binding | `MediaCanonicalDeliveryTest` filename | Delivery selector | MediaAsset checksum binding |
| 63 | No standalone image HTML page | `MediaLibraryFrontendContractTest` route | Theme/routes | direct binary only |
| 64 | Lightbox focus trap/Escape | `MediaLibraryFrontendContractTest` modal contract | `album.js` | dialog keyboard semantics |
| 65 | Caption/navigation labels | `MediaLibraryFrontendContractTest` album metadata | Template + JS | current item caption/total |
| 66 | Remote URL excluded | `McpWidgetUploadTest` URL-only | Transport/materializer | typed unsupported boundary |
| 67 | Auto-public final sequence | `ArticlePublicationGateTest` all gates | Owner publication service | final PASS + receipt binding |

## 4. TDD release order and evidence gates

### Gate A — documentation and amendment readiness

Read Constitution, ACTIVE contract manifest and current Execution State. Approve
Amendments A/B. Update subordinate contracts and registry descriptors. Run
documentation bootstrap/manifest generation locally if the repository contract
requires it. Evidence is a clean documentation diff and exact registry/schema
parity; no database or runtime mutation.

### Gate B — Capture and physical vertical

Run RED/GREEN for Slices 1–4: intent, one Capture, widget handoff, per-item
batch and continuation. Verify repository state, receipts, idempotency,
attachment read-back and Media reuse. Do not compose or publish an Article from
transport success.

### Gate C — Media identity and Article vertical

Run RED/GREEN for Slices 5–6: usage/title/anchor contract, one-image exception,
album order and Article CAS. Verify one native Post for N images and no duplicate
Media. Keep Article featured state independent from entity representative.

### Gate D — semantic and visual vertical

Run RED/GREEN for Slices 7–10: shared read-only semantic packet, bounded
research, governed Source/Evidence, representative and visual requirement
reconciliation. Verify exact scope/revision/provenance and no inferred Graph
edge from reachability alone.

### Gate E — composition, public projection and publication

Run RED/GREEN for Slices 11–16: managed-section fingerprints, security/privacy,
rights, SEO, compliance, `/anh/` delivery, gallery/lightbox and observability.
Verify stored/read-back/rendered/public route evidence before any publication
decision. Compliance capability unavailable remains review/block.

### Gate F — bounded acceptance readiness

Run local one-JPEG and multi-image acceptance with exact fixtures and no live
mutation. Produce a Cutover Readiness Report. A later staging acceptance would
require fresh documentation bootstrap, deployed build identity, exact approved
IDs, duplicate/read-only audit, canonical Capture/Governance lifecycle,
idempotency and final read-back. The dirty server remains preserved and out of
scope.

## 5. Schema and migration ledger

No migration is executed or authorized by this plan. Potential future schema
work is deliberately limited to:

1. `MediaUsage` revision and repeated-placement identity, if the contract proves
   existing UUID/uniqueness insufficient.
2. Article-managed placement/fingerprint metadata, only if native block metadata
   cannot carry the contract without losing CAS/read-back.
3. Provider receipt metadata, only if preview evidence cannot remain transient
   and the owning contract approves persistence.

For each candidate, the future coding session must first add a RED contract
test, select the next sequential UP migration after inspecting the current
ledger (currently up through `GovernanceSubjectBindingMigration020`), run the
guarded migration checks, and prove read-back. No DOWN, DROP, TRUNCATE, reset,
legacy body import or semantic backfill is part of the release order.

## 6. Explicit gaps and blockers

### Executable gaps

- `MEDIA_ENRICHMENT` is absent from `ContentIntent` and MCP catalog.
- `MediaUsage` has no contextual title or revision/CAS; current unique key also
  does not represent repeated same-role placement.
- Structural Gutenberg managed-section parsing and persisted human-edit
  fingerprint binding are not present.
- External Internet research provider abstraction is absent; Source/Evidence
  repositories are present but provider handoff is not.
- Full decoded-pixel/resource enforcement and public metadata stripping require
  end-to-end implementation proof.
- Current public gallery chooses the first published Article and cannot safely
  represent equal multi-Article targets.
- Current theme carousel has no real modal focus trap/Escape/focus-return
  implementation and gallery markup needs canonical direct-image anchors.
- The current Article media invariant needs the approved single-real-image
  exception before its implementation path can be legal.

### Runtime and infrastructure gaps

- Fresh deployed documentation/build/client parity must be verified at any later
  acceptance; local code existence is not live capability proof.
- Automated claim-compliance validation across every output channel is not yet
  runtime-proven, so auto-public remains unavailable.
- Deployment requires the canonical wrapper and `NHK_DEMO_DEPLOY_CONFIG` plus
  target credentials; this task does not inspect or modify the server.
- The server has reported local modifications/untracked content and is recorded
  as `SERVER_WORKTREE_DIRTY — OUT_OF_SCOPE — PRESERVED`.

### Deployment blockers

- No deployment, SSH, rsync, remote Git operation or staging mutation is
  authorized in this task.
- Final production cutover requires a Cutover Readiness Report and human gate.
- Any mismatch in documentation manifest, build identity, runtime registry,
  client exposure, exact IDs, rights, compliance, route or read-back is a
  fail-closed blocker.

## 7. Plan completion criteria

The implementation plan is complete when:

- Amendments A/B are listed before all coding slices;
- every slice names purpose, dependencies, current reality, exact existing
  files/classes/methods, contract and schema impact, RED test, expected failure,
  smallest change, regressions, read-back, failures, rollback, completion and
  stop conditions;
- all 67 canonical design/review cases map to a RED test, owner and evidence;
- absent provider/parser/schema capability is marked as a gap rather than
  invented;
- the future TDD order is explicit and release/cutover gates remain closed;
- no source code, tests, Constitution, schema, server or runtime data is changed
  by this planning checkpoint.

The next authorized gate is `OWNER_REVIEW_IMPLEMENTATION_PLAN`. Until that gate
passes, do not begin TDD or implementation.
