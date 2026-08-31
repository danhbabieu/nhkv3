# P4 Integration Test Environment

Integration tests use the local Homebrew MySQL server on `127.0.0.1:3306` and the database `nhk_v3_test`. Destructive operations are guarded by an exact `SELECT DATABASE()` check and must fail for `nhk_v3`.

`nhk_v3` is the development database: only non-destructive checks and UP migrations are permitted. Migration 003 DOWN is test-only. The current environment has MySQL 9.7.1 reachable outside the sandbox; sandbox localhost access requires narrowly scoped approval.

The current repository has the foundation tests, but the mandatory P4 transactional/concurrency/retry suite is still pending and skipped count must be zero before acceptance.
