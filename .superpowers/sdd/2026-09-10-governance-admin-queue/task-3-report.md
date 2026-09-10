# Task 3 Report — Secure Governance Queue Actions

Date: 2026-09-10

## Outcome

Implemented `GovernanceQueueActionService` at:

`public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php`

Added its focused unit suite at:

`public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueActionServiceTest.php`

No Admin UI, MCP catalog/tool, repository, SQL, or documentation runtime
changes were made. This report is the explicitly requested Task 3 evidence
artifact.

## Implemented boundary

The service accepts only the four canonical queue lifecycle actions:

- `submit` requires `nhk_submit_proposals` and a draft proposal.
- `approve` and `reject` require `nhk_approve_proposals` and a draft or
  submitted proposal.
- `apply` requires `nhk_apply_proposals` and an approved proposal, with an
  already-applied proposal permitted only for the canonical idempotent replay.

Each single action:

1. Validates the action and RFC UUID before reading or mutating anything.
2. Checks the mapped capability.
3. Reloads the proposal through `GovernanceActionPort::find()`.
4. Fails closed for a missing proposal, lifecycle mismatch, or a stale
   proposal ID, revision, state, content fingerprint, or dependency
   fingerprint snapshot.
5. For apply, calls `GovernanceActionPort::eligibility()` first and preserves
   all canonical reason codes. Only `ready` or the single
   `ALREADY_APPLIED` idempotency reason can proceed to the canonical apply
   port.
6. Delegates mutation exclusively to `GovernanceActionPort`; the service has
   no repository, WordPress, SQL, or direct persistence dependency.

Bulk execution invokes the same single-item path independently for every item.
It returns `selected`, `succeeded`, `failed`, `failures`, and per-item
`results`, so one missing, stale, denied, ineligible, or otherwise failed item
does not discard independent successes. Failure records retain the proposal ID,
action, state, reason, and bounded exception message when applicable.

## Test coverage

The focused suite covers:

- Single apply success and canonical result propagation.
- Repeated idempotent apply.
- Bulk approval with an independent nonexistent item.
- Bulk apply with an independent eligibility failure.
- Multiple eligibility reason-code preservation.
- Stale revision, content fingerprint, dependency fingerprint, and state.
- Invalid action, invalid UUID, missing capability, and invalid lifecycle action.
- Capability mapping and actor forwarding for submit, reject, and approve.
- No apply call when eligibility is blocked.

The recording port test double verifies call ordering and delegation without
introducing a production writer.

## Verification evidence

TDD RED:

```text
vendor/bin/phpunit --filter GovernanceQueueActionServiceTest
7 errors: Class "NHK\\Core\\Infrastructure\\Admin\\GovernanceQueueActionService" not found
```

Focused GREEN:

```text
vendor/bin/phpunit --filter GovernanceQueueActionServiceTest
Tests: 8, Assertions: 39, Errors: 0, Failures: 0
```

Relevant Governance regression suite:

```text
vendor/bin/phpunit --filter 'GovernanceQueueActionServiceTest|GovernanceActionPortTest|GovernanceCoreTest|GovernanceApplyContractTest'
Tests: 47, Assertions: 154, Errors: 0, Failures: 0
```

Static and hygiene checks:

```text
php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php
No syntax errors detected

rg -n '\$wpdb->query|INSERT INTO|UPDATE .* SET|DELETE FROM|->save\(|->create\(' public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php
No matches

git diff --check
Clean
```

PHPUnit reports pre-existing warnings/deprecations in the repository test
environment; no test failures or errors were introduced.

## Scope and worktree safety

Only these Task 3 files are intended for staging:

- `.superpowers/sdd/2026-09-10-governance-admin-queue/task-3-report.md`
- `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php`
- `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueActionServiceTest.php`

The pre-existing untracked `docs/agent-handoff/` directory was preserved and
not staged.
