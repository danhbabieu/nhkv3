# P2 Acceptance Matrix

Đối chiếu theo 37 invariant P2, trước khi bắt đầu P3.

| # | Invariant | Test class/method | Kết quả |
|---:|---|---|---|
| 1 | UUIDv7 generation | `GraphCoreContractTest::test_uuid_v7_and_legacy_uuid_round_trip` | PASS |
| 2 | UUID codec v7 round-trip | `GraphCoreContractTest::test_uuid_v7_and_legacy_uuid_round_trip` | PASS |
| 3 | UUIDv4 legacy round-trip | `GraphCoreContractTest::test_uuid_v7_and_legacy_uuid_round_trip` | PASS |
| 4 | NodeReference normalization | `GraphCoreContractTest::test_node_reference_and_resolution_are_idempotent` | PASS |
| 5 | Unsupported endpoint rejected | `GraphCoreContractTest::test_unknown_endpoint_and_predicate_are_rejected` | PASS |
| 6 | Missing endpoint rejected | `P2AcceptanceGapTest::test_missing_endpoint_and_unknown_predicate_are_rejected` | PASS |
| 7 | wp_post resolver valid | `GraphWpdbIntegrationTest::test_migration_tables_and_wp_post_graph_round_trip` | PASS |
| 8 | Node resolution idempotent | `GraphCoreContractTest::test_node_reference_and_resolution_are_idempotent` | PASS |
| 9 | Concurrent duplicate-node safety | `P2AcceptanceGapTest::test_uuid_lookup_cursor_and_node_in_use_guard` | PASS (unique-key strategy) |
| 10 | Known predicate accepted | `GraphCoreContractTest::test_create_is_idempotent_and_audited` | PASS |
| 11 | Unknown predicate rejected | `P2AcceptanceGapTest::test_missing_endpoint_and_unknown_predicate_are_rejected` | PASS |
| 12 | Source type validation | `P2AcceptanceGapTest::test_source_and_target_type_validation_are_separate` | PASS |
| 13 | Target type validation | `GraphCoreContractTest::test_outbound_and_inbound_one_cardinality_fail` | PASS |
| 14 | Edge creation | `GraphCoreContractTest::test_create_is_idempotent_and_audited` | PASS |
| 15 | Duplicate create idempotent | `GraphCoreContractTest::test_create_is_idempotent_and_audited` | PASS |
| 16 | Outgoing query | `GraphCoreContractTest::test_forward_reverse_retire_reactivate_and_no_resurrection` | PASS |
| 17 | Incoming query | `GraphCoreContractTest::test_forward_reverse_retire_reactivate_and_no_resurrection` | PASS |
| 18 | Edge UUID lookup | `P2AcceptanceGapTest::test_uuid_lookup_cursor_and_node_in_use_guard` | PASS |
| 19 | Retire | `GraphCoreContractTest::test_forward_reverse_retire_reactivate_and_no_resurrection` | PASS |
| 20 | Retired excluded by default | `GraphCoreContractTest::test_forward_reverse_retire_reactivate_and_no_resurrection` | PASS |
| 21 | Include retired explicit | `GraphCoreContractTest::test_forward_reverse_retire_reactivate_and_no_resurrection` | PASS |
| 22 | Reactivate | `GraphCoreContractTest::test_audit_sink_receives_all_mutations` | PASS |
| 23 | Create does not resurrect retired | `GraphCoreContractTest::test_forward_reverse_retire_reactivate_and_no_resurrection` | PASS |
| 24 | Expected revision success | `GraphCoreContractTest::test_audit_sink_receives_all_mutations` | PASS |
| 25 | Stale revision conflict | `GraphCoreContractTest::test_reactivate_requires_revision_and_explicit_operation` | PASS |
| 26 | Outbound cardinality | `GraphCoreContractTest::test_outbound_and_inbound_one_cardinality_fail` | PASS |
| 27 | Inbound cardinality | `P2AcceptanceGapTest::test_inbound_one_cardinality_is_enforced` | PASS |
| 28 | Self relation | `GraphCoreContractTest::test_self_relation_and_cardinality_fail` | PASS |
| 29 | Cursor pagination | `P2AcceptanceGapTest::test_uuid_lookup_cursor_and_node_in_use_guard` | PASS |
| 30 | Node in-use protection | `P2AcceptanceGapTest::test_uuid_lookup_cursor_and_node_in_use_guard` | PASS |
| 31 | Migration 001 up | `GraphWpdbIntegrationTest::test_migration_tables_and_wp_post_graph_round_trip` | PASS |
| 32 | Migration 001 down isolated | `GraphWpdbIntegrationTest::test_migration_tables_and_wp_post_graph_round_trip` | PASS (isolated fixture) |
| 33 | Migration idempotency | activation twice + table assertions | PASS |
| 34 | Predicate mapping idempotency | activation twice, 2 predicate rows | PASS |
| 35 | Audit create | `GraphCoreContractTest::test_audit_sink_receives_all_mutations` | PASS |
| 36 | Audit retire | `GraphCoreContractTest::test_audit_sink_receives_all_mutations` | PASS |
| 37 | Audit reactivate | `GraphCoreContractTest::test_audit_sink_receives_all_mutations` | PASS |

Không đổi P2 contract. Gap tests được bổ sung trước Authority.
