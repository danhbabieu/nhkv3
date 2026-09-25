# Image Capture → Media → Knowledge/Entity → Article → Public Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Make one Capture containing one or more images a coherent NHK V3 submission unit whose canonical MediaUsage, semantic scope, Article composition and public projections converge without introducing an Album entity.

**Architecture:** Capture remains the submission/orchestration owner. Each uploaded file remains an independent Media identity. One Article is created only for an Article intent; an ordered Capture asset manifest produces a governed MediaUsage plan for Article and exact semantic owners such as Model, Variant or Specimen. WordPress is updated only after canonical MediaUsage planning/read-back and remains the editorial presentation owner.

**Tech Stack:** PHP 8.x, WordPress plugin runtime, PHPUnit, existing NHK V3 domain/application services, runtime registries, Governance/Authority repositories, MediaUsage repositories, WordPress read-back adapters and public projection queries.

**Spec:** `docs/superpowers/specs/2026-09-25-image-capture-media-knowledge-article-public-design.md`

## Global Constraints

- `nhk.capture.ingest` remains the only normal new-submission boundary.
- Do not create an Album entity, Album table, Album route or parallel image pipeline.
- Capture remains orchestration; it must not become an owner of Media, Article, Knowledge, Authority or Evidence.
- WordPress `wp_posts` remains the source of truth for Article title, body, excerpt, editorial ordering and permalink.
- Canonical MediaUsage must be planned and read back before WordPress featured/inline/gallery projection is treated as complete.
- One submission creates one Capture and at most one Article; N images create N Media identities, not N Articles or N Specimens.
- Visual inference, OCR and Media observations remain scoped observations/candidates and never become canonical facts without the existing evidence and Governance lifecycle.
- Model, Variant and Specimen resolution must use the registered Authority vocabulary and existing Governance path; no Junghans-specific branch is allowed.
- Preserve UUID/stable-key identity, optimistic revisions, idempotency, provenance, readiness, public identity and fail-closed behavior.
- Do not mutate Article 711, Capture `01a0d5c4-35b1-7ac6-b060-971bdf6dad85`, or any staging/production semantic record in these implementation slices.
- Preserve unrelated pre-existing worktree changes.

## Review Focus

- Raw instruction input must never become Article title/body: test `Tạo bài viết về Junghans W64` as workflow control.
- Three assets must remain one Capture/Article while retaining three Media identities and ordered dispositions.
- WordPress featured/Gutenberg attachment presence must not satisfy canonical MediaUsage without scope, role, placement and read-back verification.
- An explicit real-object statement may plan one Specimen but must not create one Specimen per image or bypass Governance.
- A specimen-scoped observation or visual inference must not widen into a Variant/Model/Brand Claim.
- Supporting/detail assets must not disappear merely because they are not featured or inline-primary.
- Public Media reverse context must remain bounded and direct-subject scoped when one Media is reused by multiple owners.

## File map

Files below are the expected implementation surface. Existing services should be extended through their current contracts; a new file is permitted only when a responsibility cannot be expressed without making an existing class cross-boundary.

- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/TextInputInterpreter.php` to expose typed non-semantic instruction/editorial separation without promoting candidates.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/ArticleComposer.php` to consume editorial copy and typed semantic context rather than raw instructions.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`, `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php` and the canonical multipart adapter only as needed to preserve shared Capture description and per-image names as typed Capture input instead of Media-only metadata.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php` and `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` to preserve the locked subject packet, asset manifest and composition ordering.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php` to preserve ordered assets and child dispositions across retry/follow-up.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Media/MediaUsageReconciler.php`, `ArticleMediaCoordinator.php` and the existing Media binding service only where required to reconcile all current-Capture assets.
- Modify `public/wp-content/plugins/nhk-core/src/Domain/Media/MediaUsageRoleRegistry.php` only if tests prove a registered projection role is missing; do not add speculative roles.
- Modify the existing Capture/Media/Article repositories or migrations only if the current persisted manifest cannot preserve the required data without a bounded compatible addition; no Album schema is allowed.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Media/PublicMediaGalleryQuery.php`, `PublicMediaArticleLinkResolver.php` or existing Entity dossier/public projection consumers for bounded reverse context.
- Add focused tests under `public/wp-content/plugins/nhk-core/tests/Unit/` and contract tests under `tests/Contract/` only when the repository’s existing layout requires it.
- Update `docs/architecture/V3_EXECUTION_STATE.md` only at implementation checkpoints with evidence.

## Task 1: Lock typed Capture interpretation and Article input ownership

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/TextInputInterpreter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ArticleComposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php` only if the connector projection drops the shared description or asset input packet
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SharedEditorialComposerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`

**Interfaces:**
- Consumes the existing interpreter result fields and Capture context.
- Produces explicit `editorial_copy`, `instructions`, `compliance_notes`, `instruction_classes`, user claim candidates and Media observations for downstream composition.

- [ ] Write failing tests proving an instruction-only request produces no instruction paragraph and does not use the instruction as the title.
- [ ] Write a failing multipart/adapter regression proving a shared description with three `asset_inputs` resolves to `IMAGE_ARTICLE` even when every `feature_requests` list is empty; assert names remain ordered scoped asset context.
- [ ] Write the paired Feature-present regression: one explicit feature request may create only the representative branch, while the same three assets still enter the single Article plan.
- [ ] Write failing tests proving user-authored editorial copy remains available to Article composition.
- [ ] Write failing tests proving compliance/workflow instructions remain non-semantic context.
- [ ] Run the focused tests and confirm failure at the current raw-input boundary.
- [ ] Implement the smallest typed-input handoff while preserving existing claim candidate and provenance behavior.
- [ ] Normalize the accepted shared-description aliases at the Capture boundary into the existing `text`/editorial-copy path; do not add a second description owner or let per-image metadata become Article body.
- [ ] Change Article composition to select title from resolved subject/editorial context when no explicit editorial title exists.
- [ ] Run the focused tests and then the existing Article semantic suite.
- [ ] Commit only this slice with a message such as `fix: separate capture instructions from article prose`.

## Task 2: Normalize the ordered Capture asset manifest

**Files:**
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Domain/Capture/CaptureRecord.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Capture/WpdbCaptureRepository.php` only if compatible persistence is required
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureMediaIdsContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureMediaIdsReuseTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureAssetFollowUpTest.php`

**Interfaces:**
- Consumes physical Media ingest manifests and existing `media_ids`/`items` continuation inputs.
- Produces one ordered Capture asset manifest with Media identity, attachment mapping, order, contextual hints and terminal child disposition.

- [ ] Add failing tests for one Capture with three ordered assets and no duplicate Article owner.
- [ ] Add failing tests for idempotent replay preserving the same Capture and asset order.
- [ ] Add failing tests for partial retry resubmitting only failed children.
- [ ] Add failing tests for an asset that is intentionally unbound or review-required remaining visible in the manifest.
- [ ] Run focused Capture continuation tests and record the current failure.
- [ ] Normalize the existing manifest/continuation fields; do not introduce an Album or a second asset store.
- [ ] Preserve existing physical-ingest success and failure semantics.
- [ ] Run focused tests, PHP lint and `git diff --check`.
- [ ] Commit this slice with a message such as `fix: preserve capture image manifest across retries`.

## Task 3: Build a complete Capture-owned MediaUsage plan

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaUsageReconciler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php` only where its existing governed binding contract is insufficient
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Domain/Media/MediaUsageRoleRegistry.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaUsageReconcilerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaUsagePlacementTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureMediaEnrichmentMatrixTest.php`

**Interfaces:**
- Consumes the locked Capture subject packet, ordered asset manifest and registered owner capabilities.
- Produces governed MediaUsage binding requests and per-asset dispositions for featured, inline, supporting/gallery, technical detail, representative or evidence contexts.

- [ ] Write failing tests for a three-image Article where one asset is featured and two are supporting/detail placements.
- [ ] Include the required Feature-empty Hermle-shaped fixture without hard-coding the name in production code, plus a generic Feature-present variant using the same input contract.
- [ ] Write failing tests proving all three assets have active canonical usage or an explicit non-success disposition.
- [ ] Write failing tests rejecting an explicit Media whose subject scope is incompatible.
- [ ] Write failing tests ensuring representative and Article featured usages remain distinct.
- [ ] Run focused MediaUsage tests and confirm current omission behavior.
- [ ] Generate the plan from the Capture manifest, not from WordPress featured state alone.
- [ ] Reuse the existing MediaBindingService/Governance boundary for durable bindings.
- [ ] Keep unknown roles and unsupported endpoints fail-closed through runtime registries.
- [ ] Run focused media suites and verify no direct repository writes were added.
- [ ] Commit this slice with a message such as `fix: reconcile all capture media usages`.

## Task 4: Compose Article only after semantic and Media preparation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ArticleComposer.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestCoordinator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestCoordinatorTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ImageArticleProductionFlowTest.php`

