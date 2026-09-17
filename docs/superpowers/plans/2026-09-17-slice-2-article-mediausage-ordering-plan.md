# Article MediaUsage Ordering and Capture Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reconcile committed Capture Media IDs into Article `MediaUsage`
records before Article research, readiness and publication evidence are
computed, while preserving representative usages and one canonical Media.

**Architecture:** `EditorialCaptureCoordinator` owns the phase ordering;
`ArticleMediaCoordinator::ensureForPost()` owns Article slot reconciliation;
`MediaUsageReconciler` plans idempotent slot changes; `ArticleResearchPreflight`
and `ArticlePublicationGate` consume the canonical Article Usage readback.
Representative Model/Classification usages are separate endpoint identities
and are never substituted for Article usages.

**Tech Stack:** PHP 8.x, PHPUnit 11.5, existing Media repositories, WordPress
adapter and NHK V3 capture callbacks.

**Spec:** `docs/superpowers/specs/2026-09-17-intent-scoped-publication-gates-design.md`

## Global Constraints

- Slice 1 must be accepted before this plan starts.
- Use registered roles `featured_primary`, `inline_primary` and
  `inline_supporting`; do not add a `contextual` role.
- `featured_primary` and `inline_primary` are Article usages identified by
  `endpoint_type=wp_post` and the Article endpoint key. A representative usage
  on `model` or `classification` is not an Article slot.
- The one-real-image exception applies only to an `IMAGE_ARTICLE` with exactly
  one eligible real Media. It does not apply to text articles, placeholders,
  missing assets or multiple unrelated images.
- Reconciliation is idempotent and revision-aware. Every automatic change must
  produce canonical Usage readback before readiness is reported.
- Do not alter physical upload, Video, Graph or live/staging state.

## Task 1: Specify the pre-readiness MediaUsage ordering with failing tests

