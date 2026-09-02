# Brand Backbone Structural Design Specification

> **NON-NORMATIVE.** This is staged design evidence. If it conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

Status: approved staged contract; implementation not started in this checkpoint.

## Purpose and invariants

Make Brand/Model/Variant lineage expressible through the single typed Graph,
without duplicating reverse edges or inferring ownership for reusable semantic
entities. Preserve canonical UUID/stable-key identity, active/retired edge
state, revision and audit semantics.

## Contract additions

After contract approval, add only these registry definitions:

```text
model_of:   source=model,   target=brand,
            outbound=ONE, inbound=MANY, self=false, active=true
variant_of: source=variant, target=model,
            outbound=ONE, inbound=MANY, self=false, active=true
```

Use existing `PredicateDefinition` validation and `GraphService`/repository
transactions. No free-form predicate, special relation table, JSON rule,
reverse predicate, or direct persistence shortcut is allowed.

## Read and write behavior

Governed relation creation validates endpoint type, existence, active predicate,
self-relation and cardinality. A second active parent fails with the existing
typed cardinality error; the previous parent is not silently retired.

Structural reads resolve Model→active `model_of` Brand, Variant→active
`variant_of` Model, and Variant→Brand only by the two-edge traversal. Brand→Model
and Model→Variant are incoming Graph queries. Missing, retired, ambiguous or
unavailable parents produce structural incompleteness; no parent is fabricated.

## Existing payload fields and route transition

`brand_uuid` and `model_uuid` currently exist in the Authority catalog and are
consumed by `PublicRouteResolver`. They must not remain the canonical structural
store after this contract is implemented. A later compatibility decision must
make Graph-backed resolution authoritative and remove duplicate-write paths.

Until then, payload-driven routing is labeled `CONSTITUTION_CONFLICT` whenever
it claims to represent canonical structural relations. This checkpoint does not
rewrite those fields or create replacement edges.

## Orphan/readiness policy

An active Model without exactly one active `model_of` edge and an active Variant
without exactly one active `variant_of` edge are structurally incomplete. The
system must not guess or auto-attach a parent. Public routes and projections
must fail closed or use existing eligibility state. If readiness cannot carry a
structural-incomplete reason, record `CODE_GAP`; do not invent a database field.

## Explicit non-goals

No physical edge repair, bulk population, identity merge, direct Brand shortcut,
legacy article-body migration/import/parsing, unregistered field/type/predicate,
V2/live mutation, production cutover, or data-operation approval is included.

Unknown predicates, invalid endpoints, missing parents, cardinality conflicts,
revision conflicts and ambiguous identity resolution must be explicit failures
or bounded unavailable states. Later data operations require Governance,
provenance/evidence and idempotency.
