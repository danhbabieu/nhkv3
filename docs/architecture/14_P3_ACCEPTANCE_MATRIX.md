# NHK V3 P3 Acceptance Matrix

| ID | Invariant | Test class / method | Layer | Status | Notes |
|---|---|---|---|---|---|
| P3-A | Migration 001 up, idempotent up, isolated down, up again | `GraphMigrationIdempotencyTest::test_up_is_idempotent_and_down_isolated` | INTEGRATION | BLOCKED | Requires `nhk_v3_test`; destructive path guarded |
| P3-B | Migration 002 up, idempotent up, down, up again | `GraphMigrationIdempotencyTest::test_authority_migration_up_down_up_is_idempotent` | INTEGRATION | BLOCKED | Requires `nhk_v3_test` |
| P3-C | UUID string ↔ `BINARY(16)` round-trip and canonical lookup | `GraphWpdbIntegrationTest::test_authority_uuid_stable_key_and_optimistic_lock_persist_in_db` | INTEGRATION | BLOCKED | Uses shared `UuidCodec`; DB unavailable in acceptance run |
| P3-D | Stable-key same-command idempotency | `GraphWpdbIntegrationTest::test_authority_uuid_stable_key_and_optimistic_lock_persist_in_db` | INTEGRATION | BLOCKED | Required DB race coverage remains to be exercised |
| P3-E | Stable-key concurrent semantic collision | P3 DB concurrency integration test | INTEGRATION | PENDING | Must use two DB connections or parallel actors |
| P3-F | Optimistic locking for update, retire, reactivate | P3 authority lifecycle integration test | INTEGRATION | PENDING | Must assert affected-row behavior and stale revision conflicts |
| P3-G | Lifecycle, no-op revision, retired read/filter, typed state errors | P3 authority lifecycle integration test | INTEGRATION | PENDING | Unit typed-error coverage exists; DB behavior still required |
| P3-H | Cursor pagination, deterministic order, retired filtering | P3 pagination integration test | INTEGRATION | PENDING | Must verify numeric internal-id cursor and limit clamp |
| P3-I | Generic authority endpoint resolver | P3 resolver integration test | INTEGRATION | PENDING | UUID validation, missing, retired, unsupported type |
| P3-J | Graph + Authority DB vertical slice | P3 vertical-slice integration test | INTEGRATION | PENDING | Edge and node identity must survive lifecycle |
| P3-K | Main DB smoke and health | `wp db check` plus health read | INTEGRATION | BLOCKED | Must run on `nhk_v3` without destructive operations |

P3 STATUS: BLOCKED

P3 cannot be marked `ACCEPTED` until every DB invariant above has real integration evidence, zero mandatory skips, and the main database smoke check passes.
