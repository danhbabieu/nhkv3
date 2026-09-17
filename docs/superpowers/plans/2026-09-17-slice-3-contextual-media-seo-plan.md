# Contextual Media SEO and Public Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make public image title, alt text and caption deterministic for the
requesting context, with exact `MediaUsage` metadata taking precedence over
neutral Media metadata and WordPress Attachment compatibility fallback.

**Architecture:** Existing Article, Entity, gallery, preferred-image and
VisualSupport projections remain the public read boundaries. They share a
field-level metadata resolver and the existing public asset selector. Media
identity and asset visibility remain canonical; projection context supplies
presentation text without mutating global Media or Attachment metadata.

**Tech Stack:** PHP 8.x, PHPUnit 11.5, existing Media/MediaAsset/MediaUsage
repositories and public projection services.

**Spec:** `docs/superpowers/specs/2026-09-17-intent-scoped-publication-gates-design.md`

## Global Constraints

- Slice 2 must be accepted so Article Usage records are reliable inputs.
- Use this exact precedence for each output field: exact contextual
  `MediaUsage`, permitted subject-specific representative Usage, verified
  neutral Media metadata, WordPress Attachment fallback, explicit `MISSING`.
- Exact Usage text wins for its non-empty field. A missing Usage field may fall
  through field-by-field; Attachment text never overrides a non-empty
  canonical Usage field.
- The public asset selector/delivery remains the authority for public source
  eligibility. Never make a private source public in a projection.
- Use registered roles only. “Contextual” is a presentation context, not a
  MediaUsage role, endpoint type, predicate or Graph relation.
- Article-specific text never becomes global Media or Attachment metadata;
  Dictionary definition text never becomes image metadata.
- No AI text generation, binary upload, Media merge, Graph mutation or live
  repair is part of this slice.

## Task 1: Define contextual metadata precedence with failing tests

