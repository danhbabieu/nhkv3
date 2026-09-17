# Task 3 Fix Round 2 Report

## Result

Implemented the requested action-aware mutation snapshot validation in
`GovernanceQueueActionService`.

- Every mutation requires a valid integer `revision` and registered `state`.
- `approve` and `apply` additionally require bounded, printable, non-empty
  `content_fingerprint` and `dependency_fingerprint` values, and compare them
  against the current proposal.
- `submit` and `reject` validate only their scoped revision/state snapshot,
  matching their canonical `GovernanceActionPort` usage.
- Missing approval/apply fingerprints remain fail-closed as `STALE_SNAPSHOT`.
- Existing bounded diagnostic handling and mutation delegation are unchanged.

## TDD evidence

The new submit/reject regression initially failed because the service required
all four snapshot fields. After the action-aware change, the focused suite
passed.

## Verification

- `vendor/bin/phpunit --filter GovernanceQueueActionServiceTest`: 13 tests,
  74 assertions, pass.
- Relevant governance filter: 52 tests, 189 assertions, pass; existing
  warnings/deprecations remain reported by PHPUnit.
- PHP lint passed for the changed service and test files.
- `git diff --check` passed.

Only the Task 3 service and focused test files were staged for the additive
commit. Existing unrelated `docs/agent-handoff/` work was preserved.

## Slice 2 Article Media Evidence Review Fix Round

Replaced the ArticlePublicationGate media test's preloaded completion/readback
state with an in-memory `ArticleMediaCoordinator` reconciliation followed by
`CaptureArticlePreflightHandoff`. The test now asserts the exact verified
`wp_post:1:573` Article readback, both mandatory registered roles, non-empty
Usage IDs, `ARTICLE_MEDIA_RECONCILIATION` provenance and no blockers. It also
snapshots Model, Classification and Dictionary representative Usage tuples
before and after reconciliation and proves they are unchanged. The Capture
convergence test now invokes the real `ArticlePublicationGate` against evidence
built from its canonical Media readback, and the text placeholder research test
asserts `readyForDraft === false`.

## Verification

- Focused Slice 2 suite: 87 tests, 377 assertions, pass; one existing warning.
- Whole Unit: 1,779 tests, 8,749 assertions; 4 unrelated pre-existing failures
  in DemoCutover/media fixture tests, with 15 warnings and 18 deprecations.
- Guarded WordPress Media integration: `INFRASTRUCTURE_UNAVAILABLE`; all 5
  tests skipped because `NHK_WP_TEST_PATH=public` is unset.
- `composer lint`: pass.
- Changed-test PHP lint: pass.
- `git diff --check`: pass.
- Changed-scope secret review: pass; no credential or private-key patterns.

No production code, uploader, Graph relation, Video path, database, live or
staging state, deployment or push was changed.