**Interfaces:**
- Consumes the final `SubjectResolutionPacket`, selected Claims with scope/provenance/evidence status, typed editorial context, Media observations and the current MediaUsage plan.
- Produces one Article composition with title, excerpt, body, managed sections, claim trace, research snapshot and dependency fingerprint.

- [ ] Add a failing convergence test for “Tạo bài viết về Junghans W64” with canonical claims available; expect generated title/body and no raw instruction.
- [ ] Add a failing sparse-knowledge test; expect short honest output without filler claims.
- [ ] Add a failing test proving specimen-scoped observations do not appear as Model facts.
- [ ] Add a failing test proving Article composition does not run before the subject packet and claim selection are locked.
- [ ] Run the existing convergence suite and capture the pre-change order.
- [ ] Move composition to the existing preparation/reconciliation handoff without creating a second Article pipeline.
- [ ] Preserve managed Article sections, user-authored paragraphs and body-free claim trace.
- [ ] Run focused Article/Capture suites and verify idempotent continuation.
- [ ] Commit this slice with a message such as `fix: compose image articles from canonical context`.

## Task 5: Add governed real-object/Specimen intent without a new entity

**Files:**
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/AuthorityCaptureService.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Authority/ProductSpecimenAssessment.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Domain/Authority/CanonicalEntityTypeCatalog.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ProductSpecimenBoundaryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ConversationalAuthorityCaptureTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureClassifiedAsRuntimeIntegrationTest.php`

**Interfaces:**
- Consumes an explicit real-object assertion and resolved Model/Variant candidate.
- Produces a Capture-bound, registry-valid Specimen plan requiring the existing Proposal → Approval → Eligibility → Controlled Apply → read-back lifecycle.

- [ ] Add failing tests proving multiple images of one object produce one Specimen plan.
- [ ] Add failing tests proving Model-only input does not create a Specimen.
- [ ] Add failing tests proving ambiguous serial/physical provenance remains review-required.
- [ ] Add failing tests proving image recognition alone cannot create a Specimen.
- [ ] Run the focused Authority/Governance tests before implementation.
- [ ] Add only typed planning context; do not add an entity, table, Graph predicate or direct writer.
- [ ] Reuse existing Authority staging admission and exact Capture-bound approval/fingerprint checks.
- [ ] Verify canonical Authority read-back and bind the Capture asset manifest only after successful apply.
- [ ] Commit this slice with a message such as `feat: plan governed specimen capture context`.

## Task 6: Reconcile WordPress presentation after canonical MediaUsage

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Contracts/Media/WordPressArticleMediaAdapter.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleVerificationReaderTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/ArticleIngestPost55ReconciliationIntegrationTest.php` when guarded integration is available

**Interfaces:**
- Consumes a verified canonical MediaUsage plan and WordPress editorial state.
- Produces native featured/inline/gallery projection plus canonical/native/public read-back diagnostics.

- [ ] Add failing tests proving an existing WP featured attachment does not satisfy `featured_primary` when canonical scope/usage is missing.
- [ ] Add failing tests proving a Gutenberg inline attachment without MediaUsage remains incomplete.
- [ ] Add failing tests proving a valid canonical plan writes/read-backs featured and supporting placements in order.
- [ ] Add the legacy single-image compatibility assertion: one asset still receives the existing featured/inline behavior and does not require a supporting disposition.
- [ ] Add failing tests proving stale historical usage cannot override the current Capture manifest.
- [ ] Implement canonical-first ordering without adding WordPress dual-write as a semantic owner.
- [ ] Keep attachment mappings read-compatible and idempotent.
- [ ] Run focused Article media tests, PHP lint and `git diff --check`.
- [ ] Run guarded integration only against exact `nhk_v3_test`; do not use `nhk_v3` for destructive operations.
- [ ] Commit this slice with a message such as `fix: project wordpress media from canonical usage`.

