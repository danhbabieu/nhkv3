# Governance Failure and Retry

An apply operation must lock and reload the proposal inside a MySQL transaction. Authority mutation, `ApplyAttempt`, proposal state, and success audit are one atomic unit. A deterministic failure rolls back the authority mutation and proposal transition; a separate transaction records a bounded `FAILED` attempt and `ApplyFailed` audit while leaving the proposal `APPROVED`.

Retry re-evaluates eligibility and increments `attempt_no`. Revision drift or an unapplied dependency blocks retry fail-closed. Re-applying an `APPLIED` proposal returns its existing result and performs no second authority mutation.

This contract is not accepted until two real MySQL connections prove lock serialization, rollback, failure persistence, and retry history.
