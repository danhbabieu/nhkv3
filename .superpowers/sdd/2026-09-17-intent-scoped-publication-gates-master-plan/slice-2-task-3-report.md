# Slice 2 Task 3 — Canonical Article Media evidence report

## Result

Added local-only regression coverage for publication evidence and preservation
of non-Article MediaUsage owners. No production code, roles, contracts,
uploads, Graph relations, Video paths, database state, staging/live records or
external runtime state were changed.

## Coverage added

- `ArticlePublicationGateTest` proves verified Article
  `featured_primary`/`inline_primary` readback is sufficient for publication
  while Model, Classification and Dictionary representative usages retain
  their original endpoint keys and are excluded from Article Usage IDs.
- `ArticlePublicationGateTest` retains the representative-only negative case,
  which reports `MEDIAUSAGE_INCOMPLETE` and
  `ARTICLE_MEDIA_FEATURED_MISSING` instead of passing publication.
- `ArticleResearchPreflightTest` proves a text Article with placeholder
  MediaUsage readback remains `REVIEW_REQUIRED`, non-complete and reports the
  existing typed Article media blockers.
- `ArticleMediaPolicyTest` proves Dictionary representative Usage identity and
  its complete persisted fields remain unchanged during Article slot
  reconciliation.
- `EditorialCaptureConvergenceE2ETest` proves the verified canonical Article
  MediaUsage packet reaches the publication handoff separately from Model,
  Classification and Dictionary representative usages.

## Verification

- Focused gate/research/media/E2E suite: **PASS** — 87 tests, 368 assertions,
  1 existing warning.
- Whole plugin Unit suite: **BASELINE FAILURES OUTSIDE THIS TASK** — 1,779
  tests, 8,749 assertions, 5 failures. `DemoCutoverCliContractTest` receives
  `REMOTE_DEPLOYMENT_FAILED` instead of the expected
  `REMOTE_DEPLOYMENT_CONFIG_REQUIRED`; `NhkDeployVerifyCliContractTest`
  receives `REMOTE_DEPLOYMENT_FAILED` instead of the expected
  `WORKTREE_NOT_CLEAN` or `REMOTE_DEPLOYMENT_CONFIG_REQUIRED`; and three
  media-route/file checks fail because
  `public/wp-content/uploads/integration-source-original-5.webp` is absent:
  one `MediaAssetDeliveryTest` case and two `PublicMediaAssetRoutesTest`
  cases.
- Guarded `WordPressMediaIngestIntegrationTest`: **INFRASTRUCTURE_UNAVAILABLE**
  — 5 tests skipped because `NHK_WP_TEST_PATH=public` is not configured.
- `composer lint`: **PASS**.
- Explicit PHP lint for all four changed tests: **PASS**.
- `git diff --check`: **PASS**.
- Changed-file secret review: **PASS**; no credential-like material detected.
- Scope review: only the four requested Unit test files changed for the
  commit; no production file was modified.

This report is included in the documentation-only correction commit because
the review fix corrects its recorded whole-Unit baseline.

## Commit

- SHA: `116417a6`
- Message: `test: verify canonical article media evidence`
