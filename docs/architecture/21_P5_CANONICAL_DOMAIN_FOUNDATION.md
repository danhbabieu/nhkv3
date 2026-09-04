# P5 Canonical Domain Foundation

> **NON-NORMATIVE.** This is implementation evidence. If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

## Scope

P5 expands the registry-backed Authority boundary over the target canonical
types: `brand`, `model`, `variant`, `movement`, `music`, `component`,
`classification`, `specimen` and `product`.

All types use the same generic Authority storage and service contract:

- immutable canonical UUID (UUIDv7 for new records; legacy UUIDs round-trip);
- immutable scoped stable key `(entity_type, stable_key)`;
- deterministic, allow-listed payload fields with field-type and declared
  format validation; relation UUIDs and Product HTTP(S) URLs fail closed;
- optimistic revision for rename, update and lifecycle changes;
- active/retired lifecycle with typed state errors;
- registry-backed Graph endpoint registration when `graphEnabled` is true.

## Product/specimen boundary

`specimen` identifies a concrete physical object. `product` identifies a
commercial listing or offer. Product is never treated as the physical object's
identity.

The current V3 runtime has **no approved Product–Specimen persistence relation**.
No Product payload field, broad `about` edge, taxonomy, post meta or other
shortcut may be treated as canonical Product→Specimen ownership/identity
binding. A future Product–Specimen relation requires an explicit Constitution/
contract decision covering endpoints, direction, cardinality, provenance,
Governance and read-back before implementation or data population.

Any historical payload such as `specimen_uuid` is compatibility/evidence data
only unless and until a separately approved migration/repair task interprets it;
it is not authorization for a current semantic writer.

## Acceptance evidence

`P5CanonicalDomainIntegrationTest` verifies all nine types through the existing
Authority table, unique canonical UUIDs, stable-key lookup, Graph resolver
registration, retire/reactivate lifecycle and payload update behavior. The unit
suite verifies catalog completeness, conflicting registry definitions, payload
field types and format validation, and optimistic update locking.

## Migration decision

No P5 schema migration is required. `nhk_entities` already stores the generic
Authority identity, stable key, schema version, payload, state and revision.
Adding a type is a registry/schema-contract change, not a new table. A future
schema-version change must be additive and receive its own migration gate.
