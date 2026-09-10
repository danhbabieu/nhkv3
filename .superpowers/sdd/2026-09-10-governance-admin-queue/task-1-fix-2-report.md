# Task 1 Fix 2 Report — Governance Admin Queue

Date: 2026-09-10

## Scope

Fixed only the three issues identified in `task-1-fix-1-review.md`:

1. Queue rows now fail closed when canonical operation/entity bindings or
   expected revisions are unreadable. `relation_create` keeps its null/zero
   normalization, and targetless `create`/`ingest` keeps the supported
   null/empty/zero expected-revision representation.
2. UUID search bindings now use compact hex for `HEX(proposal_uuid)` and
   `HEX(target_uuid)` while retaining the original escaped search for text
   fields, so canonical and hyphenated partial UUID searches match.
3. Tests assert the explicit UUID SQL bindings and canonical binding outcomes,
   while retaining the recorded count/item SQL and result assertions.

No docs, UI, MCP, schema, database, or unrelated dirty files were changed.

## TDD evidence

The focused test was run after adding the regression tests and failed 4 tests:
the UUID binding expectation and the three invalid-binding cases. After the
minimal implementation, the focused test passed.

## Verification

- `GovernanceQueueQueryTest`: 29 tests, 106 assertions — PASS.
- Focused related Governance tests: 61 tests, 201 assertions — PASS.
- PHP lint for implementation and test — PASS.
- `git diff --check` — PASS.
- No live MySQL/WordPress integration claim; the repository integration runtime
  was not required for this unit-scope fix.

## Files

- `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/WpdbGovernanceQueueQuery.php`
- `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueQueryTest.php`
- This report.
