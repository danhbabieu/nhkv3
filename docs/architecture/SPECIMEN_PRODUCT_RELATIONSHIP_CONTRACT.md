# Specimen / Product Relationship Contract

Status: ACTIVE local implementation contract — 2026-10-09

This contract extends the existing Authority, Graph, GraphRelationContext and
Governance owners. It does not create a new owner, relation table, payload
shortcut or runtime authorization.

## Canonical relations

| Predicate | Source → target | Active cardinality | Evidence |
|---|---|---:|---|
| `specimen_of` | `specimen` → `model` or `variant` | one active target across both target types | required |
| `lists_specimen` | `product` → `specimen` | one active target per Product; many historical Products per Specimen | required |

`specimen_of` is the physical identity edge. A Variant target derives Model and
Brand only through the existing `variant_of` → `model_of` chain. `model_uuid` on
Specimen remains compatibility evidence and is never Graph truth. No direct
Specimen → Brand edge is created.

`lists_specimen` connects a commercial listing to one concrete object. Sold,
expired or archived Products retain their historical edge and never delete the
Specimen. Multi-object listings and ambiguous candidate matches remain blocked.
An active listing conflict is a typed review/block outcome, not an automatic
retirement or reassignment.

## Governance and context

New/replacement relations require exact endpoint UUIDs, endpoint revisions,
scope, registered provenance, canonical Evidence references, approval binding
and idempotency. Apply uses the existing Proposal → approval → eligibility →
Controlled Apply → GraphRelationContext → canonical read-back lifecycle.
Retire/reclassify/reassign operations preserve the existing edge/context rows;
they never hard-delete semantic history.

The local code includes `SpecimenProductRelationPolicy` and a forward-only
registry-data migration (`SpecimenProductRelationMigration026`). The migration
is intentionally prepared but not wired into automatic runtime migration in
this checkpoint.

## Capture and public read

Resolved Capture continuation may use a server-owned
`ResolvedSubjectReconciliationPacket` bound to Capture ID, request fingerprint,
Capture revision, idempotency key, exact resolved subject, Evidence references
and expiry. A stale or changed packet fails closed. The supplied ÔĐô 36/8 case
is not mutated locally and remains runtime acceptance work.

`SpecimenCatalogueQuery` reuses Authority, Graph and Public Identity/route
boundaries for bounded deterministic `/hien-vat/` and `/san-pham/` reads. Public
items omit UUIDs, stable keys, revisions and internal diagnostics. Knowledge,
Dictionary and Media/Video are read through their existing owner callbacks and
retain their own scope/visibility gates.
