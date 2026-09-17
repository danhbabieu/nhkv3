# Exact Binary Media Dedupe and Pre-ingest Reuse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent duplicate canonical Media creation when upload bytes are an
exact checksum match, while retaining distinct Media for different bytes,
visual similarity, alternate views and different physical objects.

**Architecture:** `MediaAssetRepository::findByChecksum()` supplies exact
candidate lookup. A new stateless application resolver validates each candidate
against Media readiness, asset ownership, WordPress attachment mapping and
canonical readback before `MediaBatchUploadService` invokes physical ingest.
The existing batch idempotency and physical source/private WebP pipeline remain
unchanged for newly created binaries.

**Tech Stack:** PHP 8.x, PHPUnit 11.5, existing Media repositories,
`WordPressMediaAttachmentIngestor`, `ExistingMediaReferenceResolver` and
batch-upload manifest contracts.

**Spec:** `docs/superpowers/specs/2026-09-17-intent-scoped-publication-gates-design.md`

## Global Constraints

- Slice 4 mapping/readback and Slice 5 Dictionary Usage preservation must be
  accepted before this plan starts.
- Exact byte identity is established only by the canonical source checksum;
  filename, dimensions, visual similarity, OCR, title or user assertion do not
  establish identity.
- A checksum candidate is reusable only when Media is active/ready/non-
  placeholder, the asset belongs to that Media, Attachment mapping is valid and
  attachment readback confirms the same bytes.
- Preserve all existing MediaUsage rows, including Dictionary and Article
  contexts, when returning a reused Media.
- A non-match follows the current physical ingest path and preserves private
  source-original/public WebP laws. Batch idempotency remains independent from
  checksum reuse.
- No merge, deletion, binary mutation, Graph relation, Video path or live data
  operation is part of this slice.

## Task 1: Define exact reuse and non-match behavior with failing tests

- [ ] **Component:** Exact checksum resolver and batch upload manifest tests.

  **Files:**

  - Create `public/wp-content/plugins/nhk-core/tests/Unit/ExactMediaDuplicateResolverTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/MediaBatchUploadServiceTest.php` if it exists; otherwise create it at this exact path.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ExistingMediaReferenceResolverTest.php`.
  - Do not modify production files in this task.

  **Interfaces:** Tests consume the planned
  `ExactMediaDuplicateResolver::resolve(string $checksum): ?array` and the
  current `MediaBatchUploadService::upload()` manifest. They produce exact
  reuse, non-match and typed inconsistency assertions.

  **TDD steps:**

  1. Add a failing resolver test with two checksum candidates sorted by asset
     ID. The first candidate has a valid Media/asset/attachment/readback chain;
     assert deterministic reuse:

     ```php
     $manifest = $resolver->resolve(hash('sha256', $bytes));

     self::assertSame('REUSED', $manifest['upload_status']);
     self::assertTrue($manifest['reused']);
     self::assertSame($expectedMediaId, $manifest['media_id']);
     self::assertSame($expectedAttachmentId, $manifest['attachment_id']);
     self::assertSame($checksum, $manifest['checksum_sha256']);
     self::assertSame('VERIFIED', $manifest['attachment_readback_state']);
     ```

  2. Add exact negative tests: same filename with a different checksum,
     different bytes with matching dimensions, visually adjacent alternate
     angle, inactive/placeholder Media, missing mapping, stale asset and
     attachment readback conflict. Assert no `REUSED` result for each.

  3. Add a batch test proving a resolved exact duplicate does not call the
     physical ingestor, returns one `REUSED` item and preserves all MediaUsage
     rows. Add a non-match test proving the ingestor is called once and the
     normal `CREATED` manifest retains source/private WebP fields.

  4. Run the focused suite and record expected failure because checksum lookup
     exists only on the repository and batch upload currently ingests before it
     can reuse a canonical Media:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/ExactMediaDuplicateResolverTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaBatchUploadServiceTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ExistingMediaReferenceResolverTest.php
     ```

  5. Run `git diff --check` and commit the red tests:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/ExactMediaDuplicateResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaBatchUploadServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/ExistingMediaReferenceResolverTest.php
     git commit -m "test: define exact binary media reuse"
     ```

## Task 2: Implement canonical exact-match resolution before physical ingest

- [ ] **Component:** Exact resolver and batch upload orchestration.

  **Files:**

  - Create `public/wp-content/plugins/nhk-core/src/Application/Media/ExactMediaDuplicateResolver.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBatchUploadService.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/ExistingMediaReferenceResolver.php` only if it can safely share attachment/readback validation without changing its known-Media-ID contract.
  - Modify `public/wp-content/plugins/nhk-core/src/Contracts/Media/MediaAssetRepository.php` only if the existing checksum method needs a documented return shape; do not add a second checksum lookup.
  - Test files remain those from Task 1.

  **Interfaces:** Create
  `ExactMediaDuplicateResolver` with constructor dependencies
  `MediaAssetRepository`, `MediaRepository`, `WordPressMediaAttachmentBridge`
  or the existing `WordPressMediaAttachmentIngestor` read boundary, and a
  public method `resolve(string $checksum): ?array`. It produces a
  batch-manifest-compatible array with `upload_status`, `reused`, `media_id`,
  `attachment_id`, `asset_id`, `checksum_sha256` and typed readback state.
  `MediaBatchUploadService` receives it as an optional dependency after the
  existing repository argument, preserving current construction sites.

  **TDD steps:**

  1. Keep Task 1 red tests. Implement the resolver by calling
     `MediaAssetRepository::findByChecksum($checksum)`, sorting candidates by
     canonical asset ID, and validating every candidate in this order:
     active Media, non-placeholder/readiness, asset-to-Media ownership,
     attachment mapping, attachment readback, exact checksum agreement. Return
     the first verified manifest; return `null` for a clean non-match.

  2. If a candidate has a mapping/readback conflict, return a typed
     `INCONSISTENT` result to the batch service rather than silently selecting a
     different object. The batch service must report review/block evidence and
     must not claim a successful reuse.

  3. Update `MediaBatchUploadService::__construct()` with the optional resolver
     and compute the source checksum from the validated upload file before
     calling `WordPressMediaAttachmentIngestor::ingest()`. For a verified
     resolver result, emit `REUSED` and skip physical ingest. For `null`, retain
     the existing `CREATED` branch unchanged. For `INCONSISTENT`, emit the
     existing typed failure manifest without creating a duplicate.

  4. Keep batch idempotency fingerprint checks before per-file work. A replay
     of the same batch returns its existing manifest; an exact binary in a new
     batch reuses the canonical Media only after the resolver readback passes.

  5. Run the focused suite and require PASS:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/ExactMediaDuplicateResolverTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaBatchUploadServiceTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ExistingMediaReferenceResolverTest.php
     ```

  6. Lint modified production files and check the diff:

     ```text
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/ExactMediaDuplicateResolver.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/MediaBatchUploadService.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/ExistingMediaReferenceResolver.php
     php -l public/wp-content/plugins/nhk-core/src/Contracts/Media/MediaAssetRepository.php
     git diff --check
     ```

  7. Commit the implementation family:

     ```text
     git add public/wp-content/plugins/nhk-core/src/Application/Media/ExactMediaDuplicateResolver.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaBatchUploadService.php public/wp-content/plugins/nhk-core/src/Application/Media/ExistingMediaReferenceResolver.php public/wp-content/plugins/nhk-core/src/Contracts/Media/MediaAssetRepository.php
     git commit -m "fix: reuse exact duplicate media assets"
     ```

