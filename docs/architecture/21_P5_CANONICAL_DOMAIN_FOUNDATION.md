# P5 Canonical Domain Foundation

## Scope

P5 expands the registry-backed Authority boundary over the target canonical
types: `brand`, `model`, `variant`, `movement`, `music`, `component`,
`classification`, `specimen` and `product`.

All types use the same generic Authority storage and service contract:

- immutable canonical UUID (UUIDv7 for new records; legacy UUIDs round-trip);
- immutable scoped stable key `(entity_type, stable_key)`;
- deterministic, allow-listed payload fields with field-type validation;
- optimistic revision for rename, update and lifecycle changes;
- active/retired lifecycle with typed state errors;
- registry-backed Graph endpoint registration when `graphEnabled` is true.

## Product/specimen boundary

`specimen` identifies a concrete physical object. `product` identifies a
commercial listing or offer and may refer to a specimen through a semantic Graph
relation or a typed payload reference. Product is never treated as the physical
object's identity.

## Acceptance evidence

`P5CanonicalDomainIntegrationTest` verifies all nine types through the existing
Authority table, unique canonical UUIDs, stable-key lookup, Graph resolver
registration, retire/reactivate lifecycle and payload update behavior. The unit
suite verifies catalog completeness, conflicting registry definitions, payload
field types and optimistic update locking.

## Migration decision

No P5 schema migration is required. `nhk_entities` already stores the generic
Authority identity, stable key, schema version, payload, state and revision.
Adding a type is a registry/schema-contract change, not a new table. A future
schema-version change must be additive and receive its own migration gate.
