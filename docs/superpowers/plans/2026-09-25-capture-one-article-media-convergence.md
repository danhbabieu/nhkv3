# Capture → One Article → Ordered Media Convergence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make one Capture produce at most one composed Article and reconcile the complete ordered Media manifest through canonical MediaUsage read-back before completion.

**Architecture:** Keep `EditorialCaptureCoordinator` as orchestration over existing owners. Move Article creation behind semantic composition, feed the native Article media coordinator the full Capture manifest, and make completion derive truth from Article/Media canonical read-backs rather than optimistic phase status.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress plugin runtime, existing Capture/Article/MediaUsage contracts and repositories.

**Spec:** `docs/superpowers/specs/2026-09-25-capture-one-article-media-convergence-design.md`

## Global Constraints

- Work directly on `main`; do not create a worktree or modify server/staging/production data.
- `nhk.capture.ingest` remains the only normal new-content entry point.
- WordPress native `wp_posts` remains the sole Article editorial truth; MediaUsage remains canonical media relationship truth.
- Do not invent entity types, predicates, relation types, or generic writers.
- Preserve UUID/stable-key, optimistic revision, idempotency, provenance, readiness, and fail-closed behavior.
- Do not report `COMPLETE` when canonical read-back is absent or `usages=[]`.

## Review Focus

- Image manifest cardinality 1/3/10 must produce one Capture and at most one Article: Task 1.
- Empty Feature requests must not route to Media-only or block Article creation: Task 1.
- Knowledge `NEEDS_REVIEW` must not erase an otherwise valid Article MediaUsage branch: Task 2.
- Every manifest Media must receive a terminal disposition and canonical usage read-back: Task 3.
- Retry must reuse the same Article and must not create duplicate usages: Task 4.

### Task 1: Pin routing, admission, composition-order, and cardinality invariants

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentIntentRouter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/PreparationPhaseAdmissionPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`

**Interfaces:**
- `ContentIntentRouter::route(array $input, array $interpretation, array $assets): array` continues returning the registered `ContentIntent` packet.
- `EditorialCaptureCoordinator` continues accepting injected owner callables; no new owner boundary is introduced.

- [ ] **Step 1: Write failing tests** for shared description + multiple assets + empty features, preparation review/optional findings still admitting `IMAGE_ARTICLE`, composed content being the only Article draft body, and one draft on replay.
- [ ] **Step 2: Run focused tests** with `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php` and confirm the new assertions fail for the current ordering/admission behavior.
- [ ] **Step 3: Implement the minimum routing/admission/order change**: preserve explicit intent precedence, classify usable images plus shared description as `IMAGE_ARTICLE`, treat only mandatory preparation blockers as admission blockers, compose before native draft creation, and pass the composed result into the single draft creator with `capture_id . ':article'`.
- [ ] **Step 4: Re-run focused tests** and confirm routing, composition ordering, cardinality, and idempotent replay pass.
- [ ] **Step 5: Commit** with `git add` and `git commit -m "fix: compose one article per capture"`.

### Task 2: Make Article media planning whole-manifest and canonical-first

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaResult.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WpdbMediaUsageRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaUsageReconcilerTest.php`

**Interfaces:**
- `ArticleMediaCoordinator::ensureForPost(int $postId, array $context, array $selectedMediaBySlot, array $supportingMediaIds): ArticleMediaResult` remains the canonical Article Media boundary.
- `ArticleMediaResult::toArray()` exposes dispositions and canonical usage read-back without changing public reader-safe serialization.

- [ ] **Step 1: Write failing tests** for manifest sizes 1/3/10, terminal disposition coverage, `usages=[]` read-back failure, and canonical usage application preceding WordPress synchronization.
- [ ] **Step 2: Run the focused media tests** and confirm the new assertions fail.
- [ ] **Step 3: Implement whole-manifest planning** using ordered assets, deterministic roles/placement keys, and explicit `NEEDS_REVIEW`/`FAILED_RETRYABLE` dispositions for unusable or failed items; ensure canonical usage rows are read back after apply before projection.
- [ ] **Step 4: Re-run media tests** and verify existing role/CAS behavior remains green.
- [ ] **Step 5: Commit** with `git add` and `git commit -m "fix: reconcile full article media manifest"`.

### Task 3: Harden completion reducer and branch isolation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentCompletionPolicy.php` only if the shared read-back shape requires it.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentCompletionRegressionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`

**Interfaces:**
- `CompletionCoordinator::aggregateCapture(string $captureId, array $children, array $evidence): array` remains the derived completion reducer.
- Capture child packets retain owner type/id, canonical read-back, relation/usage state, blockers, and current-outcome precedence.

- [ ] **Step 1: Write failing tests** proving Article intent with no Article, Article media with empty canonical usages, or non-terminal manifest disposition cannot be `COMPLETE`; prove feature failure and Knowledge review remain isolated from valid Article/MediaUsage children.
- [ ] **Step 2: Run the focused completion tests** and confirm failure.
- [ ] **Step 3: Implement intent-aware required-owner/disposition checks** and ensure the reducer preserves partial/blocked status with actionable blockers rather than promoting phase receipts.
- [ ] **Step 4: Run focused completion and capture tests** and confirm all pass.
- [ ] **Step 5: Commit** with `git add` and `git commit -m "fix: prevent false capture completion"`.

### Task 4: Full verification, execution-state checkpoint, commit, and push

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Review: `docs/architecture/V2_V3_PARITY_MATRIX.md`
- Review: all changed PHP/JS files and git diff.

- [ ] **Step 1: Run focused PHP tests** for Capture, Article, MediaUsage, Completion, and MCP contract coverage.
- [ ] **Step 2: Run the full Unit suite** with the repository command; record pre-existing failures by name if any.
- [ ] **Step 3: Run `composer run lint`, `git diff --check`, and the repository secret review; do not run destructive database commands.**
- [ ] **Step 4: Run guarded integration checks only if the exact local test environment is available; otherwise record environment-gated status without mutation.**
- [ ] **Step 5: Update `docs/architecture/V3_EXECUTION_STATE.md`** with root cause, changed boundary, verification evidence, and explicit no-live-mutation status.
- [ ] **Step 6: Commit the checkpoint** with `git add` and `git commit -m "fix: converge capture article media pipeline"`.
- [ ] **Step 7: Push the verified commit** using `git push origin main`, then verify the remote branch contains the commit with `git ls-remote origin refs/heads/main`.
