# NHK V3 Related Semantic Projection Contract

> **NON-NORMATIVE CURRENT ARCHITECTURE CONTRACT.** If this document conflicts
> with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.

Status: approved bounded Graph read/projection contract, reconciled to the
2026-09-07 executable runtime. This document authorizes no new type, endpoint,
predicate, field, operation, Graph edge, taxonomy/postmeta fallback or data
mutation.

## 1. Purpose and ownership

Every registered canonical Graph endpoint may be a source for bounded semantic
navigation. Projection remains read-only:

```text
canonical endpoint
→ active Graph read
→ direct/incoming/outgoing path resolution
→ bounded derived neighborhood (max two hops)
→ public eligibility/readiness/route policy
→ related/dossier projection
→ frontend
```

Authority owns identity/lifecycle; Graph owns typed relations; Knowledge owns
atomic claims; Source/Evidence owns provenance/support; Media/MediaAsset/
MediaUsage retain their own boundaries; Video owns canonical external reference;
WordPress owns editorial Post content/URL. Related projection owns no fact and
never writes a shortcut edge.

`Article` is an editorial workflow over WordPress, not an `article` Graph
endpoint. Album/Collection remains `SEMANTIC_GAP` unless a future approved
registry contract adds it.

## 2. Current executable Graph inventory

Current full boot has 15 endpoint types:

`wp_post`, `brand`, `model`, `variant`, `movement`, `music`, `component`,
`classification`, `specimen`, `product`, `media`, `video`, `knowledge`,
`source`, `evidence`.

Current predicates are exactly:

| Predicate | Registered source → target |
|---|---|
| `about` | registered endpoint → registered endpoint under current allowlist |
| `depicts` | `media` → registered endpoint |
| `model_of` | `model` → `brand` |
| `variant_of` | `variant` → `model` |
| `uses_movement` | `variant` → `movement` |
| `supports_music` | `movement` → `music` |
| `configured_with_music` | `variant` → `music` |
| `observed_playing_music` | `specimen` → `music` |

`classified_as` is not registered. Product–Specimen has no dedicated approved
persistence relation. Missing vocabulary remains `REGISTRY_GAP`; broad `about`
may not impersonate membership, structural parentage, configuration,
movement-use or Product–Specimen ownership.

## 3. Current read engine

The current application/runtime now has the shared bounded semantic read seam
that older 2026-09-02 checkpoints lacked. It includes:

- Graph outgoing and incoming reads over the same stored canonical edge;
- bounded two-hop related/neighborhood traversal with registry validation;
- cycle protection and canonical-target deduplication;
- direct-versus-derived path information;
- public/dossier consumers over canonical Graph state;
- MCP `nhk.entity.neighborhood` for bounded semantic neighborhood reads;
- operational `nhk.graph.inventory` for internal/admin diagnostics.

Therefore historical wording such as “no reusable two-hop engine”, “no MCP
related read”, or “Graph neighborhood is unavailable” is superseded as a current
capability statement. Those old checkpoints remain historical evidence in Git
history/execution records only.

A specific frontend or dossier can still be incomplete. Missing consumption of
an eligible path/profile is `PARTIAL_FRONTEND_GAP`, not Graph absence.

## 4. Query contract

Input is bounded and registry-controlled:

```text
source type + canonical identity
profile / permitted target families
max_hops = 1..2
bounded limit/cursor where supported
```

Rules:

1. resolve the source through the registered canonical endpoint resolver;
2. read only active Graph edges through Graph/application boundaries;
3. preserve actual stored direction; incoming read is not reverse persistence;
4. direct result is one registered hop;
5. derived result is at most two registered/allowed hops;
6. prevent cycles and graph explosion;
7. deduplicate by canonical target identity;
8. direct wins an equivalent derived result while alternative explainable paths
   may be retained by reader/admin contracts;
9. apply public eligibility/readiness/route policy before public emission;
10. dependency failure, ambiguity or unsupported path is explicit unavailable/
    conflict/gap, not an honest empty set.

No query may infer a relation from display name, stable key wording, URL, Article
text, Dictionary label, WordPress taxonomy/postmeta, MediaUsage placement,
checksum, visual similarity or keyword search.

## 5. Result and explainability

Application/read-model results distinguish at least:

- source canonical identity/type internally;
- target canonical identity/type internally;
- `DIRECT` or `DERIVED`;
- `hop_count`;
- direction and registered predicate for each hop;
- best path and bounded alternatives when supported;
- public eligibility/route result;
- unavailable/conflict/gap diagnostics.

Public serialization replaces internal IDs with reader-safe labels/routes and
must not expose raw Graph storage, private Source/Evidence payloads, internal
lifecycle/revision or stable keys. Enough path explanation should remain for the
frontend to explain why an item is related where that surface exposes relation
context.

## 6. Ranking and projection

Pipeline:

```text
registered Graph candidate set
→ direct/derived classification
→ canonical deduplication / best path
→ permitted editorial/quality/freshness ranking
→ diversity/limit/pagination
→ projection
```

Relation filtering always happens before `LATEST`, `FEATURED` or other
presentation ranking. Ranking may order an already-authorized candidate set; it
cannot create semantic relationship truth.

Frontend owns section layout/title/card/grid/limit. Graph/Authority must not
store UI concerns. Derived paths are never materialized merely to enrich a page.

## 7. Cuckoo and Classification distinction

Canonical Cuckoo Classification
`01a07614-832d-7f27-959c-74eb0cd63f3e` currently has 10 core Knowledge claims
successfully connected by `Knowledge → about → Classification Cuckoo` through
the full governed mutation lifecycle and canonical Graph read-back.

Those direct `about` relations are valid Graph truth and may participate in
bounded neighborhood projection subject to public/readiness policy.

This does not mean Model/Variant membership exists. `classified_as` remains a
registry gap and may not be synthesized from `about`, lexical similarity or
frontend grouping.

## 8. Video/public-safe Knowledge boundary

Guided Video relation orchestration may establish a canonical Video `about`
relation with its verified Source→Claim→Evidence provenance chain. Related
projection consumes the canonical Graph edge after eligibility; it does not
infer the edge from YouTube title/URL/user hint.

Public-safe Knowledge can be projected after its own policy while PRIVATE raw
Source/Evidence remains hidden. Public Knowledge eligibility does not
automatically make a Graph relation public; relation eligibility is independent.

## 9. Current remaining frontend/read-model gaps

Current gaps are surface-specific rather than “Graph unavailable”:

- some entity dossier types do not yet have the same depth/type-specific path
  recipes as the complete Brand dossier;
- ranking/diversity/cache/telemetry policies may remain partial on individual
  consumers;
- public route/readiness coverage remains target-runtime dependent;
- missing registered relation vocabulary remains a registry gap rather than a
  frontend workaround opportunity.

Record these as `PARTIAL_FRONTEND_GAP`, `REGISTRY_GAP` or the exact runtime
reason. Do not fall back to taxonomy/keyword similarity.

## 10. Acceptance invariants

A conforming implementation proves:

1. one valid direct relation appears once;
2. a permitted two-hop result remains `DERIVED` and explainable;
3. three-hop/unbounded traversal is excluded;
4. direct beats equivalent derived after deduplication;
5. incoming/outgoing direction is preserved without inverse persistence;
6. cycles terminate;
7. wrong target/profile is excluded;
8. no relation yields honest empty, not keyword/taxonomy fallback;
9. runtime/registry failure remains unavailable/gap, not empty;
10. frontend does not mutate Graph truth;
11. Cuckoo Knowledge `about` relations are not misrepresented as
    `classified_as` membership;
12. replay/read-only projection never creates a Graph edge.
