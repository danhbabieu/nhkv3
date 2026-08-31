# NHK V3 P3 Integration Acceptance

## Scope

- Workspace: `/Users/imac24-2125d/Developer/nhk-v3`
- Development database: `nhk_v3`
- Integration-test database: `nhk_v3_test`
- Destructive integration operations are guarded by `TestDatabaseGuard` and must never target `nhk_v3`.

## Acceptance status

P3 STATUS: ACCEPTED

## Required evidence

The final run must record real integration evidence for UUID `BINARY(16)` round-trip lookup, migrations 001/002, stable-key race handling, optimistic locking for update/retire/reactivate, lifecycle and retired filtering, cursor pagination, generic authority endpoint resolution, the Graph/Authority vertical slice, and main database health. Unit tests do not substitute for these checks.

Acceptance evidence:

- MySQL: `mysqld is alive` on `127.0.0.1:3306`; `wp db check` passes.
- Test DB: `nhk_v3_test`; all destructive integration operations are guarded and isolated there.
- Integration command: `NHK_WP_TEST_DB=nhk_v3_test NHK_WP_TEST_PATH=public composer test`.
- Result: 37 tests, 100 assertions, 0 skipped.
- Coverage includes migrations 001/002 up/idempotency/down/up, UUID binary persistence, stable-key idempotency and two-connection concurrency, optimistic locking, lifecycle/filtering, cursor pagination, endpoint resolution, and the Post→Authority graph vertical slice.
- Main DB `nhk_v3` smoke/health: reachable; migration current 2, target 2; migration not required; graph and authority storage ready.
