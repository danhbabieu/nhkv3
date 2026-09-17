# Dictionary Illustration Reuse and Replacement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an approved Dictionary concept pin an existing canonical Media
as a preferred illustration, replace that illustration without touching old
Article usages, and expose it through the existing Dictionary public query.

**Architecture:** `DictionaryCurationService` remains the governed lexical
mutation boundary. It validates concept approval and Media readiness before
writing one existing `MediaUsage` tuple on `dictionary_concept`.
`DictionaryRuntime` wires the Media repositories into that service;
`DictionaryPublicQuery` continues to resolve images through
`EntityMediaProjection`. No Dictionary binary store, Evidence, Claim or Graph
relation is introduced.

**Tech Stack:** PHP 8.x, PHPUnit 11.5, existing Dictionary repositories,
`MediaService`, MediaUsage roles and public query/sitemap contracts.

**Spec:** `docs/superpowers/specs/2026-09-17-intent-scoped-publication-gates-design.md`

## Global Constraints

- Slice 3 contextual projection and Slice 4 canonical attachment readback must
  be accepted before this plan starts.
- Only an approved, unambiguous `DictionaryConcept` may select a preferred
  illustration. Draft, rejected, delegated-without-owner and ambiguous
  concepts are not public preferred-image writers.
- Reuse an existing ready Media and eligible public asset. Do not copy binary
  data, create an Attachment, create a Media, create Evidence or create Graph
  relations.
- Use `endpoint_type=dictionary_concept`, the concept ID as endpoint key,
  registered role `representative`, placement `preferred_illustration`,
  `selection_source=USER_EXPLICIT` and `selection_policy=PINNED`.
- Replacing Media A with Media B changes only the Dictionary preferred Usage;
  old Article/Model/Classification usages remain intact.
- Delegated concepts may display the reused image through their owner-aware
  projection but remain non-dedicated and non-indexable when the existing
  Dictionary contract says so.

## Task 1: Define approved reuse and replacement with failing tests

- [ ] **Component:** Dictionary curation, public query and media observation
  boundary tests.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryCurationServiceTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRuntimeContractTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMediaObservationBoundaryTest.php`.
  - Do not modify production files in this task.

  **Interfaces:** Tests consume the planned public method on
  `DictionaryCurationService` and the existing Dictionary public query. They
  produce exact MediaUsage identity, selection metadata and lexical-boundary
  assertions.

  **TDD steps:**

  1. Add a failing test for approved concept `cuckoo-clock`, ready Media A and
     one public asset. Call the planned method:

     ```php
     $usage = $service->selectPreferredIllustration(
         'concept-cuckoo-clock',
         $concept->revision(),
         'media-a',
         'Đồng hồ chim cúc cu',
         'Ảnh đồng hồ chim cúc cu',
         'Minh họa cho mục từ.'
     );

     self::assertSame('dictionary_concept', $usage->endpointType());
     self::assertSame('representative', $usage->role());
     self::assertSame('preferred_illustration', $usage->placementKey());
     self::assertSame('USER_EXPLICIT', $usage->selectionSource());
     self::assertSame('PINNED', $usage->selectionPolicy());
     ```

  2. Add a replacement test selecting Media B after Media A. Assert one active
     preferred Dictionary Usage, stable Article Usage IDs for Media A, and no
     new Media or Attachment. Add an idempotent replay assertion.

  3. Add rejected, draft, ambiguous and non-ready Media tests. Assert typed
     review/block results and no public preferred image. Add a test proving the
     selected alt/title/caption are not copied into `Media::name`, definition,
     or Attachment metadata.

  4. Add a Côn 111 boundary test: Media A can be Model/Component representative,
     Article technical detail and Dictionary preferred illustration while
     marking 111, mounting detail and installed-context Media remain distinct.
     Assert no Evidence or Graph row is created.

  5. Run the focused suite and record expected failure because the current
     curation service has no MediaUsage selection method:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryCurationServiceTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRuntimeContractTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMediaObservationBoundaryTest.php
     ```

  6. Run `git diff --check` and commit the red tests:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/DictionaryCurationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRuntimeContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMediaObservationBoundaryTest.php
     git commit -m "test: define dictionary illustration reuse"
     ```

## Task 2: Add the governed Dictionary preferred-illustration operation

- [ ] **Component:** Dictionary curation service, runtime wiring, MediaUsage
  creation/update and public query selection.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryCurationService.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRuntime.php`.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php` to expose optional selection source/policy/active slot arguments without changing existing callers.
  - Modify `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPublicQuery.php` only if it needs to prefer the pinned placement before generic representative output.
  - Test files remain the four files from Task 1.

  **Interfaces:** Add the exact method
  `DictionaryCurationService::selectPreferredIllustration(string $conceptId,
  int $expectedConceptRevision, string $mediaId, string $title='', string
  $altText='', string $caption=''): MediaUsage|array`. It consumes an approved
  concept, expected revision and existing Media ID. It produces the canonical
  Dictionary Usage or a typed blocked/review result. Extend
  `MediaService::addUsage()` with optional trailing selection fields so current
  callers remain source-compatible.

  **TDD steps:**

  1. Keep Task 1 tests red. Add repository/service dependencies to
     `DictionaryCurationService` through its existing constructor expansion:
     `MediaService`, `MediaRepository`, `MediaAssetRepository` and the
     `MediaUsageRepository`/updater boundary. Wire them from `DictionaryRuntime`
     using the existing runtime repositories. Do not create a second
     Dictionary media service.

  2. Validate concept status and expected revision before any Usage write.
     Validate Media active/readiness, non-placeholder status, an eligible public
     asset and canonical readback. Return a typed review/block result for every
     failed precondition.

  3. Call `MediaService::addUsage()` with the exact identity tuple and selection
     fields:

     ```php
     $usage = $this->media->addUsage(
         $mediaId,
         'dictionary_concept',
         $conceptId,
         'representative',
         $title,
         $altText,
         $caption,
         0,
         'preferred_illustration',
         'USER_EXPLICIT',
         'PINNED',
         'preferred_illustration'
     );
     ```

     The implementation must reconcile the existing placement row when
     replacing Media A, not append an unbounded second preferred row. Preserve
     the MediaUsage UUID when the repository’s identity/update contract allows
     it; otherwise retire the prior Dictionary row through the existing typed
     updater and read back one active row.

  4. Update `DictionaryPublicQuery` image selection to prefer the exact pinned
     placement, then its existing representative projection. Keep delegated
     concept ownership, `dedicated`, `indexable` and owner-route validation
     unchanged.

  5. Run the focused suite and require PASS:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryCurationServiceTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRuntimeContractTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMediaObservationBoundaryTest.php
     ```

  6. Lint modified production files and check the diff:

     ```text
     php -l public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryCurationService.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRuntime.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php
     php -l public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPublicQuery.php
     git diff --check
     ```

  7. Commit the implementation family:

     ```text
     git add public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryCurationService.php public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRuntime.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPublicQuery.php
     git commit -m "feat: reuse canonical media for dictionary illustrations"
     ```

