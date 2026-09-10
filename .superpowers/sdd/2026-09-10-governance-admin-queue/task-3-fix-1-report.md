# Task 3 Fix Round 1 Report — Governance Queue Actions

Date: 2026-09-10

## Findings addressed

- Every mutating queue action now requires `revision`, `state`,
  `content_fingerprint` and `dependency_fingerprint` in the submitted
  snapshot. Missing fields fail closed as `STALE_SNAPSHOT` before the
  canonical port is read or mutated.
- Snapshot values are type-checked and bounded: revision is a positive
  integer, state is an allowlisted `ProposalState`, and both fingerprints are
  non-empty printable strings of at most 128 bytes. Existing canonical
  revision, state and fingerprint equality checks remain in force.
- Exception diagnostics no longer expose unexpected exception or database
  text. Known governance exception messages are whitespace-normalized and
  capped at 160 characters; unexpected exceptions receive the bounded,
  human-readable message `Governance action failed.`.

## Tests added

The focused suite now covers omitted snapshot fields, malformed revision/state/
fingerprint values, and a long multiline unexpected exception. It also retains
the bulk partial-result test and canonical eligibility/apply invocation checks.

## Verification

```text
vendor/bin/phpunit --filter GovernanceQueueActionServiceTest
Tests: 11, Assertions: 66, Failures: 0, Errors: 0

vendor/bin/phpunit --filter 'GovernanceQueueActionServiceTest|GovernanceActionPortTest|GovernanceCoreTest|GovernanceApplyContractTest'
Tests: 50, Assertions: 181, Failures: 0, Errors: 0

composer lint
exit 0

php -l .../GovernanceQueueActionService.php
No syntax errors detected

git diff --check
Clean
```

The PHPUnit run retains repository-existing warnings/deprecations. No UI,
MCP, migration, remote, repository or database changes were made.