## Task 3: Verify batch, MCP and canonical usage non-regression

- [ ] **Component:** Batch upload, MCP widget upload and Media asset completion
  contracts.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/McpWidgetUploadTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/ExistingMediaReferenceResolverTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php`.
  - Add or modify the batch upload contract test at `public/wp-content/plugins/nhk-core/tests/Unit/MediaBatchUploadServiceTest.php`.

  **Interfaces:** Tests consume the MCP upload boundary, batch manifest,
  attachment readback and MediaUsage repositories. They produce evidence for
  exact reuse, normal creation, idempotent replay and visibility preservation.

  **TDD steps:**

  1. Add MCP assertions for `REUSED` exact bytes and `CREATED` different bytes.
     Assert same filename/different checksum is `CREATED`, and visual similarity
     without exact bytes is never `REUSED`.

  2. Add a Côn 111 regression with Media A’s Component/Article/Dictionary
     usages and exact duplicate upload bytes. Assert all usages remain attached
     to Media A and no second canonical Media appears.

  3. Add partial batch/retry tests: a previously completed batch remains
     idempotent; a new exact duplicate reuses; a different binary follows
     physical ingest; a failed readback remains distinguishable from an empty
     result.

  4. Run focused, unit, guarded integration, lint and diff checks:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/McpWidgetUploadTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/ExistingMediaReferenceResolverTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaBatchUploadServiceTest.php \
       public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php
     vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit
     composer lint
     git diff --check
     ```

     Integration execution requires the guarded `nhk_v3_test` database and
     generated media fixtures. If unavailable, report
     `INFRASTRUCTURE_UNAVAILABLE`; do not mark the physical-law assertions
     green without execution.

  5. Review changed files for no checksum-based merge/delete, no upload-path
     rewrite, no private-to-public visibility change, no Graph/Governance
     mutation and no live/staging target. Run a changed-file secret review.

  6. Commit the verification boundary:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/McpWidgetUploadTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php public/wp-content/plugins/nhk-core/tests/Unit/ExistingMediaReferenceResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaBatchUploadServiceTest.php public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php
     git commit -m "test: verify exact media dedupe boundaries"
     ```

## Slice completion gate

- [ ] Exact bytes reuse one verified canonical Media/Attachment without a
  physical ingest or duplicate.
- [ ] Different checksum, filename-only match, visual similarity and alternate
  view remain distinct Media decisions.
- [ ] Existing Article, representative and Dictionary usages survive reuse.
- [ ] Batch idempotency and private-source/public-WebP laws remain unchanged.
- [ ] Focused tests, unit tests, guarded integration evidence, lint and diff
  checks are PASS, with unavailable infrastructure explicit.
- [ ] Reviewer accepts this family as the final planned slice.
