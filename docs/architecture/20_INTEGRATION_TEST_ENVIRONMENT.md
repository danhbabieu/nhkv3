# P4 Integration Test Environment

> **NON-NORMATIVE.** Đây là test-environment evidence. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Integration tests use the currently configured NHK V3 TEST RUNTIME identity:
environment `staging`, database `erourxcg_nhkv3`, site/home URL
`https://demo.1945.vn` and runtime identity `nhk-v3`. Destructive operations
must verify all identity fields and fail for production, unknown staging
runtimes, wrong sites and wrong databases.

`nhk_v3` is the development database: only non-destructive checks and UP
migrations are permitted. Legacy `nhk_v3_test` is not authorized by this
contract unless separately re-added to an explicit identity allowlist.

Run unit tests with `composer test`. Run DB integration with
`NHK_WP_TEST_PATH=public composer test`; the runtime guard must verify the full
identity before any mutation. Migration DOWN/reset remains test-only and is
permitted only after the same exact identity check.