## Task 3: Verify lexical, projection and sitemap boundaries

- [ ] **Component:** Dictionary public query, sitemap and observation contracts.

  **Files:**

  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRuntimeContractTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryCurationServiceTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMediaObservationBoundaryTest.php`.
  - Modify `public/wp-content/plugins/nhk-core/tests/Unit/SitemapIndexabilityProjectionTest.php` for the dedicated/indexable sitemap contract.

  **Interfaces:** Tests consume curation, Entity projection, Dictionary query
  and sitemap output. They produce proof that illustration reuse is
  presentation-only and delegated concepts do not create competing indexable
  pages.

  **TDD steps:**

  1. Assert public detail/hub output includes the pinned illustration and
     contextual metadata while `definition` remains the Dictionary lexical
     field. Assert no `claim`, `evidence`, `relation` or binary-store field is
     emitted.

  2. Assert replacement A-to-B leaves every old Article and representative
     Usage attached to A, and the sitemap includes only concepts that pass the
     existing dedicated/indexable policy.

  3. Run focused, Dictionary contract, unit, lint and diff suites:

     ```text
     vendor/bin/phpunit --configuration phpunit.xml.dist \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryCurationServiceTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRuntimeContractTest.php \
       public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMediaObservationBoundaryTest.php \
       public/wp-content/plugins/nhk-core/tests/Contract
     vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit
     composer lint
     git diff --check
     ```

  4. Review the diff for no binary copy, Attachment creation, Graph/Evidence
     write, new role or public route. Run a changed-file secret review.

  5. Commit the verification boundary:

     ```text
     git add public/wp-content/plugins/nhk-core/tests/Unit/DictionaryCurationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRuntimeContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMediaObservationBoundaryTest.php public/wp-content/plugins/nhk-core/tests/Unit/SitemapIndexabilityProjectionTest.php
     git commit -m "test: verify dictionary illustration boundaries"
     ```

## Slice completion gate

- [ ] Approved concepts can pin and replace an existing canonical Media through
  one typed Dictionary `MediaUsage`.
- [ ] Article, Model and Classification usages remain unchanged on replacement.
- [ ] Ambiguous/unapproved/non-ready selections cannot become public preferred
  illustrations.
- [ ] Dictionary remains lexical; no binary, Evidence or Graph store is added.
- [ ] Focused tests, contracts, unit tests, lint and diff checks are PASS.
- [ ] Reviewer accepts this family before Slice 6 begins.
