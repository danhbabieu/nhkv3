# NHK V3 Authority Core Contract

> **NON-NORMATIVE IMPLEMENTATION CONTRACT.** Runtime registry and the current
> Constitution control. Historical P3 statements that only Brand existed or
> that no Authority mutation surface existed are superseded by the current
> executable boundary.

## Current identity and mutation contract

Authority has nine registered canonical types: `brand`, `model`, `variant`,
`movement`, `music`, `component`, `classification`, `specimen`, `product`.
Each entity uses an immutable canonical UUID and a scoped stable key
`(entity_type, stable_key)`. Canonical name, payload, lifecycle state and schema
version are mutable only through the registered application/Governance boundary;
revisions use optimistic locking.

Payload JSON is deterministic and unknown fields fail closed. Graph references
resolve Authority UUIDs through the registry-backed endpoint resolver. Rename,
rekey, update, retire and reactivate preserve the canonical UUID. Rekey requires
exact old key, expected revision and collision checking; it changes only the
scoped stable key and increments revision once.

## Reconcile-before-create invariant

Authority creation begins with current canonical research, not with a create
command. Resolve by canonical UUID, stable key, then exact registered name/alias;
classify the intended identity as `EXACT_EXISTING`, `MERGE_CANDIDATE`,
`RELATED_BUT_DISTINCT`, `NO_EXISTING_CANONICAL_RECORD` or `UNCERTAIN`.

Only `NO_EXISTING_CANONICAL_RECORD` may proceed to a new canonical create.
`EXACT_EXISTING` reuses the record. Merge candidates use the governed merge/
rekey/update contract. Related-but-distinct records retain separate identities.
Uncertain cases go to research/deferred state. Fuzzy/name/keyword similarity is
not identity proof.

## Governed lifecycle and read-back

Current semantic Authority writes follow:

`proposal create/ingest → submit → review → approval with content/dependency
binding fingerprints → eligibility → Controlled Apply → canonical Authority
read-back → idempotency verification`.

Proposal identity is not canonical entity identity. Create/ingest may have no
canonical UUID before Apply. `COMPLETED` requires the canonical Authority owner
to return the intended new/updated record; a repeated identical intent must not
create another node.

## Runtime create probe — 2026-09-07

Proposal `01a07c4e-14b2-734e-8264-3f04b37e5fe4` for
`operation=create, entity_type=classification` passed proposal creation,
submission, review/fingerprint binding, approval and eligibility with
`ready=true`, no reasons. It was intentionally not applied to avoid a junk
Classification.

Therefore the Authority-create boundary is verified through eligibility, not
through actual new-node persistence. Do not claim the still-unrun steps
Controlled Apply → generated canonical UUID → entity/read resolver read-back →
immediate Graph use until a real node is needed and that chain is observed.

A create Proposal using the entity type as its pre-create subject marker is not
the historical relation source-binding bug. `relation_create` has an existing
canonical source and must carry its real UUID.
