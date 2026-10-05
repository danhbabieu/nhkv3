# NHK V3 P3 Integration Acceptance

> **NON-NORMATIVE.** Đây là test/environment evidence. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

## Scope

- Workspace: `/Users/imac24-2125d/Developer/nhk-v3`
- Development database: `nhk_v3`
- Authorized TEST RUNTIME: environment `staging`, database `erourxcg_nhkv3`,
  site `https://demo.1945.vn`, runtime identity `nhk-v3`.
- Destructive integration operations are guarded by `TestDatabaseGuard` and
  must verify the complete identity tuple before mutation.

## Acceptance status

P3 STATUS: ACCEPTED

## Required evidence

The final run must record real integration evidence for UUID `BINARY(16)` round-trip lookup, migrations 001/002, stable-key race handling, optimistic locking for update/retire/reactivate, lifecycle and retired filtering, cursor pagination, generic authority endpoint resolution, the Graph/Authority vertical slice, and main database health. Unit tests do not substitute for these checks.

Acceptance evidence:

- MySQL: `mysqld is alive` on `127.0.0.1:3306`; `wp db check` passes.
- Test runtime: `staging / erourxcg_nhkv3 / https://demo.1945.vn / nhk-v3`;
  all destructive integration operations are guarded there.
- Integration command: `NHK_WP_TEST_PATH=public composer test`.
- Result: 37 tests, 100 assertions, 0 skipped.
- Coverage includes migrations 001/002 up/idempotency/down/up, UUID binary persistence, stable-key idempotency and two-connection concurrency, optimistic locking, lifecycle/filtering, cursor pagination, endpoint resolution, and the Post→Authority graph vertical slice.
- Main DB `nhk_v3` smoke/health: reachable; migration current 2, target 2; migration not required; graph and authority storage ready.