- [ ] **Component:** Article media policy and Capture-to-Article ordering
  tests.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/CaptureMediaIdsReuseTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php` for the post-reconcile inventory assertion.
  - Do not create a production file in this task.

  **Interfaces:** Tests consume `ArticleMediaCoordinator::ensureForPost()` and
  the existing Capture callback sequence. They produce assertions for the
  returned Article Usage readback and preserved representative usages.

  **TDD steps:**

  1. Add a failing unit test with one canonical Media, a public source/derivative
     asset pair, a Model representative Usage and a Classification
     representative Usage. Call `ensureForPost()` for an Article with
     `capture_owned_media_ids` containing that Media and the narrow one-image
     exception:

     ```php
     public function testCaptureMediaIsReconciledIntoArticleSlotsWithoutTouchingRepresentativeUsages(): void
     {
         $mediaId = $this->seedReadyMediaWithPublicAsset('media-a');
         $modelUsage = $this->seedUsage($mediaId, 'model', 'model-111', 'representative', 'default');
         $classificationUsage = $this->seedUsage($mediaId, 'classification', 'classification-cuckoo', 'representative', 'default');

         $result = $this->coordinator->ensureForPost(573, [
             'content_intent' => 'IMAGE_ARTICLE',
             'capture_owned_media_ids' => [$mediaId],
             'single_real_image_exception' => true,
             'subject_ids' => ['subject-vedette-37'],
         ]);

         $articleUsages = $this->articleUsages(573);
         self::assertSame([$mediaId], array_values(array_unique(array_column($articleUsages, 'media_id'))));
         self::assertSame($modelUsage->id(), $this->usage($modelUsage->id())->id());
         self::assertSame($classificationUsage->id(), $this->usage($classificationUsage->id())->id());
         self::assertSame('VERIFIED', $result->toArray()['canonical_readback']['media_usage']['state']);
         self::assertCount(2, $this->usagesForArticleAndRoles(573, ['featured_primary', 'inline_primary']));
     }
     ```

  2. Add a replay test that calls the coordinator twice with the same Capture
     Media IDs and asserts stable Usage UUIDs, stable Media UUID, no new binary
     asset and no duplicate Article slot. Add a multi-image test proving the
     exception is not used when the Capture owns more than one real Media.

  3. Add an ordering test with a callback log. The log must be exactly
     `semantic_writeback`, `article_media_reconcile`, `article_research`,
     `publication_gate` for the Article path. Assert that research sees the
     Article Usage IDs returned by reconciliation.

  4. Add a missing/corrupt asset test. Assert a typed `RECONCILE` or
     `REVIEW_REQUIRED` media state and a publication blocker; do not accept a
     successful research result based on a representative Usage alone.

  5. Run the focused suite and record expected pre-implementation failures:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/CaptureMediaIdsReuseTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php
     ```

     Expected failure: the Article inventory is researched before committed
     Capture media is reconciled, or the returned evidence does not contain
     canonical Article Usage readback.

  6. Run `git diff --check` on the red test diff and commit:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureMediaIdsReuseTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php
     git commit -m "test: define article media reconciliation ordering"
     ```

## Task 2: Reconcile committed Capture media before Article research

- [ ] **Component:** `ArticleMediaCoordinator`, Capture media callback and
  publication evidence assembly.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`.
  - Modify the Article media callback wiring in `public/wp-content/plugins/nhk-core/src/Plugin.php` only where it passes Capture IDs or consumes the result.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestCoordinator.php` only if its resume path can bypass the same ordering.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php` if the canonical media readback field is not preserved.
  - Test files remain the four files from Task 1.

  **Interfaces:** The capture callback consumes committed `capture_owned_media_ids`
  and produces `canonical_readback.media_usage` with Article Usage IDs and
  state. `ArticleMediaCoordinator::ensureForPost()` keeps its current method
  signature and accepts an explicit context marker
  `article_media_reconciliation=REQUIRED_BEFORE_PUBLICATION_RESEARCH`.

  **TDD steps:**

  1. Keep Task 1 red tests as the failing contract. Add a result assertion for
     the exact shape:

     ```php
     [
         'state' => 'VERIFIED',
         'endpoint_type' => 'wp_post',
         'endpoint_key' => '1:573',
         'roles' => ['featured_primary', 'inline_primary'],
         'usage_ids' => ['...'],
         'source' => 'ARTICLE_MEDIA_RECONCILIATION',
     ]
     ```

  2. In `ArticleMediaCoordinator::ensureForPost()`, preserve the existing
     `MediaUsageReconciler::plan()` and `reconcileUsage()` flow. Ensure the
     desired set is built from the committed Capture IDs before placeholder
     decisions, and that existing representative endpoint rows are never read
     as candidates for an Article slot. Return the canonical Usage rows after
     the repository writes and adapter synchronization.

  3. Keep the one-image decision explicit and bounded:

     ```php
     $singleRealImage = $contentIntent === 'IMAGE_ARTICLE'
         && count($captureOwnedMediaIds) === 1
         && $context['single_real_image_exception'] === true
         && $this->mediaIsReadyAndPublic($captureOwnedMediaIds[0]);
     ```

     Use this only to let one Media satisfy both Article mandatory roles. Do not
     use it to collapse representative and Article usages.

  4. In the Capture callback, place the Article media reconciliation call after
     semantic writeback and before the fresh Article research callback. Carry the
     returned `ArticleMediaResult` into the publication evidence rather than
     invoking `diagnoseForPost()` as the sole source of readiness. If the
     canonical Usage readback is absent or inconsistent, return a typed
     publication blocker.

  5. Preserve the existing text-Article placeholder policy and all scoped reuse
     checks. A placeholder is an explicit non-ready state; it is not a verified
     real image. Do not call a physical uploader from this code path.

  6. Run the focused suite and require PASS:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/CaptureMediaIdsReuseTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php
     ```

  7. Run PHP lint and diff checks:

     ```text
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php
     php -l public/wp-content/plugins/nhk-core/src/Plugin.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestCoordinator.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php
     git diff --check
     ```

  8. Commit the implementation family:

     ```text
     git add public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php
     git commit -m "fix: reconcile article media before readiness"
     ```

## Task 3: Verify publication evidence and preserve non-Article owners

- [ ] **Component:** Article research/gate contract and Media usage boundary.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Integration/` only through an existing guarded Article/Media test if the environment is available; do not add a live acceptance mutation.

  **Interfaces:** Tests consume the canonical Article Usage packet and the
  publication gate. They produce proof that Model/Classification/Dictionary
  usages remain independent from Article slot reconciliation.

  **TDD steps:**

  1. Add a gate test where Article `featured_primary` and `inline_primary` are
     verified after reconciliation while representative usages remain on their
     original endpoint keys. Assert publication evidence is complete.

  2. Add a gate test with only a representative Usage and no Article Usage.
     Assert `ARTICLE_MEDIA_FEATURED_MISSING` or the existing typed equivalent,
     not a false PASS. Add a text Article test proving placeholder evidence is
     reported as its existing non-ready state.

  3. Run the focused gate/research suite:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
     ```

  4. Run all unit tests, relevant integration tests against the guarded
     integration database only, lint and diff checks:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit
     vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php
     composer lint
     git diff --check
     ```

     If the integration environment is unavailable, record that fact as
     `INFRASTRUCTURE_UNAVAILABLE`; do not convert the test to a pass.

  5. Review the diff for no new Media role, no upload call, no Graph relation,
     no direct database writer and no Video path change. Run a changed-file
     secret review.

  6. Commit the regression boundary:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
     git commit -m "test: verify canonical article media evidence"
     ```

## Slice completion gate

- [ ] Committed Capture Media IDs reach Article reconciliation before fresh
  Article research and publication gating.
- [ ] Article Usage UUIDs are stable on replay; representative Usage UUIDs and
  endpoint identities are unchanged.
- [ ] The one-image exception is restricted to `IMAGE_ARTICLE` and one ready
  real Media.
- [ ] Focused tests, unit tests, guarded integration evidence, lint and diff
  checks are PASS, with unavailable infrastructure reported explicitly.
- [ ] Reviewer accepts this family before Slice 3 begins.
