# P4 Acceptance Matrix

Status: `BLOCKED` pending mandatory MySQL integration work.

| Invariant | Description | Test class/method | Level | Result | Notes |
|---|---|---|---|---|---|
| P4-MIG-001 | Migration 003 creates five governance tables | Pending dedicated integration test | INTEGRATION | NOT RUN | UP/DOWN/UP must run only on `nhk_v3_test` |
| P4-PROP-001 | UUIDv7, persistence, idempotency and canonical fingerprint | `GovernanceCoreTest` | UNIT | PASS/PARTIAL | Existing foundation only; DB race test missing |
| P4-DEP-001 | Closure and cycle detection | `GovernanceCoreTest::test_dependency_closure_rejects_direct_and_transitive_cycles` | UNIT | PASS/PARTIAL | Two/three-node DB integration missing |
| P4-APPLY-001 | Transaction, row lock, rollback, failure and retry | Pending dedicated integration suite | INTEGRATION | NOT RUN | Acceptance blocker; no PASS by unit inference |
| P4-AUD-001 | Durable append-only audit for graph, authority and governance | Pending dedicated integration suite | INTEGRATION | NOT RUN | Current sink covers governance foundation only |
| P4-AUTH-001 | Capability registration and denial boundary | Pending dedicated integration suite | INTEGRATION | NOT RUN | Registration foundation added |
| P4-EDITORIAL-001 | Normal WordPress post publication bypasses governance | Pending regression test | INTEGRATION | NOT RUN | Must remain explicit |
| P4-HEALTH-001 | Actual 3/3 readiness after main UP-only migration | Pending post-gate verification | INTEGRATION | BLOCKED | Main DB UP intentionally not run before gate |
