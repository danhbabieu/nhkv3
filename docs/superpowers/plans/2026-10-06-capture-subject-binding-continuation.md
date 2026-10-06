# NHK V3 Capture Subject Binding and Content Continuation

## Objective

Fix the generic Capture → canonical subject resolution → subject reconciliation → persisted binding → content continuation lifecycle without changing ontology, editorial ownership, Governance boundaries, or production/Test data. A Capture whose Authority work is already applied must be able to continue its owned content without replaying an old Authority approval packet when the new request contains no Authority delta. A genuine new Authority mutation must still use the normal approval lifecycle.

## Constraints and invariants

- WordPress native `wp_posts` remains the editorial source of truth; Capture owns the durable subject-resolution handoff for its Article continuation.
- Downstream content preparation, reconciliation, publication review, and publication context consume a canonical persisted Capture binding that was read back from the Capture repository. They must not promote a transient caller packet into authoritative state.
- Canonical subject identity, type, stable key, lifecycle/currentness, revision/CAS, provenance, idempotency, and fail-closed behavior remain enforced.
- Conflicting, stale, invalid, ambiguous, unconfirmed, or unreadable bindings remain blocked.
- No migration, fixture-specific branch, production/Test mutation, direct database writer, generic WordPress writer, or Governance bypass.

## Root-cause fixes

1. Introduce one Capture continuation classification policy that distinguishes content continuation and subject reconciliation from a new Authority delta. Historical `MIXED` purpose alone is not an Authority delta. Replayed identical Authority apply remains idempotent; changed Authority requests or relationship operations still route through the approval gate.
2. Make Capture subject-binding persistence a single owner boundary. Validate candidate identity against the registered canonical resolver, compare against any existing authoritative binding, persist with optimistic revision, read back through the repository, and verify the exact Article/Capture/subject tuple before returning it.
3. Route all continuation/reconciliation persistence through that owner boundary. Remove manual packet writes and transient caller-evidence fallback from publication context reconstruction.
4. Persist and read back a newly confirmed binding before downstream content continuation proceeds. Surface binding-unavailable/readback failures as explicit reconciliation blockers and preserve fail-closed publication behavior.

## Test-first work

Add failing unit coverage before implementation for:

- applied Authority plus valid confirmed subject binding persists and reads back;
- content continuation after Authority apply succeeds without an approval packet when no Authority delta is present;
- same applied Authority plan remains an idempotent replay;
- a changed Authority request or relationship operation still requires the normal approval packet/gate;
- repeated continuation and binding persistence are idempotent;
- stale Capture revision/CAS fails closed;
- ambiguous, invalid, retired, unconfirmed, and conflicting subjects fail closed;
- Capture persistence/readback failure blocks downstream content;
- multiple registered subject/entity types use the same generic path;
- publication/reconciliation context never trusts a transient subject packet when the persisted Capture binding is absent;
- existing Capture, Authority, Article, and MCP contract suites remain green.

Use Odo/Sonodo/Article 757 only as end-to-end regression fixtures after the generic tests pass.

## Implementation sequence

1. Re-read the current execution-state checkpoint and inspect the existing transport, Capture continuation, subject-binding recovery, coordinator, Article reconciliation, and publication-context contracts.
2. Add the red tests in the nearest existing unit suites, using in-memory repositories/fakes that can model CAS and readback failures without touching a runtime database.
3. Implement the continuation classifier and wire it into `McpTransport` while preserving old Authority plan/apply replay behavior.
4. Refactor `CaptureSubjectBindingRecovery` into the sole persistence/readback boundary, inject it into continuation/coordinator paths, and validate canonical resolver results.
5. Remove transient publication-context promotion; expose explicit blockers for missing or unreadable Capture bindings and map only safe persistence repair to the existing governed reconciliation action.
6. Run focused tests, PHP lint, full relevant Unit suites, `git diff --check`, and a secret review. Do not run semantic mutation or publication.
7. Update `docs/architecture/V3_EXECUTION_STATE.md` with the root cause, generic fix, evidence, and no-data-mutation status. Read the parity matrix before any parity statement.

## Expected changed areas

- Capture continuation classification policy and tests.
- `McpTransport` continuation routing and tests.
- `CaptureSubjectBindingRecovery` plus canonical resolver/readback tests.
- `EditorialCaptureContinuationService` and `EditorialCaptureCoordinator` wiring.
- Plugin publication/reconciliation context boundary and blocker mapping.
- Article remediation/reconciliation tests.
- Execution-state checkpoint.

## Verification and delivery

The final report must state `STATUS`, `ROOT_CAUSE`, `ARCHITECTURAL_FIX`, `FILES_CHANGED`, `TESTS_ADDED`, `TEST_RESULTS`, `BACKWARD_COMPATIBILITY`, `GOVERNANCE_INVARIANTS`, `REFERENCE_FIXTURE_RESULT`, `COMMIT_HASH`, and `NEXT`. It must explicitly state that no production publication or data mutation occurred. Commit only after all applicable checks pass.
