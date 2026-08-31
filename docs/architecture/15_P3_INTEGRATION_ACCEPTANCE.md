# NHK V3 P3 Integration Acceptance

## Scope

- Workspace: `/Users/imac24-2125d/Developer/nhk-v3`
- Development database: `nhk_v3`
- Integration-test database: `nhk_v3_test`
- Destructive integration operations are guarded by `TestDatabaseGuard` and must never target `nhk_v3`.

## Acceptance status

P3 STATUS: BLOCKED — the local MySQL server was unavailable during this acceptance run, so the required DB integration gates could not be executed. This document must not be changed to `ACCEPTED` until the complete suite passes with zero mandatory skips.

## Required evidence

The final run must record real integration evidence for UUID `BINARY(16)` round-trip lookup, migrations 001/002, stable-key race handling, optimistic locking for update/retire/reactivate, lifecycle and retired filtering, cursor pagination, generic authority endpoint resolution, the Graph/Authority vertical slice, and main database health. Unit tests do not substitute for these checks.

The current implementation includes the shared UUID codec lookup fix, guarded test-database harness, migration idempotency coverage, and initial DB persistence coverage. Remaining acceptance evidence is pending the test database and the complete integration scenarios.
