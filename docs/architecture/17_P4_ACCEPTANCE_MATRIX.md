# P4 Acceptance Matrix

> **NON-NORMATIVE.** Đây là acceptance evidence, không phải luật kiến trúc.
> Nếu mâu thuẫn với `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp
> kiểm soát.

Status: `ACCEPTED / CLOSED` — all P4 test gates pass on `nhk_v3_test`, and
Migration003 UP-only plus health 3/3 pass on `nhk_v3`.

| ID | Acceptance | Test class / method | Level | Result |
|---|---|---|---|---|
| P4-001 | Migration 003 UP / idempotent / guarded DOWN | migration smoke | INTEGRATION | PASS |
| P4-002 | Proposal persistence, UUID, canonical command, fingerprint | `GovernanceCoreTest` | UNIT | PASS |
| P4-003 | Proposal idempotency | `GovernanceCoreTest` | UNIT | PASS |
| P4-004 | Proposal idempotency race | `P4ControlledApplyIntegrationTest::test_idempotency_race_has_one_row_and_conflicting_command_is_rejected` | INTEGRATION | PASS |
| P4-005 | Approval durability and binding | `P4ControlledApplyIntegrationTest` | INTEGRATION | PASS |
| P4-006 | Reject, cancel, supersede lifecycle | `P4GovernanceAcceptanceIntegrationTest::test_lifecycle_is_transactional_and_supersede_keeps_canonical_replacement_id` | INTEGRATION | PASS |
| P4-007 | Direct/transitive dependencies and cycles | `P4GovernanceAcceptanceIntegrationTest::test_dependencies_are_persisted_idempotently_and_transitive_cycles_are_rejected` | INTEGRATION | PASS |
| P4-008 | Eligibility reason matrix and revision drift | `P4GovernanceAcceptanceIntegrationTest::test_eligibility_reports_dependency_and_revision_reason_codes` | INTEGRATION | PASS |
| P4-009 | ApplyAttempt history, rollback, failure, retry, re-apply | `P4ControlledApplyIntegrationTest` | INTEGRATION | PASS |
| P4-010 | True concurrent apply | `P4ControlledApplyIntegrationTest::test_true_concurrent_apply_serializes_on_proposal_row_and_returns_same_result` | INTEGRATION | PASS |
| P4-011 | Graph durable audit | `P4MigrationAndAuditIntegrationTest::test_graph_audit_create_retire_reactivate_is_durable` | INTEGRATION | PASS |
| P4-012 | Authority durable audit | `P4MigrationAndAuditIntegrationTest::test_authority_audit_is_durable_and_uses_shared_event_store` | INTEGRATION | PASS |
| P4-013 | Governance durable audit | `P4ControlledApplyIntegrationTest::test_failure_rolls_back_authority_persists_failed_attempt_and_retry_is_successful`, `P4GovernanceAcceptanceIntegrationTest::test_governance_audit_is_durable_and_redacts_sensitive_context` | INTEGRATION | PASS |
| P4-014 | Append-only audit and privacy | durable audit contract suite | UNIT/INTEGRATION | PASS |
| P4-015 | Capability registration and authorization denial | `P4GovernanceAcceptanceIntegrationTest::test_capability_registration_denial_and_wordpress_editorial_bypass` | INTEGRATION | PASS |
| P4-016 | WordPress editorial bypass | `P4GovernanceAcceptanceIntegrationTest::test_wp_post_editorial_write_does_not_require_governance` | INTEGRATION | PASS |
| P4-017 | Full P4 integration, mandatory skipped = 0 | `NHK_WP_TEST_DB=nhk_v3_test NHK_WP_TEST_PATH=public composer test` | INTEGRATION | PASS — 56 tests, 167 assertions, 0 skipped |
| P4-018 | Main migration UP-only and health 3/3 | post-gate smoke | INTEGRATION | PASS — current 3, target 3, required false, graph/authority/governance ready |
| P4-019 | P3 regression | existing P3 integration suite | INTEGRATION | PASS |
| P4-020 | Final diff/secret review | release checklist | RELEASE | PASS — completed before checkpoint |

P4 can become `ACCEPTED / CLOSED` only when every mandatory row is `PASS` and mandatory skipped count is zero.
