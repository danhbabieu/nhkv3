# Graph Core Contract

> **NON-NORMATIVE IMPLEMENTATION CONTRACT.** If this document conflicts with
> `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution controls.
> Statements below about the old P2 surface are retained only as history where
> explicitly labelled; current executable registry/runtime status controls.

## Boundary

Graph is the single semantic relation persistence system for registered NHK V3
endpoints. Domain code is WordPress-independent; infrastructure owns WPDB
adapters. Article remains an operation over the registered `wp_post` endpoint,
not an `article` Graph entity.

Frontend, Admin, MCP, WordPress taxonomy/postmeta, MediaUsage and payload fields
must not create a parallel relation store. A relation exists canonically only
when it is represented by a valid registered Graph edge and read back from the
Graph boundary.

## Current endpoint and predicate registries — 2026-09-07

Current full boot registers 15 endpoint types:

`wp_post`, Authority `brand`, `model`, `variant`, `movement`, `music`,
`component`, `classification`, `specimen`, `product`, plus `knowledge`,
`source`, `media`, `video`, `evidence`.

Current executable predicates are:

| Predicate | Current source → target contract |
|---|---|
| `about` | registered endpoint → registered endpoint under the broad current allowlist |
| `depicts` | `media` → registered endpoint |
| `model_of` | `model` → `brand`, outbound ONE / inbound MANY |
| `variant_of` | `variant` → `model`, outbound ONE / inbound MANY |
| `uses_movement` | `variant` → `movement` |
| `supports_music` | `movement` → `music` |
| `configured_with_music` | `variant` → `music` |
| `observed_playing_music` | `specimen` → `music` |

`classified_as` is **not registered**. A dedicated Product↔Specimen relation is
also not registered. Missing vocabulary is `REGISTRY_GAP`; do not use `about`
to fake classification membership, structural parentage, configuration,
movement use, Product–Specimen ownership or another missing predicate.

If the registry permits `variant → uses_movement → movement`, that is not
permission to mint a Model→Movement edge. Endpoint/type allowlists are semantic
law, not suggestions.

## Canonical relation command identity

A `relation_create` packet preserves real typed endpoints:

- `source_type` = actual semantic endpoint type;
- `source_uuid` = actual existing canonical source UUID;
- `predicate` = registered predicate;
- `target_type` = actual canonical target type;
- `target_uuid` = actual existing canonical target UUID.

The historical proposal-hydration defect that could return `subject_id` as an
entity-type string (for example `knowledge`) instead of the relation source UUID
is **RESOLVED**. Current `WpdbProposalRepository::hydrate()` derives relation
subject identity from `payload.source_uuid`, with legacy `source_key` only as a
compatibility fallback. Runtime relation flows have completed through Graph
canonical read-back after this fix.

Do not treat that old defect as a global Graph blocker. It is also distinct from
a create Proposal for a new Authority entity: a new node has no canonical UUID
before creation, while a relation source must already exist canonically.

## Governed mutation contract

Graph create/retire/reactivate are governed semantic mutations. Current
lifecycle is:

`proposal/relation_create|relation_retire|relation_reactivate → submit → review →
approval with binding fingerprints → eligibility → Controlled Apply → canonical
Graph read-back → idempotency verification`.

GraphService normalizes and validates source/target existence, predicate,
allowlists, self-relation/cardinality, state and revision. Exact active triple
creation is idempotent and returns/reuses the existing edge. A retired triple is
not silently resurrected; reactivate is explicit. Cardinality conflict does not
auto-retire another edge. Revision mismatch fails closed.

`COMPLETED` is not inferred from proposal state or an apply response. Canonical
Graph read-back must show the expected typed active/retired edge. A second run
of the same intent must not create another active edge.

Post→Knowledge or any other relation created by Article/Video/Media workflows
uses this same governed boundary. Direct relation mutation outside Governance is
a `CONSTITUTION_CONFLICT`.

## Storage contract

`nhk_graph_nodes` stores normalized endpoint references with a unique
`(endpoint_type, endpoint_key)` identity. `nhk_graph_predicates` is the compact
predicate dictionary; executable predicate rules remain in code. Graph edges
hold edge UUID, source/target node IDs, predicate, lifecycle state, revision and
timestamps. Exact source/predicate/target triple is unique.

Edges do not store Article body, Knowledge payload, Media metadata or Evidence
blob. Graph nodes are not hard-deleted while edges depend on them.

Mutation is transaction-safe. Unique constraints are the final duplicate safety
net; cardinality checks and exact-edge operations are serialized at the storage
boundary according to the repository contract.

## Query, direction and semantic neighborhood

Canonical Graph reads support outgoing and incoming direction. Reverse query is
not reverse persistence. Default query returns active edges; retired state is
explicit. Cursor pagination remains bounded and storage-oriented reads are
administrator/internal where they expose technical identifiers.

The current executable MCP/read boundary includes:

- `nhk.graph.inventory` for bounded operational edge inventory/diagnostics;
- `nhk.relation.backfill.dry_run` for read-only relation audit;
- `nhk.entity.neighborhood` for a bounded semantic neighborhood with maximum two
  hops and registered profiles;
- application/frontend related/dossier queries over canonical Graph data.

Therefore “Graph neighborhood does not exist” or “Graph has no read seam” is
stale. Individual frontend surfaces may still consume only part of the eligible
neighborhood/path policy; classify that as `PARTIAL_FRONTEND_GAP`, not as Graph
unavailability.

Direct, incoming/outgoing and derived results remain distinct. Derived paths are
read-time bounded associations and are never persisted merely to enrich UI.
Frontend must not use keyword search, taxonomy, postmeta or display-name matches
as a relation substitute.

## Evidence and provenance

Graph does not embed provenance blobs. Evidence requirements are enforced by the
owning relation/orchestration contract. Video `about` relation flows can require
canonical Evidence references and verify PRIVATE/HIDDEN Source/Evidence through
governed internal read-back without making those payloads public.

A public-safe Knowledge projection does not make a Graph relation public.
Relation projection applies its own eligibility, endpoint and privacy rules.

## Cuckoo runtime correction — 2026-09-07

Canonical Cuckoo Classification:

- UUID `01a07614-832d-7f27-959c-74eb0cd63f3e`;
- stable key `nhk:classification:clock-type.cuckoo-clock`;
- name `Đồng hồ chim cúc cu`.

Ten core Cuckoo Knowledge claims have completed the real governed
`Knowledge → about → Classification` relation lifecycle and canonical Graph
read-back. For those 10 claims, older `RELATION_GAP`/“Cuckoo neighborhood
empty” wording is superseded by `COMPLETED` current runtime evidence.

This does not close Classification membership. A Model/Variant classification
membership would require `classified_as`, which remains a registry gap. Do not
confuse Knowledge `about` with entity membership.

## Historical P2 wording

Early P2 documents correctly recorded that the initial phase did not expose
REST/MCP mutation and used limited/fake endpoint support during foundation work.
That is historical implementation evidence only. Current Governance, MCP and
GraphService relation surfaces described above supersede it; the old absence of
a mutation adapter must not be carried forward as a current blocker.
