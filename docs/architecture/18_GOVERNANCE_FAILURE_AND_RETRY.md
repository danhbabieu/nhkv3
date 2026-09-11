# Governance Failure and Retry

> **NON-NORMATIVE.** Đây là implementation evidence. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

An apply operation must lock and reload the proposal inside a MySQL transaction. Authority mutation, `ApplyAttempt`, proposal state, and success audit are one atomic unit. A deterministic failure rolls back the authority mutation and proposal transition; a separate transaction records a bounded `FAILED` attempt and `ApplyFailed` audit while leaving the proposal `APPROVED`.

Retry re-evaluates eligibility and increments `attempt_no`. Revision drift or an unapplied dependency blocks retry fail-closed. Re-applying an `APPLIED` proposal returns its existing result and performs no second authority mutation.

The proposal row is the serialization point. The semantic transaction locks and reloads it with `SELECT ... FOR UPDATE`; the failed-attempt write occurs only after rollback in a separate transaction and locks the same row before allocating the next attempt number. Acceptance still requires two real MySQL connections to prove lock serialization and idempotent concurrent apply.

Conversational Authority multi-candidate apply uses `ControlledApplyService`
`applyMany` only when Authority, Graph, Proposal and audit stores share the
same `TransactionManager`/wpdb boundary. Proposal lifecycle work is staged
first; selected canonical mutations then run inside that shared transaction.
A failure rolls back the complete canonical batch, while the post-rollback
failure-attempt transaction preserves durable diagnostics. If a runtime cannot
prove the shared boundary, plan apply must fail before semantic mutation rather
than claim atomicity or guess compensation.

For semantic merge, source and target revisions are independent bindings. A
proposal whose subject binding does not carry the canonical source UUID is
invalid and must be rejected before apply; it must not be diagnosed as merge
unavailability or allowed to mutate the source or target.