## Task 7: Complete public Media and Entity reverse context

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/PublicMediaGalleryQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/PublicMediaArticleLinkResolver.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/RelatedContentQuery.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Presentation/EntityPresentationViewModel.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleSemanticDossierTest.php`
- Test: add or extend the existing public Media projection test file

**Interfaces:**
- Consumes active public-safe MediaUsage and existing WordPress/Public Identity resolvers.
- Produces bounded Media → Article/Entity context and exact direct-subject Entity galleries without inventing a Media semantic route.

- [ ] Add failing tests for one Media linked to one published Article and one exact Entity context.
- [ ] Add failing tests for one Media reused by multiple valid Articles without arbitrary context loss.
- [ ] Add failing tests proving a Model dossier does not absorb Specimen Media through reachability alone.
- [ ] Add failing tests excluding inactive/private/retired/placeholder MediaUsage.
- [ ] Implement bounded reverse context using existing query/projection boundaries.
- [ ] Preserve `/anh/<slug>.webp` public asset routing and public identity rules.
- [ ] Run focused public projection tests and verify no public UUID/stable-key leakage.
- [ ] Commit this slice with a message such as `feat: expose bounded media reverse context`.

## Task 8: Converge completion and add the 711 read-only diagnostic

**Files:**
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CapturePhaseReceiptReducer.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleDiagnosticReader.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php`
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php` and the existing Capture result serializer for the human-readable result packet
- Inspect/modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaRoutes.php` or the existing `/thu-vien/` query/template boundary only where the public state derives from the wrong source
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CapturePhaseReceiptReducerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleDiagnosticReaderTest.php`
- Update: `docs/architecture/V3_EXECUTION_STATE.md` with verified local evidence only

**Interfaces:**
- Consumes canonical owner read-backs, MediaUsage plan/read-back, WordPress state and public projection evidence.
- Produces independent canonical/enrichment/publication/frontend states, continuation hints and a read-only diagnostic packet for the supplied Capture/Article case.

- [ ] Add failing tests proving ingest success alone cannot be `COMPLETE`.
- [ ] Add failing tests proving `PARTIAL` retains child continuation state.
- [ ] Add failing tests separating `PUBLICATION_READY` from `ENRICHMENT_COMPLETE`.
- [ ] Add failing tests proving an asset without disposition yields an explicit blocker/partial state.
- [ ] Add failing tests proving the result packet reports Article/Media/Knowledge states in visitor language, hides verbose phase receipts by default, and distinguishes canonical Article usage from “chưa gắn bài viết”.
- [ ] Add a failing public gallery regression proving a published Article-backed Media card derives its Article context from active canonical MediaUsage, while a draft Article reports its persisted non-public state accurately.
- [ ] Implement reducer changes through existing completion policy boundaries, not a domain-specific 711 fix.
- [ ] Add a read-only diagnostic command/test fixture path that accepts exact supplied IDs and reports mismatches without mutating them.
- [ ] Implement the bounded result packet and public read model changes through existing serializers/query services; do not expose internal UUIDs, raw phase receipts or debug vocabulary in the default visitor surface.
- [ ] Run full Unit, Contract, PHP lint, `git diff --check` and secret review.
- [ ] Run guarded Integration only when exact environment prerequisites are present; report unavailable infrastructure honestly.
- [ ] Update execution state with commands, results and environment gates.
- [ ] Commit this slice with a message such as `fix: converge capture media completion states`.

## Final verification and handoff

- [ ] Read the final diff and confirm no Album/entity/schema or Junghans/711-specific logic was introduced.
- [ ] Confirm the plan's WordPress projection tests cover canonical-first ordering and the public gallery derives its Article label from active canonical MediaUsage, not attachment-only state.
- [ ] Confirm all changed files remain within the approved Capture/Media/Knowledge/Article/Public boundaries.
- [ ] Run the focused Capture, MediaUsage, Article and public projection suites.
- [ ] Run the full Unit and Contract suites with the repository-approved memory setting.
- [ ] Run PHP lint over changed PHP files.
- [ ] Run `git diff --check`.
- [ ] Perform a secret review; do not commit credentials, dumps, private keys or environment files.
- [ ] Read `docs/architecture/V2_V3_PARITY_MATRIX.md` before making any parity claim.
- [ ] Do not mutate Capture `01a0d5c4-35b1-7ac6-b060-971bdf6dad85` or Article 711 unless a separate exact governed acceptance is approved.
