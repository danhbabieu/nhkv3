# NHK V3 Capture Pipeline Convergence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Do not start implementation until this plan has been reviewed and approved.

**Goal:** Make the shared NHK V3 Capture lifecycle deterministic, intent-isolated, idempotent and canonically read-back verifiable from Capture through public frontend output.

**Architecture:** Preserve the existing owner boundaries and repair the orchestration seams between them. Capture owns lifecycle receipts and intent routing; WordPress owns editorial fields and URLs; MediaUsage owns placement; Governance owns semantic mutation; publication and completion consume current canonical read-backs. No new semantic owner, direct writer or data-specific branch is introduced.

**Tech Stack:** PHP 8.x, WordPress plugin runtime, PHPUnit 11, WPDB repositories, existing MCP/Ability contracts, existing guarded integration database `nhk_v3_test`.

**Spec:** `docs/superpowers/specs/2026-09-23-capture-pipeline-convergence-design.md`

## Global Constraints

- Use the Constitution and current approved contracts as authority; do not weaken them to legalize an implementation.
- Keep the normal new-content entrypoint as `nhk.capture.ingest` and preserve `Proposal → Submit → Approve → Eligibility → Controlled Apply` for governed semantic mutations.
- Do not hard-code Post ID, Capture ID, Media ID, subject name, Odo 30 or any reproduction-specific value.
- WordPress `wp_posts` remains the sole owner of editorial title, body, excerpt, dates, slug and public editorial URL.
- Media, MediaAsset, MediaUsage and Video remain distinct boundaries; a WordPress attachment or `_thumbnail_id` is not by itself canonical MediaUsage.
- Retry must rehydrate current canonical owner state and must not replay completed physical or governed phases.
- Development database `nhk_v3` permits only health, inspection, non-destructive additions and UP migrations; destructive integration work is restricted to exact `nhk_v3_test` with its guard.
- No V2/production data mutation, legacy article-body migration, direct SQL semantic write, generic WordPress writer fallback or final production cutover.
- Every mutation must have idempotency, optimistic revision/state-token protection and canonical read-back.
- Update `docs/architecture/V3_EXECUTION_STATE.md` after each implementation checkpoint with evidence and explicit `UNVERIFIED` external results.

## Review Focus

- Non-Video intents must never invoke Video enrichment/readiness or receive a Video blocker; pinned by Task 1 tests.
- An exact subject UUID must remain the same subject after retry, continuation and downstream Video/Media preparation; pinned by Task 2 tests.
- A server-issued staging packet must remain bound to the exact Capture, target, operation and revision through MediaUsage apply; pinned by Task 3 tests.
- Native featured/inline attachment state and canonical MediaUsage must converge to the same Media and placement identity without duplicate rows; pinned by Task 4 tests.
- A successful writer response with a stale or mismatched WordPress field must fail with an explicit read-back diagnostic; pinned by Task 5 tests.

## File and module map

### Capture and intent boundary

