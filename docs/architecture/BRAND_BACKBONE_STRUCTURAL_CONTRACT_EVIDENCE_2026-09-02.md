# Brand Backbone Structural Contract — Evidence Package

Date: 2026-09-02
Status: approved staged-contract design; read-only checkpoint
Mutation boundary: no semantic records, Graph edges, redirects, or legacy
article bodies were changed.

## Executive decision

The canonical structural backbone is:

```text
Brand ← model_of ← Model ← variant_of ← Variant
```

Persisted Graph facts are written from child to parent:

```text
Model --model_of--> Brand
Variant --variant_of--> Model
```

Reverse navigation is a query-service concern. `Variant → Brand` is derived by
traversing `variant_of` then `model_of`; it is never persisted as a shortcut.

## Evidence and gap classification

| Finding | Evidence | Classification | Checkpoint treatment |
|---|---|---|---|
| Graph registry seeds only `about` and `depicts` | `src/Domain/Graph/PredicateRegistry.php` registers exactly two definitions | `REGISTRY_GAP` | Do not register or persist new predicates here |
| Graph centralizes endpoint, predicate, self-edge and existence checks | `src/Application/Graph/GraphService.php`, `EndpointTypeRegistry`, `PredicateDefinition` | Existing foundation | Reuse the single Graph boundary later |
| Model and Variant are registered Authority types | `CanonicalEntityTypeCatalog.php`; P5 covers nine types | No type gap | No new entity types or tables |
| Model payload requires `brand_uuid` | `CanonicalEntityTypeCatalog.php`; route tests create Models with it | `CODE_GAP`; `CONSTITUTION_CONFLICT` if treated as canonical relationship truth | Mark transitional behavior; later reads use Graph |
| Variant payload requires `model_uuid` | `CanonicalEntityTypeCatalog.php`; route tests create Variants with it | `CODE_GAP`; `CONSTITUTION_CONFLICT` if treated as canonical relationship truth | Mark transitional behavior; later reads use Graph |
| Public routes resolve parents from payload fields | `Application/Entity/PublicRouteResolver.php` reads `brand_uuid`/`model_uuid` | `CONSTITUTION_CONFLICT` with the approved Graph-owned contract | Do not silently preserve as final architecture |
| Existing physical Graph lacks required backbone facts | Approved request and current execution state | `DATA_COMPATIBILITY_GAP` | Audit only; no edge repair here |
| Shared semantic entities have no required Brand parent | Constitution §04 and approved cardinality law | Contract rule | Do not add Brand ownership for Movement, Music, Component or Classification |

The three gaps remain separate. A registry change does not prove code support;
code support does not prove existing data has valid parents.

## Current registry comparison

| Concern | Current runtime | Approved target | Decision |
|---|---|---|---|
| Predicate vocabulary | `about`, `depicts` | Add `model_of`, `variant_of` only through future registry contract | `REGISTRY_GAP` |
| Model parent | Payload `brand_uuid`; no typed structural predicate | Exactly one active `Model → Brand` `model_of` edge | Graph becomes canonical |
| Variant parent | Payload `model_uuid`; no typed structural predicate | Exactly one active `Variant → Model` `variant_of` edge | Graph becomes canonical |
| Variant→Brand | Not a registered predicate | Derived query path only | Never persist |
| Brand→Model / Model→Variant | Not approved storage direction | Reverse query traversal only | Never persist as duplicate edges |
| Movement/Music/Component/Classification | Registered Authority types, no required Brand ancestry | Optional registered semantic relations | Keep reusable/shared |
| Orphan visibility | No dedicated structural readiness contract found | Incomplete and not public as structurally complete | Use eligibility machinery; record `CODE_GAP` if needed |

## Relation contract matrix

| Predicate | Source | Target | Outbound cardinality | Inbound cardinality | Direct/derived |
|---|---|---|---|---|---|
| `model_of` | `model` | `brand` | `ONE` active canonical parent per Model | `MANY` Models per Brand | Direct |
| `variant_of` | `variant` | `model` | `ONE` active canonical parent per Variant | `MANY` Variants per Model | Direct |
| Brand context for Variant | — | — | — | — | Derived: `variant → variant_of → model → model_of → brand` |

Invalid endpoint combinations fail closed, including `movement --model_of →
brand`, `variant --model_of → brand`, and `model --variant_of → variant`.

## Semantic boundary matrix

| Domain | Required Brand parent? | Allowed interpretation |
|---|---:|---|
| Model | Yes, through `model_of` | Product-line structure |
| Variant | Yes, through `variant_of` then `model_of` | Product-line structure |
| Movement | No | Reusable technical identity; approved semantic predicates only |
| Music | No | Reusable work/program; never infer Brand ownership |
| Component | No | Reusable component/configuration; narrowest supported level |
| Classification | No | Shared label/configuration, not a Brand substitute |
| Media / Video / Knowledge | No | Registered semantic predicates plus public/readiness policy |
| Specimen / Product | No automatic ancestry | Keep physical-object versus offer identity distinct |

## Non-actions recorded

- No `model_of` or `variant_of` registry entry, predicate row, or Graph edge.
- No Authority payload was rewritten or deleted.
- No route, redirect, migration, import, or article-body operation was run.
- No Brand was guessed for any record.

This package is evidence and contract design, not a data-repair approval.