- [ ] **Component:** Article, Entity, gallery and VisualSupport public media
  projections.

  **Files:**

  - Create `public/wp-content/plugins/nhk-core/tests/Unit/ContextualMediaSeoProjectionTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php` if it owns shared projection fixtures.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/EntitySeoProjectionTest.php` if present and already responsible for Entity image metadata.
  - Do not modify production files in this task.

  **Interfaces:** Tests consume the current projection method signatures and
  existing in-memory repository doubles. They produce the stable return-field
  contract `metadata_source`, `title`, `alt`, `caption`, `url` and explicit
  missing state.

  **TDD steps:**

  1. Create one ready Media with one public asset and four Usage contexts:
     Article A featured text `Ảnh A`, Article B featured text `Ảnh B`, a
     subject representative text, and a Dictionary representative text. Call
     each projection with its endpoint key and assert exact contextual text:

     ```php
     public function testExactUsageMetadataWinsPerPublicContext(): void
     {
         $media = $this->seedReadyMedia('media-a', 'Đồng hồ cúc cu');
         $this->seedPublicAsset($media->id(), 'asset-a');
         $this->seedUsage($media->id(), 'wp_post', '1:54', 'featured_primary', 'default', [
             'alt' => 'Ảnh bài 54', 'title' => 'Bài 54', 'caption' => 'Mô tả bài 54',
         ]);
         $this->seedUsage($media->id(), 'wp_post', '1:57', 'featured_primary', 'default', [
             'alt' => 'Ảnh bài 57', 'title' => 'Bài 57', 'caption' => 'Mô tả bài 57',
         ]);

         self::assertSame('Ảnh bài 54', $this->articleProjection->forPost('1:54')['alt']);
         self::assertSame('Ảnh bài 57', $this->articleProjection->forPost('1:57')['alt']);
         self::assertSame('MEDIA_USAGE', $this->articleProjection->forPost('1:54')['metadata_source']);
     }
     ```

  2. Add tests for subject-specific representative fallback, verified neutral
     Media name fallback, Attachment fallback and explicit `MISSING`. Assert
     that an empty Attachment alt cannot replace exact Usage alt, and that a
     private source without an eligible public asset returns no public URL.

  3. Add a propagation test proving Article-specific alt/title/caption do not
     change `Media::name` or Attachment metadata. Add a Dictionary test proving
     definition text is absent from all image fields.

  4. Run the focused tests and record the expected failure because the current
     Article projection reads one featured Usage without contextual fallback or
     an explicit metadata source:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/ContextualMediaSeoProjectionTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/EntitySeoProjectionTest.php
     ```

  5. Run `git diff --check` and commit the red tests:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/ContextualMediaSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/EntitySeoProjectionTest.php
     git commit -m "test: define contextual media metadata precedence"
     ```

## Task 2: Implement the shared Usage-first projection contract

- [ ] **Component:** Article SEO projection, Entity media projection, public
  gallery, preferred-image SEO and VisualSupport projection.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaSeoProjection.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/PublicMediaGalleryQuery.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/PreferredImageSeoProjection.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/VisualSupportPublicProjection.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php` only if its representation method currently forces Attachment text over canonical Usage text.
  - Test files remain the files from Task 1.

  **Interfaces:** Existing public method names remain unchanged. Return arrays
  gain `metadata_source`, `title`, `alt`, `caption` and, when no eligible
  public asset exists, `state=MISSING`. Entity and gallery items carry the same
  metadata source so consumers do not invent their own fallback order.

  **TDD steps:**

  1. Keep the Task 1 tests red. Define one internal helper contract in the
     owning projection boundary rather than a new public role. The helper must
     select fields in this order:

     ```php
     private function metadataFor(
         ?MediaUsage $contextual,
         ?MediaUsage $subjectRepresentative,
         Media $media,
         ?array $attachment
     ): array {
         $fields = ['title', 'alt', 'caption'];
         $sources = [
             [$contextual, 'MEDIA_USAGE'],
             [$subjectRepresentative, 'SUBJECT_REPRESENTATIVE'],
             [$media, 'MEDIA_NEUTRAL'],
             [$attachment, 'WORDPRESS_ATTACHMENT'],
         ];
         // Select the first verified non-empty value per field and emit
         // metadata_source for the highest-precedence source used.
     }
     ```

     The implementation may share this behavior through an existing service
     boundary, but it must not add a `contextual` role or persist projection
     text as semantic data.

  2. Update `ArticleMediaSeoProjection::forPost()` to read the exact Article
     Usage tuple `wp_post + endpoint key + featured_primary + placement` and
     select its canonical public asset. Preserve the current srcset/URL
     construction and add metadata source fields.

  3. Update `EntityMediaProjection::forEntity()` and gallery cards to prefer
     exact representative/evidence/gallery context, then neutral Media
     metadata. Preserve the existing grouping semantics and public asset
     eligibility.

  4. Update `PreferredImageSeoProjection` and
     `VisualSupportPublicProjection` to consume the same field fallback and
     retain their existing requirement/feature fields. VisualSupport remains an
     exact requirement ledger; it must not create Claim, Evidence or Graph
     records.

  5. Ensure `WordPressMediaAttachmentBridge::representation()` is fallback-only
     for metadata. A non-empty canonical Usage field must survive any bridge
     call, while missing canonical fields may use verified Attachment values.

  6. Run the focused projection suite and require PASS:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/ContextualMediaSeoProjectionTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/EntitySeoProjectionTest.php
     ```

  7. Lint every modified production file and check the diff:

     ```text
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaSeoProjection.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/PublicMediaGalleryQuery.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/PreferredImageSeoProjection.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/VisualSupportPublicProjection.php
     php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php
     git diff --check
     ```

  8. Commit the implementation family:

     ```text
     git add public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaSeoProjection.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php public/wp-content/plugins/nhk-core/src/Application/Media/PublicMediaGalleryQuery.php public/wp-content/plugins/nhk-core/src/Application/Media/PreferredImageSeoProjection.php public/wp-content/plugins/nhk-core/src/Application/Media/VisualSupportPublicProjection.php public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php
     git commit -m "feat: project contextual media seo metadata"
     ```

## Task 3: Verify public contract and non-regression boundaries

- [ ] **Component:** Public media, SEO, Dictionary and VisualSupport contract
  regressions.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportReverseReconciliationTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/PublicMediaAssetProjectionTest.php` or the existing public route contract test that owns asset visibility.
  - Keep the existing `public/wp-content/plugins/nhk-core/tests/Contract` suite as a regression input; do not invent a projection contract file.
  - Do not modify upload or Video tests except to prove they remain green.

  **Interfaces:** Tests consume the public projection arrays and public asset
  selector. They produce evidence for metadata precedence, missing states,
  subject isolation and stable URL/visibility behavior.

  **TDD steps:**

  1. Add contract assertions for the full precedence chain and for the exact
     `MISSING` state. Assert public output never exposes a private source path.

  2. Add a Dictionary public-query assertion that approved contextual MediaUsage
     text is rendered while the definition remains lexical content. Add a
     delegated-concept assertion that image projection does not make the
     delegated concept an indexable competing page.

  3. Run the focused and relevant suites:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Contract \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportReverseReconciliationTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/PublicMediaAssetProjectionTest.php
     vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit
     composer lint
     git diff --check
     ```

  4. Review changed files for no Attachment metadata writes, no public/private
     visibility change, no new role/endpoint/predicate, no binary upload and no
     semantic mutation. Run a changed-file secret review.

  5. Commit the regression boundary:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportReverseReconciliationTest.php public/wp-content/plugins/nhk-core/tests/Unit/PublicMediaAssetProjectionTest.php
     git commit -m "test: verify contextual media public contracts"
     ```

## Slice completion gate

- [ ] Exact contextual Usage metadata wins without changing global Media or
  Attachment metadata.
- [ ] Subject-specific, neutral Media and Attachment fallback are verified and
  ordered; unavailable data is explicit `MISSING`.
- [ ] Public asset visibility and VisualSupport ledger laws remain intact.
- [ ] Focused tests, contract tests, unit tests, lint and diff checks are PASS.
- [ ] Reviewer accepts this family before Slice 4 begins.