- `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` — intent-owned dispatch, phase receipts, owner propagation and canonical retry path.
- `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php` — retry/addendum admission and subject reconciliation.
- `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentIntentRouter.php` — registered intent validation and persisted intent reuse.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SubjectResolutionService.php` and `Domain/Capture/SubjectResolutionPacket.php` — identity precedence and immutable handoff.

### Staging, media and editorial owners

- `src/Application/Governance/StagingAcceptanceScopeVerifier.php` — server-issued exact scope/context packet.
- `src/Application/Media/MediaBindingService.php`, `src/Application/Media/ArticleMediaCoordinator.php`, `src/Application/Media/MediaUsageReconciler.php` — canonical usage operations and Article placement reconciliation.
- `src/Application/Article/ArticleIngestCoordinator.php`, `src/Application/Article/ArticleEditorialAdapter.php`, `src/Application/Article/ArticlePublicationGate.php` — field mapping, publication evidence and blocker semantics.
- `src/Infrastructure/WordPress/WpEditorialPostStore.php`, `src/Infrastructure/Article/WpEditorialStateReader.php` — native WordPress mutation/read-back and state token.
- `src/Application/Completion/CompletionCoordinator.php` — required-owner aggregation and current outcome selection.
- `src/Application/PublicIdentity/*`, `src/Infrastructure/Http/PublicEditorialRoutes.php` and existing public-route services — reservation/activation/read-back boundaries only where tests show a route lifecycle gap.

### Existing test homes

Use the nearest existing test file rather than inventing a parallel harness:
`ContentIntentRouterTest`, `EditorialCaptureConvergenceE2ETest`,
`EditorialCaptureContinuationTest`, `CaptureCurrentOutcomeReducerTest`,
`StagingAcceptanceScopeVerifierTest`, `MediaBindingServiceTest`,
`MediaBindingReadbackContractTest`, `MediaUsageReconcilerTest`,
`ImageArticleProductionFlowTest`, `ArticleIngestCoordinatorTest`,
`ArticlePublicationGateTest`, `CompletionConvergenceTest`,
`RenderedArticleVerifierTest`, and the existing Contract/Integration suites.

## Dependency order

The tasks below are sequential. A task may be started only after the preceding
task's pass conditions are satisfied and its checkpoint evidence is recorded.

### Task 1: Intent isolation at the Capture coordinator

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` around Video enrichment, Video publication verification, thumbnail fallback and non-Article completion dispatch.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentIntentRouter.php` only if the tests expose an explicit-intent validation gap.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php`.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php`.

**Interfaces:**

- Consumes: resolved persisted intent from `ContentIntentRouter`, current `CaptureRecord`, existing Video verifier callback.
- Produces: a coordinator execution where Video callbacks are invoked only for `VIDEO` with an actual Video input/owner; all other intents receive a neutral not-requested Video packet.

- [ ] **Step 1: Write failing isolation tests.** Add callback counters to assert that `TEXT_ARTICLE`, `IMAGE_ARTICLE`, `MEDIA_ENRICHMENT`, `KNOWLEDGE_DELTA` and `KNOWLEDGE_REPAIR` do not invoke Video publication verification or Video-only reconciliation. Add a `VIDEO` case proving the callback still runs.
- [ ] **Step 2: Run the focused tests and confirm the failure.** Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'ContentIntentRouterTest|EditorialCaptureConvergenceE2ETest|EditorialCaptureContinuationTest' --no-progress`. Expected: non-Video callback-count assertions fail before implementation.
- [ ] **Step 3: Implement the smallest boundary fix.** Gate Video enrichment, verifier, thumbnail fallback and Video-specific blockers on the resolved intent value `VIDEO` and on an actual Video branch. Keep `MEDIA_ENRICHMENT`'s existing early path and do not alter registered intent vocabulary.
- [ ] **Step 4: Verify all intent regressions.** Re-run the focused command. Expected: all intent-isolation tests pass and no unrelated `CAPTURE_SUBJECT_RECONCILIATION_VIDEO_REQUIRED` or Video quality blocker appears in non-Video diagnostics.
- [ ] **Step 5: Checkpoint.** Run PHP lint on changed files and `git diff --check`; update execution state with the exact test count. Do not proceed if any intent changes Article creation or existing Video continuation semantics.

**Invariant:** A resolved intent owns its dependency graph; non-Video Capture cannot become blocked by a Video owner that was not requested.

**Pass condition:** Focused tests pass; the coordinator has no unconditional Video verifier call in the non-Video path; no hard-coded identity values are introduced.

**Regression risk:** `IMAGE_ARTICLE` may lose required image publication checks if the guard is too broad; `TEXT_ARTICLE` may accidentally skip semantic read-back; `VIDEO` may lose its verifier; `MEDIA_ENRICHMENT` may re-enter Article creation; `KNOWLEDGE_DELTA`/`KNOWLEDGE_REPAIR` may gain or lose governed branches. Test each explicitly before continuing.

### Task 2: Subject packet authority and continuation rehydration

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SubjectResolutionService.php` only where exact UUID/stable-key behavior is not already contract-complete.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` subject packet hydration and owner revision checks.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php` reconciliation admission and retry input reconstruction.
- Inspect/modify only if required: `public/wp-content/plugins/nhk-core/src/Domain/Capture/SubjectResolutionPacket.php`.
- Test: `SubjectResolutionService` coverage in the existing Capture/semantic test files; `EditorialCaptureContinuationTest`; `CaptureArticlePreflightHandoffTest`; `EditorialCaptureConvergenceE2ETest`.

**Interfaces:**

- Consumes: canonical UUID, stable key, explicit hints, persisted `SubjectResolutionPacket`, current Authority read-back revision.
- Produces: one immutable subject packet passed to preparation, semantic, Video and Media consumers; retry either reuses the packet or fails closed on revision/identity drift.

- [ ] **Step 1: Add failing precedence/retry tests.** Cover exact UUID over contradictory hint, stable key over title/body inference, persisted packet reuse after retry, retired/missing owner rejection, and `CAPTURE_SUBJECT_RECONCILIATION_VIDEO_REQUIRED` only for persisted `VIDEO`.
- [ ] **Step 2: Run the focused tests and record the first failure.** Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'Subject|CaptureArticlePreflightHandoffTest|EditorialCaptureContinuationTest|EditorialCaptureConvergenceE2ETest' --no-progress`.
- [ ] **Step 3: Implement packet-bound continuation.** Ensure the coordinator hydrates the persisted packet before calling weaker resolution and passes the same packet to every child. Ensure continuation input cannot replace the packet unless the registered bounded reconciliation path has confirmed an allowed candidate.
- [ ] **Step 4: Verify data-flow invariants.** Assert downstream callbacks receive the same canonical subject UUID/type/revision and that a retry does not invoke a different subject resolver branch or create a second semantic owner.
- [ ] **Step 5: Checkpoint.** Lint, diff check, focused tests and execution-state update.

**Invariant:** `canonical UUID > stable_key > explicit subject hint > title/body inference`; canonical owner wins over cached Capture snapshot.

**Pass condition:** All exact-subject and continuation tests pass, and no Video-specific reconciliation code is reached for another intent.

**Regression risk:** A stricter packet may reject legitimate user-confirmed Video reconciliation; a stale revision may be accepted as current; Article/Knowledge flows may accidentally require packet resolution when their contract permits unresolved review. Keep review-required states explicit.

### Task 3: Shared staging scope propagation

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScopeVerifier.php` only to expose the existing server-issued packet fields required by the downstream operation.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` to persist and propagate the packet from Capture through Article creation/read-back.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php` and its governed adapter to consume the exact packet without caller-invented scope.
- Modify if required: `src/Application/Governance/ControlledApplyService.php` or proposal binding readers, preserving Governance ownership.
- Test: `StagingAcceptanceScopeVerifierTest.php`, `MediaBindingStagingAdmissionTest.php`, `CaptureDependencyStagingAdmissionTest.php`, `AuthorityStagingAdmissionTest.php`.

**Interfaces:**

- Consumes: Capture ID/fingerprint, exact target/revision, registered operation family, dependency closure, capability, expiry and signature.
- Produces: `staging_acceptance`/scope context carried unchanged through proposal, approval, Controlled Apply and canonical read-back; invalid, stale or missing packets fail closed.

- [ ] **Step 1: Add failing propagation tests.** Use generated UUIDs and synthetic Capture records to assert packet fingerprint/target/revision equality at MediaBindingService and Controlled Apply boundaries; assert caller-supplied guessed scope is rejected.
- [ ] **Step 2: Run staging-focused tests and confirm failures.** Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'StagingAcceptanceScopeVerifierTest|MediaBindingStagingAdmissionTest|CaptureDependencyStagingAdmissionTest|AuthorityStagingAdmissionTest' --no-progress`.
- [ ] **Step 3: Implement propagation without widening authority.** Store the packet in Capture context, include it in MediaUsage proposal metadata/context, and require the same signed packet at apply/read-back. Do not add an object-specific allowlist or direct writer.
- [ ] **Step 4: Verify stale/replay behavior.** Test expiry, changed Capture fingerprint, changed target/revision, wrong operation family and same-key idempotent retry.
- [ ] **Step 5: Checkpoint.** Lint, diff check, focused tests and execution-state update.

**Invariant:** A canonical Capture entrypoint has a valid server-issued path to complete its contract-permitted MediaUsage mutation, while internal/admin writers remain restricted.

**Pass condition:** Exact packet propagation tests pass and no mutation succeeds without the packet or with a stale/tampered packet.

**Regression risk:** `IMAGE_ARTICLE` can deadlock at MediaUsage if the packet is not available after Article creation; `MEDIA_ENRICHMENT` can lose its exact binding path; Authority/Video staging may accidentally accept an Article packet; production/read-only policy could be widened. Preserve operation-family checks.

### Task 4: MediaUsage convergence and placement identity

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaUsageReconciler.php`.
- Modify only when required for canonical projection: `public/wp-content/plugins/nhk-core/src/Infrastructure/WordPress/WpEditorialPostStore.php` and the attachment bridge used by Article media.
- Test: `MediaUsageReconcilerTest.php`, `MediaBindingServiceTest.php`, `MediaBindingReadbackContractTest.php`, `ArticleMediaPolicyTest.php`, `ImageArticleProductionFlowTest.php`.

**Interfaces:**

- Consumes: canonical Media IDs, existing usage rows, Article endpoint key, role, placement key, optimistic usage revision and exact staging packet.
- Produces: one idempotent desired-state reconciliation with canonical usage read-back and matching native featured/inline projection.

- [ ] **Step 1: Add failing convergence tests.** Cover featured reuse, inline primary/supporting reuse, replace/remove, multi-image stable placement keys, duplicate retry, stale WordPress `_thumbnail_id`, and attachment A/body placement versus MediaUsage B mismatch.
- [ ] **Step 2: Run focused media tests and confirm failures.** Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'MediaUsageReconcilerTest|MediaBindingServiceTest|MediaBindingReadbackContractTest|ArticleMediaPolicyTest|ImageArticleProductionFlowTest' --no-progress`.
- [ ] **Step 3: Implement canonical desired-state reconciliation.** Normalize placements before planning; reuse a valid existing Media identity; apply add/replace/remove through the existing governed service; preserve placement keys and contextual alt/caption/title on MediaUsage, not canonical Media.
- [ ] **Step 4: Implement projection/read-back comparison.** After native WordPress synchronization, read back featured and inline attachment IDs and canonical usage rows; return explicit mismatch diagnostics and replan only from current canonical state.
- [ ] **Step 5: Verify idempotency.** Run each binding twice with the same key and assert exactly one active usage per target/role/placement, stable Media ID and no duplicate physical adoption.
- [ ] **Step 6: Checkpoint.** Lint, diff check, focused tests and execution-state update.

**Invariant:** `Media` is physical identity; `MediaUsage` is target/role/placement relationship; native attachment state is projection, not semantic ownership.

**Pass condition:** Featured and inline usage/read-back agree for new and reused assets, including multi-image packets and retries.

**Regression risk:** `TEXT_ARTICLE` may become incorrectly media-required; `IMAGE_ARTICLE` may still lack inline enforcement when content contains images; representative entity bindings may be altered; `VIDEO` thumbnail fallback may accidentally become Article MediaUsage. Keep intent and consumer role explicit.

### Task 5: Article field normalization and canonical read-back

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestCoordinator.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php` and the typed draft/update boundary if alias normalization is split there.
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/WordPress/WpEditorialPostStore.php`.
- Test: `ArticleIngestCoordinatorTest.php`, `ArticleEditorialAdapterTest.php`, `EditorialDraftGatewayTest.php`, `EditorialPublicationWriterTest.php`.

**Interfaces:**

- Consumes: documented public field packet (`fields` object or typed fields), expected state token, idempotency key.
- Produces: normalized native fields (`post_title`, `post_content`, `post_excerpt`, `post_name`, category IDs, featured attachment) and a read-back result that explicitly reports field mismatch.

- [ ] **Step 1: Add failing field tests.** Cover documented aliases, explicit title over body heading, explicit excerpt/slug/metadata, unknown-field rejection, successful native read-back and writer-response/read-back mismatch.
- [ ] **Step 2: Run the focused Article tests and confirm failures.** Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'ArticleIngestCoordinatorTest|ArticleEditorialAdapterTest|EditorialDraftGatewayTest|EditorialPublicationWriterTest' --no-progress`.
- [ ] **Step 3: Implement one normalization boundary.** Normalize aliases before the native store, reject unknown fields with a machine-readable error, and keep `featured_media_id`/category mapping explicit.
- [ ] **Step 4: Implement exact read-back comparison.** Compare every requested persisted field against `EditorialPostState`; return failure with field-level causes rather than `ok:true` when the persisted value differs.
- [ ] **Step 5: Verify Composer precedence through the real adapter.** Add an end-to-end unit fixture where body starts with a Markdown heading but explicit title wins through draft create/update/retry.
- [ ] **Step 6: Checkpoint.** Lint, diff check, focused tests and execution-state update.

**Invariant:** explicit request > approved/generated value > body inference; WordPress remains editorial source of truth.

**Pass condition:** Every accepted Article mutation returns matching canonical fields or a specific failure; no silent success remains.

**Regression risk:** Existing direct/internal Article lifecycle consumers may rely on legacy aliases; `IMAGE_ARTICLE` composition may lose managed sections; `KNOWLEDGE_DELTA`/`KNOWLEDGE_REPAIR` must not create or update Article fields.

### Task 6: State-token rehydration and retry convergence

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` save/rehydration paths.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestCoordinator.php` receipt continuation binding.
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Article/WpEditorialStateReader.php` only if the token does not reflect the canonical native modification state required by the contract.
- Test: `EditorialStateTokenTest.php`, `EditorialCaptureContinuationTest.php`, `CaptureCurrentOutcomeReducerTest.php`, `ArticleOperationReceiptTest.php`.

**Interfaces:**

- Consumes: current WordPress `EditorialPostState`, Capture revision, phase receipts and expected token.
- Produces: a retry that refreshes Article state after composition/media/projection changes and never overwrites a newer owner revision with an old Capture snapshot.

- [ ] **Step 1: Add failing race/retry tests.** Simulate Article edit after draft creation, MediaUsage token rotation, retry after successful apply before receipt persistence, repeated publish retry and network interruption before final read-back.
- [ ] **Step 2: Run focused state tests and confirm failures.** Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'EditorialStateTokenTest|EditorialCaptureContinuationTest|CaptureCurrentOutcomeReducerTest|ArticleOperationReceiptTest' --no-progress`.
- [ ] **Step 3: Implement canonical rehydration.** Before compose/update/publication on retry, read current Article state; update Capture token/revision from the read-back; reject stale CAS and resume from the current canonical phase.
- [ ] **Step 4: Preserve receipt history while selecting current outcome.** Ensure a successful current owner outcome supersedes only the effective stale projection, leaving append-only historical receipts intact.
- [ ] **Step 5: Verify no duplicate work.** Assert physical ingest, draft creation, governed apply and MediaUsage creation counts remain one across retries where their receipts are already complete.
- [ ] **Step 6: Checkpoint.** Lint, diff check, focused tests and execution-state update.

**Invariant:** canonical mutable owner state beats cached Capture state; state token changes after each update and is persisted before the next dependent phase.

**Pass condition:** Retry tests converge without stale overwrite, duplicate owner creation or loss of phase receipts.

**Regression risk:** legitimate addendum/replacement flows may be treated as stale retries; Video completion-only retry could accidentally re-enter semantic work; Knowledge repair could be made Article-dependent. Preserve explicit continuation modes.

### Task 7: Publication gate, public URL lifecycle and completion

**Files:**

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php`.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleRemediationPlanner.php` and `OwnerPublicationApplicationService.php` only where blocker ownership/read-back evidence is incomplete.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php` and coordinator `requiredOwners()/completionChildren()`.
- Modify only when tests prove a route lifecycle gap: existing Public Identity/public editorial route services; do not invent a new route namespace.
- Test: `ArticlePublicationGateTest.php`, `PublicationDiagnosticRegistryTest.php`, `OwnerPublicationApplicationServiceTest.php`, `CompletionConvergenceTest.php`, `RenderedArticleVerifierTest.php`, `PublicRouteResolverTest.php`, `PublicUrlArchitectureRegressionTest.php`.

**Interfaces:**

- Consumes: current Article state, current MediaUsage snapshot, semantic/governance read-back, native route reservation, publication evidence and rendered public read-back.
- Produces: blocker packets with owner/cause/remediation, concrete required-owner IDs, route reservation before publication and route activation/read-back after publish.

- [ ] **Step 1: Add failing blocker/completion tests.** Cover optional Media for `TEXT_ARTICLE`, required submitted Media for `IMAGE_ARTICLE`, stale blocker removal after dependency repair, route reservation without publish deadlock, post-publish route activation, and empty-owner rejection.
- [ ] **Step 2: Run gate/completion/route tests and confirm failures.** Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'ArticlePublicationGateTest|PublicationDiagnosticRegistryTest|OwnerPublicationApplicationServiceTest|CompletionConvergenceTest|RenderedArticleVerifierTest|PublicRouteResolverTest|PublicUrlArchitectureRegressionTest' --no-progress`.
- [ ] **Step 3: Implement current-evidence blocker ownership.** Make each required blocker derive from current canonical evidence, retain optional gaps as warnings/deferred repairs, and attach a remediation code understood by the existing planner.
- [ ] **Step 4: Fix required-owner propagation.** Build required owners only after Article/Media/Video/Knowledge read-back IDs exist; ensure `wp_post` and Media owners cannot be represented by empty IDs.
- [ ] **Step 5: Verify route lifecycle.** Reserve/check native draft route before gate; activate/check public route only after publish; verify rendered URL and final native status via existing services.
- [ ] **Step 6: Checkpoint.** Lint, diff check, focused tests and execution-state update.

**Invariant:** publication and completion are derived from current canonical owner state, never a stale snapshot or optimistic writer response.

**Pass condition:** All required blockers self-resolve after canonical dependency repair; completion is true only with concrete required owners and verified final read-back.

**Regression risk:** `TEXT_ARTICLE` could become blocked by optional Media; `IMAGE_ARTICLE` could publish without required MediaUsage; Video public readiness could be weakened; Knowledge intents could gain Article/public-route requirements; public entity routes could be confused with native editorial URLs.

### Task 8: Full regression matrix and contract coverage

**Files:**

- Modify: existing Unit tests from Tasks 1–7.
- Add only if no existing home expresses the whole flow: `public/wp-content/plugins/nhk-core/tests/Unit/CapturePipelineConvergenceTest.php`.
- Modify: relevant Contract tests for MCP schema/intent/read-back parity.
- Modify: guarded Integration tests such as `CaptureVideoRecoveryIntegrationTest.php`, `McpTransportIntegrationTest.php` and Article/Media integration tests only when the exact test runtime is configured.

**Interfaces:**

- Consumes: all repaired boundaries and registered MCP public contract.
- Produces: a generalized data-independent matrix proving all six intents, retries, duplicate prevention, field/read-back consistency and public read-back.

- [ ] **Step 1: Add the generalized IMAGE_ARTICLE flow.** Use generated canonical UUIDs and generated attachment/media fixtures; cover existing Media reuse, exact subject, Article draft, semantic relation, featured/inline usage, title edit, retry, review, publish, public URL and rendered read-back.
- [ ] **Step 2: Add the other intent cases.** Assert TEXT-only without Media, IMAGE with new Media, IMAGE multi-image, MEDIA_ENRICHMENT without Article, VIDEO with Video-only gates, KNOWLEDGE_DELTA without Article/image and KNOWLEDGE_REPAIR without inference.
- [ ] **Step 3: Add idempotency interruption cases.** Repeat Capture, Media bind and publish; simulate interruption after Proposal Apply and before receipt persistence; assert one canonical operation and current receipt convergence.
- [ ] **Step 4: Run Unit and Contract suites.** Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --no-progress` and `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Contract' --no-progress`. Expected: pass with only already-documented warnings/deprecations.
- [ ] **Step 5: Run guarded Integration when available.** Require exact `NHK_WP_TEST_PATH=public` and `NHK_WP_TEST_DB=nhk_v3_test` plus the repository TestDatabaseGuard; never reset or destructively operate on `nhk_v3`.
- [ ] **Step 6: Checkpoint.** Record test totals, warnings, skips and any environment blocker in execution state.

**Invariant:** A fix for one intent cannot silently change the dependency graph or completion semantics of another.

**Pass condition:** Matrix passes with generated data only; no production/V2/staging semantic mutation occurs.

**Regression risk:** The broad flow may expose hidden constructor wiring, MCP schema mismatch or integration-only state-token behavior. Fix at the shared boundary and add the smallest contract test that proves it.

### Task 9: Documentation and execution evidence

**Files:**

- Modify: `docs/architecture/V3_EXECUTION_STATE.md` after each checkpoint and at final local verification.
- Modify only for proven behavior changes: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`, `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, `docs/architecture/04_MEDIA_MODEL.md`, `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`, `docs/seo/PUBLIC_URL_SLUG_CONTRACT.md`.
- Modify: relevant MCP/runtime schema snapshots only if executable schema actually changes and the owning contract allows it.

**Interfaces:**

- Consumes: implementation and test evidence from Tasks 1–8.
- Produces: documentation that states the current behavior without creating new vocabulary or contradicting the Constitution.

- [ ] **Step 1: Compare implementation with current contracts.** Mark any conflict as `CONSTITUTION_CONFLICT`; do not change the Constitution or silently reinterpret a normative contract.
- [ ] **Step 2: Update only behavior-backed documentation.** Document intent-owned dependencies, subject packet retry, scope propagation, MediaUsage projection/read-back, field aliases, state-token rehydration and blocker remediation with exact existing names.
- [ ] **Step 3: Add evidence references.** Record test commands/results, changed files, local runtime identity if available and all external/staging limitations as `UNVERIFIED`.
- [ ] **Step 4: Run documentation/path checks.** Run the repository's documentation/runtime registry tests and `git diff --check`; verify no secrets, credentials or local env files are included.
- [ ] **Step 5: Checkpoint.** Save the final local execution-state entry before any staging verification.

**Invariant:** Documentation is subordinate evidence/contract guidance and cannot invent entities, fields, operations, routes or authorization.

**Pass condition:** Docs accurately describe tested runtime behavior and preserve the current Constitution/contract vocabulary.

**Regression risk:** Over-documenting optional enrichment as required could reintroduce intent cross-contamination; changing contract text without executable support could create a runtime/schema mismatch.

### Task 10: Staging/runtime verification and bounded handoff

**Files:**

- No product-code files are changed by this task unless a verification failure identifies a previously untested shared-boundary defect; any such change returns to the relevant earlier task.
- Evidence: `docs/architecture/V3_EXECUTION_STATE.md` and a dated verification report under `docs/architecture/` if the repository convention requires one.

**Interfaces:**

- Consumes: completed local Unit/Contract/guarded Integration evidence, fresh documentation bootstrap, deployed build identity, exact signed acceptance packet and configured MCP/runtime connector.
- Produces: a fail-closed staging verification result or an explicit `UNVERIFIED`/blocked report; never an autonomous production cutover.

- [ ] **Step 1: Run fresh documentation bootstrap and runtime discovery.** Verify documentation manifest/version, executable registry, MCP tool schema and build identity match the local expected checkpoint.
- [ ] **Step 2: Verify staging authorization.** Require explicit bounded user-approved acceptance scope, exact existing IDs supplied by the user/runtime, duplicate/read-only audit, signed packet bound to current Capture/request/revisions and operation family, expiry and idempotency.
- [ ] **Step 3: Execute only through public canonical MCP flow.** Use `nhk.capture.ingest` and registered Governance/Article continuation operations; do not use direct DB, generic writer or compatibility bypass.
- [ ] **Step 4: Read back every owner.** Verify Capture phase receipts, Article fields/token, Media/MediaAsset/MediaUsage, semantic relation/claims, publication status, public URL, projection and rendered frontend page.
- [ ] **Step 5: Retry the exact Capture.** Confirm no duplicate Media, usage, Article, proposal or publication operation and that the final current state remains unchanged.
- [ ] **Step 6: Stop fail-closed on mismatch.** If runtime/build/docs/packet differs, record the exact blocker and do not mutate or claim staging success.
- [ ] **Step 7: Final report.** Include root cause, files, invariants, tests, commit SHA, deployment/runtime identity, staging E2E result and every remaining `UNVERIFIED` item.

**Invariant:** External mutation is bounded, canonical, idempotent and fully read-back verified; production cutover is never autonomous.

**Pass condition:** Fresh runtime discovery, bounded acceptance, public MCP E2E and final retry all pass, or the report clearly states why staging remains `UNVERIFIED` without weakening local correctness.

**Regression risk:** Deployed runtime may differ from local code; staging scope may be stale; connector exposure may be incomplete; public rendering may be unavailable. Treat each as an external verification blocker, not a reason to bypass a boundary.

## Final plan self-review

- **Spec coverage:** Sections 3.1–3.6 map to Tasks 1–7; generalized regression and safety gates map to Tasks 8–10.
- **Intent coverage:** All six registered intents are explicitly tested in Tasks 1 and 8.
- **Boundary coverage:** Capture, Subject, staging scope, MediaUsage, Article fields, state token, publication/URL, completion, documentation and staging verification all have an owner task.
- **Placeholder scan:** No `TODO`, `TBD`, “implement later” or unspecified test step is present.
- **Type/interface consistency:** Later tasks consume only persisted intent/packet/token/read-back structures produced by earlier tasks; no new semantic type or operation is introduced.
- **Safety review:** No task authorizes V2/production mutation, direct DB writes, hard-coded fixture logic or final cutover.

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-09-23-capture-pipeline-convergence.md`. Review the plan and confirm it captures the intended work. Before implementation begins, choose the execution method:

- **Native:** I implement every task in this session using `superpowers:executing-plans`, with one final independent review.
- **Subagent-driven:** each task is implemented and reviewed independently, followed by a whole-branch review; this is more thorough but slower and uses more context.
