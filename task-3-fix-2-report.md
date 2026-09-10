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
