# P4 Integration Test Environment

Integration tests use the local Homebrew MySQL server on `127.0.0.1:3306` and the database `nhk_v3_test`. Destructive operations are guarded by an exact `SELECT DATABASE()` check and must fail for `nhk_v3`.

`nhk_v3` is the development database: only non-destructive checks and UP migrations are permitted. Migration 003 DOWN is test-only. The current environment has MySQL 9.7.1 reachable outside the sandbox; sandbox localhost access requires narrowly scoped approval.

Run unit tests with `composer test`. Run DB integration with `NHK_WP_TEST_DB=nhk_v3_test NHK_WP_TEST_PATH=public composer test`; this must report zero skips for mandatory acceptance. Migration DOWN/reset is permitted only on `nhk_v3_test`; `nhk_v3` permits health, schema inspection, smoke checks, and UP-only migration.
