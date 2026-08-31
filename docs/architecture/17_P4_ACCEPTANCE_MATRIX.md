# P4 Acceptance Matrix

Status: `IN PROGRESS` — implemented gates are green; remaining rows require the full acceptance harness.

| ID | Acceptance | Test class / method | Level | Result |
|---|---|---|---|---|
| P4-001 | Migration 003 UP / idempotent / guarded DOWN | migration smoke | INTEGRATION | PASS |
| P4-002 | Proposal persistence, UUID, canonical command, fingerprint | `GovernanceCoreTest` | UNIT | PASS |
| P4-003 | Proposal idempotency | `GovernanceCoreTest` | UNIT | PASS |
| P4-004 | Proposal idempotency race | two-connection suite | INTEGRATION | PENDING |
| P4-005 | Approval durability and binding | `P4ControlledApplyIntegrationTest` | INTEGRATION | PASS |
| P4-006 | Reject, cancel, supersede lifecycle | governance lifecycle suite | INTEGRATION | PENDING |
| P4-007 | Direct/transitive dependencies and cycles | dependency lifecycle suite | INTEGRATION | PENDING |
| P4-008 | Eligibility reason matrix and revision drift | eligibility/retry suite | INTEGRATION | PENDING |
| P4-009 | ApplyAttempt history, rollback, failure, retry, re-apply | `P4ControlledApplyIntegrationTest` | INTEGRATION | PASS |
| P4-010 | True concurrent apply | two independent workers | INTEGRATION | PENDING |
| P4-011 | Graph durable audit | durable audit suite | INTEGRATION | PENDING |
| P4-012 | Authority durable audit | durable audit suite | INTEGRATION | PENDING |
| P4-013 | Governance durable audit | durable audit suite | INTEGRATION | PENDING |
| P4-014 | Append-only audit and privacy | durable audit contract suite | UNIT/INTEGRATION | PASS |
| P4-015 | Capability registration and authorization denial | authorization suite | INTEGRATION | PENDING |
| P4-016 | WordPress editorial bypass | WP regression suite | INTEGRATION | PENDING |
| P4-017 | Full P4 integration, mandatory skipped = 0 | `P4Integration` | INTEGRATION | PENDING |
| P4-018 | Main migration UP-only and health 3/3 | post-gate smoke | INTEGRATION | PENDING |
| P4-019 | P3 regression | existing P3 integration suite | INTEGRATION | PASS |
| P4-020 | Final diff/secret review | release checklist | RELEASE | PENDING |

P4 can become `ACCEPTED / CLOSED` only when every mandatory row is `PASS` and mandatory skipped count is zero.
