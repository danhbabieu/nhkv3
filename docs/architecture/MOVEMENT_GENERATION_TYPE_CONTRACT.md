# Movement generation/type contract

Status: canonical contract, implemented in the Authority registry on 2026-09-17.

## Owner and identity

Movement remains an Authority entity with the existing canonical UUID and stable-key identity. No parallel generation/type entity, endpoint type, or Graph predicate is introduced.

## Registered attributes

The Authority entity type `movement` is schema version 2. The following optional fields are registered in `CanonicalEntityTypeCatalog`:

| Field | Type | Meaning |
| --- | --- | --- |
| `generation` | string | Canonical movement generation/family label when supplied by authoritative evidence. |
| `movement_type` | string | Canonical movement type/category label when supplied by authoritative evidence. |

They are ordinary governed Authority attributes accepted only through the existing Authority create/update Proposal and Controlled Apply path. Empty/unknown values are not synthesized from fixtures, UI labels, or legacy data. Enumerated values require a later registry change and compatibility review.

## Governance and compatibility

The existing `GovernanceAutomationPolicyResolver` owner key is `movement`; operation and staging authorization remain scoped by registered Controlled Apply operations and capabilities. Existing movement payloads remain valid because both fields are optional. Consumers must read the runtime registry rather than hard-code an attribute whitelist.
