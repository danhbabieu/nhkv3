# Image Editor Re-adoption and Attachment Readback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make edited WordPress attachments re-adopt into their existing
canonical Media identity, reconcile missing or changed source/derivative assets,
preserve every unrelated `MediaUsage`, and return typed attachment readback
states through MCP.

**Architecture:** The existing attachment mapping remains the identity bridge.
`WordPressMediaAttachmentBridge::adoptAttachment()` compares the current
physical file to the mapped canonical asset and reconciles assets in place.
`WordPressMediaAttachmentIngestor::read()` joins physical validity with the
mapping and canonical Media state. The existing physical ingest pipeline is
not rewritten.

**Tech Stack:** PHP 8.x, PHPUnit 11.5, WordPress attachment APIs, GD/WebP
processing already owned by the attachment ingestor, and guarded integration
database tests.

**Spec:** `docs/superpowers/specs/2026-09-17-intent-scoped-publication-gates-design.md`

## Global Constraints

- Slice 3 must be accepted before this plan starts.
- Preserve one Media UUID, all representative/Article/Dictionary/technical
  Usage UUIDs, one private source asset and one public WebP derivative.
- Attachment 572 is a design fixture only. Do not inspect or mutate the live
  attachment during implementation.
- `WordPressMediaAttachmentIngestor::ingest()` retains its current physical
  image validation, orientation, resize, private source and public WebP laws.
- Mapping conflicts, missing physical files, missing canonical assets and
  checksum disagreement are typed states; they are never silently converted to
  a new Media or a false `null` read.
- No direct SQL writer, binary upload through ChatGPT, live repair, Media merge,
  Graph mutation or Video change is part of this slice.

## Task 1: Define edited-attachment identity and readback failures with tests

- [ ] **Component:** Attachment bridge, physical ingestor and MCP attachment
  read contract tests.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php` if it owns attachment hook/read contracts.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/McpReadContractTest.php`.
  - Do not change production files in this task.

  **Interfaces:** Tests consume `WordPressMediaAttachmentBridge::adoptAttachment()`,
  `WordPressMediaAttachmentIngestor::read()` and
  `McpReadHandler::mediaAttachmentGet()`. They produce typed state and identity
  preservation assertions.

  **TDD steps:**

  1. Add a failing integration test that maps one attachment to Media A, writes
     a changed physical image at the same attachment path, calls
     `adoptAttachment()`, and asserts:

     ```php
     $result = $bridge->adoptAttachment(572, ['selection_source' => 'IMAGE_EDITOR']);

     self::assertSame($mediaA->id(), $result);
     self::assertCount(1, $mediaRepository->findByStableKey($mediaA->stableKey()));
     self::assertSame('VERIFIED', $ingestor->read(572)['readback_state']);
     self::assertSame($articleUsageId, $usageRepository->find($articleUsageId)->id());
     self::assertSame($dictionaryUsageId, $usageRepository->find($dictionaryUsageId)->id());
     ```

     Assert the mapped source checksum, dimensions and public derivative are
     reconciled while the Media and Usage UUIDs remain stable.

  2. Add a repeated-adoption test proving the second call performs no duplicate
     Media or asset creation and returns the same canonical IDs. Add a missing
     physical file test returning `UNAVAILABLE`, and a mapping-to-another-Media
     test returning `INCONSISTENT` with no new Media.

  3. Add a read contract test proving an existing physical attachment with a
     missing canonical asset is not returned as `null`. Assert the response
     includes `attachment_id`, `media_id`, `readback_state=INCONSISTENT`, and a
     stable error code. A genuinely unknown attachment remains `NOT_FOUND`.

  4. Run the focused tests and record expected failures because the current
     bridge returns early when mapped assets exist and the current read is
     physical-only:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php \
       public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/McpReadContractTest.php
     ```

  5. Run `git diff --check` and commit the red tests:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php
     git commit -m "test: define canonical attachment readback states"
     ```

## Task 2: Reconcile mapped assets in place and join canonical readback

