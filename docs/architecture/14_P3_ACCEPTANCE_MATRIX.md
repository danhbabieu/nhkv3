# NHK V3 P3 Acceptance Matrix

| ID | Invariant | Test class / method | Layer | Status | Notes |
|---|---|---|---|---|---|
| P3-A | Migration 001 up, idempotent up, isolated down, up again | `GraphMigrationIdempotencyTest::test_up_is_idempotent_and_down_isolated` | INTEGRATION | PASS | Real `nhk_v3_test`; destructive path guarded |
| P3-B | Migration 002 up, idempotent up, down, up again | `GraphMigrationIdempotencyTest::test_authority_migration_up_down_up_is_idempotent` | INTEGRATION | PASS | Real `nhk_v3_test` |
| P3-C | UUID string ↔ `BINARY(16)` round-trip and canonical lookup | `GraphWpdbIntegrationTest::test_authority_uuid_stable_key_and_optimistic_lock_persist_in_db` | INTEGRATION | PASS | Real DB persistence via `UuidCodec` |
| P3-D | Stable-key same-command idempotency | `GraphWpdbIntegrationTest::test_authority_uuid_stable_key_and_optimistic_lock_persist_in_db` | INTEGRATION | PASS | Same canonical UUID returned |
| P3-E | Stable-key concurrent semantic collision | `StableKeyConcurrencyIntegrationTest` (two forked DB actors) | INTEGRATION | PASS | Same command yields one row/UUID; different commands yield one winner plus `StableKeyCollision` |
| P3-F | Optimistic locking for update, retire, reactivate | `GraphWpdbIntegrationTest::test_authority_lifecycle_filter_and_stale_revision_are_db_backed`, vertical slice test | INTEGRATION | PASS | Revision condition enforced by DB update; stale write rejected |
| P3-G | Lifecycle, no-op revision, retired read/filter, typed state errors | `GraphWpdbIntegrationTest::test_authority_lifecycle_filter_and_stale_revision_are_db_backed` | INTEGRATION | PASS | Active/retired filtering and no-op revision verified |
| P3-H | Cursor pagination, deterministic order, retired filtering | `GraphWpdbIntegrationTest::test_graph_cursor_pagination_and_retired_filter_are_db_backed` | INTEGRATION | PASS | Numeric cursor, limit, and retired visibility verified |
| P3-I | Generic authority endpoint resolver | `GraphWpdbIntegrationTest::test_authority_endpoint_resolver_and_graph_vertical_slice_preserve_identity` | INTEGRATION | PASS | Real UUID lookup and invalid-reference rejection |
| P3-J | Graph + Authority DB vertical slice | `GraphWpdbIntegrationTest::test_graph_authority_edge_survives_rename_retire_reactivate_and_paginates` | INTEGRATION | PASS | Post→about→brand edge UUID survives authority lifecycle |
| P3-K | Main DB smoke and health | `wp db check` plus `HealthCheck::read()` | INTEGRATION | PASS | `nhk_v3`: reachable, current/target 2, no migration required, both stores ready |

P3 STATUS: ACCEPTED

Evidence run: `NHK_WP_TEST_DB=nhk_v3_test NHK_WP_TEST_PATH=public composer test` → 37 tests, 100 assertions, 0 skipped.
