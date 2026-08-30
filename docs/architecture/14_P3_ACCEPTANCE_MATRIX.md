# P3 Acceptance Matrix

| Invariant group | Test coverage | Result |
|---|---|---|
| UUID identity, stable key, idempotent create | `AuthorityCoreTest::test_brand_create_uses_uuid_and_stable_key_is_idempotent` | PASS |
| Payload boundary and collision | `AuthorityCoreTest::test_unknown_payload_and_collision_are_rejected`, `test_payload_fields_are_validated` | PASS |
| Lifecycle, revision, UUID reuse | `AuthorityCoreTest::test_lifecycle_preserves_uuid_and_revision_locking` | PASS |
| Generic graph resolver and retired endpoint | `AuthorityCoreTest::test_generic_resolver_validates_uuid_and_retired_entities_remain_graph_endpoints` | PASS |
| Graph Post→Brand survives lifecycle | `AuthorityGraphIntegrationTest::test_post_about_brand_survives_rename_retire_reactivate` | PASS |
| Storage/migration/health | `GraphMigrationIdempotencyTest` plus health read | PENDING |