- [ ] **Component:** Attachment mapping/adoption, asset reconciliation and MCP
  read path.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php` with a bounded asset reconciliation operation.
  - Modify `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentIngestor.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Contracts/Media/WordPressMediaAttachmentIngestor.php` only if the public read array requires a contract annotation; do not create a duplicate contract.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressArticleMediaAdapter.php` only if the mapping read must cross its existing adapter boundary.
  - Test files remain those from Task 1.

  **Interfaces:** Add the exact mapping read
  `WordPressMediaAttachmentBridge::bindingForAttachment(int $attachmentId): ?array`.
  It returns attachment ID, Media ID, canonical asset IDs, storage keys and
  current readback state. Add an in-place asset operation with an explicit
  signature such as
  `MediaService::reconcileAsset(string $mediaId, string $assetId, array $spec): MediaAsset`.
  Extend the existing attachment read array with `readback_state` and canonical
  IDs; do not change the public `read(int $attachmentId): ?array` entry point.

  **TDD steps:**

  1. Keep Task 1 red tests. Implement `bindingForAttachment()` by reading the
     existing mapping table through the bridge’s repository boundary and
     resolving the mapped Media/assets. A missing mapping returns `null`; a
     mapping whose Media or asset cannot be resolved returns a typed
     `INCONSISTENT` binding, not an invented Media.

  2. Implement `MediaService::reconcileAsset()` using the existing asset
     repository update contract. Validate that the asset belongs to the mapped
     Media, preserve its UUID and visibility, update checksum/storage key/size/
     dimensions/metadata, and increment its revision through the existing
     optimistic path. Reject a cross-Media asset ID.

  3. Update `adoptAttachment()` so it always computes the current physical
     source checksum before its existing early-return branch. For a mapped
     attachment, compare the current source and derivative facts; when either
     canonical asset is absent or stale, regenerate the private source and
     public WebP through the existing physical helper, then call
     `reconcileAsset()` on the mapped asset IDs. Save the same mapping and
     preserve every Usage row. A mapping conflict remains a typed hard failure.

  4. Update `WordPressMediaAttachmentIngestor::read()` to merge its current
     physical read with `bindingForAttachment()`. Return one of:
     `VERIFIED` when physical and canonical state agree,
     `UNAVAILABLE` when the physical file cannot be read,
     `INCONSISTENT` when mapping/canonical asset state conflicts, and
     `NOT_FOUND` when WordPress has no attachment. Keep physical-only callers
     able to distinguish a missing mapping from a missing attachment.

  5. Update `McpReadHandler::mediaAttachmentGet()` to return the typed response
     without treating `INCONSISTENT` as `null`. Preserve the current public MCP
     operation name and read-only behavior.

  6. Run the focused tests and require PASS. Include the existing MCP read
     contract test path discovered during repository inspection in the same
     command; the known attachment, hook and integration coverage is:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php \
       public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php
     ```

  7. Lint modified production files and run the diff check:

     ```text
     php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php
     php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentIngestor.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php
     php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressArticleMediaAdapter.php
     git diff --check
     ```

  8. Commit the implementation family:

     ```text
     git add public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentIngestor.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressArticleMediaAdapter.php
     git commit -m "fix: reconcile edited wordpress attachments with canonical media"
     ```

## Task 3: Verify hooks, identity preservation and failure typing

- [ ] **Component:** WordPress attachment hooks, MCP contracts and guarded
  integration readback.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/McpReadContractTest.php`.
  - Do not modify `Plugin.php` unless a test proves the existing attachment
    hooks fail to call the repaired adoption/read path.

  **Interfaces:** Tests consume existing attachment hooks, bridge adoption,
  MCP readback and Media projections. They produce proof that re-adoption does
  not alter unrelated usages or public/private visibility.

  **TDD steps:**

  1. Add assertions for `add_attachment`, `edit_attachment` and
     `rest_after_insert_attachment` hook registration and for the same mapped
     Media UUID after each event.

  2. Add a full readback assertion for source PRIVATE, derivative PUBLIC,
     representative and Article/Dictionary/technical Usage preservation, and
     stable `attachmentForMedia()` mapping. Add typed failure assertions for
     unavailable physical file and conflicting mapping.

  3. Run the focused, complete unit, guarded integration, lint and diff suites:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php \
       public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/McpReadContractTest.php \
       public/wp-content/plugins/nhk-core/tests/Contract
     vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit
     composer lint
     git diff --check
     ```

     Integration execution requires the guarded `nhk_v3_test` database and
     generated test WordPress media fixtures. If unavailable, report
     `INFRASTRUCTURE_UNAVAILABLE` without weakening unit assertions.

  4. Review the diff for no physical uploader rewrite, no `wp_upload_bits`
     change, no duplicate Media creation branch, no direct database writer and
     no live/staging target. Run a changed-file secret review.

  5. Commit the verification boundary:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceCompletionTest.php public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php
     git commit -m "test: verify edited attachment canonical readback"
     ```

## Slice completion gate

- [ ] Edited physical bytes are reconciled to the mapped Media identity without
  creating a second Media.
- [ ] Source/derivative assets retain visibility laws and Usage UUIDs.
- [ ] MCP readback distinguishes `VERIFIED`, `UNAVAILABLE`, `INCONSISTENT` and
  `NOT_FOUND`.
- [ ] Focused tests, guarded integration evidence, unit tests, lint and diff
  checks are PASS, with unavailable infrastructure explicit.
- [ ] Reviewer accepts this family before Slice 5 begins.
