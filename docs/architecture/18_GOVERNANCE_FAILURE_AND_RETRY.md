# Governance Failure, Retry and Completion

> **NON-NORMATIVE IMPLEMENTATION EVIDENCE.** Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

## Apply failure and retry

A Controlled Apply operation locks and reloads the proposal inside the semantic
transaction. The owning mutation, apply attempt, proposal transition and success
audit are handled according to the domain transaction contract. A deterministic
failure rolls back the semantic mutation and successful transition; a bounded
`FAILED` attempt/audit is recorded after rollback while the proposal remains
retryable when policy permits.

Retry always re-evaluates eligibility. Revision drift, changed dependency
closure, invalid endpoint/predicate or unavailable canonical dependency blocks
retry fail-closed. Re-applying an already APPLIED proposal returns/reuses its
durable result and performs no second semantic mutation.

When a rate limit, process interruption, transport failure or runtime outage
happens after a proposal already exists, the caller must retain and reuse that
proposal ID/idempotency binding when the durable intent is unchanged. Do not
create a replacement proposal merely to retry the same work. Same key with
changed intent is an idempotency conflict.

## Completion requires canonical owner read-back

Proposal/apply state is control-plane evidence only. A mutation is not
`COMPLETED` until the canonical owner returns the expected record/edge and the
result matches the intended identity, state/revision and dependency binding.

Owner read-back examples:

- Authority → entity/resolver;
- Knowledge → Knowledge owner;
- Source → Source owner;
- Evidence → Evidence/claim-source chain;
- Graph → edge/outgoing/incoming/neighborhood as appropriate;
- Video → Video owner;
- Media → Media/Asset/Usage owner;
- Article editorial state → native WordPress/read-render boundary.

A second identical execution must create no duplicate canonical entity,
Knowledge, Source, Evidence, Video, Media, active relation or publication side
effect.

## Relation identity

For `relation_create`, proposal identity must preserve the canonical source
endpoint, registered predicate and canonical target endpoint. The current packet
uses `source_type/source_uuid`, `predicate`, `target_type/target_uuid`.

The historical relation hydration defect that could substitute an entity type
for the real source UUID is resolved in the current proposal repository. It must
not be carried forward as a global Graph retry/apply blocker.

For semantic merge, source and target revisions remain independent bindings.
Merge/rekey may still be blocked for identity-specific evidence/revision reasons;
that is distinct from current ordinary relation source binding.

## Deferred retry

If the current operation cannot safely continue because of research,
Evidence/registry/relation/lexical/frontend/runtime gaps, preserve a deferred
entry rather than creating a partial orphan. Useful states include
`PENDING_RESEARCH`, `EVIDENCE_GAP`, `REGISTRY_GAP`, `RELATION_GAP`,
`LEXICAL_GAP`, `FRONTEND_GAP`, `RUNTIME_BLOCKED`, `NEEDS_REVIEW`.

A deferred entry should retain source/provenance, proposed subject/type/relation,
evidence, resolved canonical IDs, existing proposal ID, blocker and deterministic
rerun instruction. Final outcomes are `COMPLETED`, `DEFERRED_WITH_REASON` or
`BLOCKED_WITH_OWNER_ACTION`.
