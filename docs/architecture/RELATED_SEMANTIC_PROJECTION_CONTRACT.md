# NHK V3 Related Semantic Projection Contract

> **NON-NORMATIVE.** Đây là architecture contract dưới Hiến pháp. Nếu mâu
> thuẫn với `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Status: approved documentation contract and runtime audit, 2026-09-02.
This document defines a reusable read/query boundary. It does not authorize
new entity types, endpoints, predicates, fields, operations, Graph edges,
taxonomy, post meta, migration or data repair.

## 1. Purpose and boundaries

Every registered canonical endpoint may be used as the source of a bounded
related query. A public entity page is therefore a Graph entry point, but its
projection is read-only:

```text
registered endpoint
    → Graph read boundary
    → RelatedEntityQuery
    → public eligibility/readiness and route policy
    → RelatedSection projection
    → frontend
```

Authority owns identity/lifecycle, Graph owns typed relations, Knowledge owns
atomic claims, Source/Evidence owns provenance, Media/MediaAsset/MediaUsage
retain their separate boundaries, Video owns external references, and native
WordPress owns editorial Post content and URL. Related projection owns none of
those facts.

`Article` is not a canonical entity or Graph endpoint; a V3 Article is an
operation-level WordPress workflow. `Album`/`Collection` has no registered V3
entity type, endpoint, predicate, repository or public contract. It remains a
`SEMANTIC_GAP`; a section label cannot create its identity.

## 2. Runtime inventory

### 2.1 Entity and endpoint types

The current working-tree runtime registers these nine Authority entity types:

| Registry | Actual registered values |
|---|---|
| Authority `EntityTypeRegistry` | `brand`, `model`, `variant`, `movement`, `music`, `component`, `classification`, `specimen`, `product` |
| Graph `EndpointTypeRegistry` | `wp_post`, the nine Authority types above, `media`, `video`, `knowledge`, `source`, `evidence` |

The Graph endpoint count is 15. `wp_post` uses the existing stable key
`<blog_id>:<post_id>`; canonical semantic endpoints use their canonical UUID.
The endpoint registry is the authority for which source and target types may
be queried.

### 2.2 Predicate matrix from the runtime registry

The following is the current registry matrix, not a desired vocabulary. All
rows are direct persisted Graph predicates. The runtime `PredicateDefinition`
currently expresses source/target allow-lists and cardinality, but does not
express a symmetric, inverse or traversable flag. Consequently, derived
traversal is a contract/implementation gap until an approved traversal policy
can be enforced without inventing registry data.

| Source type(s) | Predicate | Target type(s) | Direction | Direct | Derived traversal allowed now |
|---|---|---|---|---|---|
| all 15 registered endpoints | `about` | all 15 registered endpoints | outbound source → target | Yes | `IMPLEMENTATION_GAP`: no central traversal/direction policy |
| `media` | `depicts` | all 15 registered endpoints | outbound media → target | Yes | `IMPLEMENTATION_GAP`: no central traversal/direction policy |
| `model` | `model_of` | `brand` | outbound model → brand | Yes | `IMPLEMENTATION_GAP`: structural reverse-read and path policy are not reusable |
| `variant` | `variant_of` | `model` | outbound variant → model | Yes | `IMPLEMENTATION_GAP`: two-hop structural policy is not in a shared engine |
| `variant` | `uses_movement` | `movement` | outbound variant → movement | Yes | `IMPLEMENTATION_GAP`: no shared two-hop traversal policy |
| `movement` | `supports_music` | `music` | outbound movement → music | Yes | `IMPLEMENTATION_GAP`: no shared two-hop traversal policy |
| `variant` | `configured_with_music` | `music` | outbound variant → music | Yes | `IMPLEMENTATION_GAP`: no shared traversal/ranking policy |
| `specimen` | `observed_playing_music` | `music` | outbound specimen → music | Yes | `IMPLEMENTATION_GAP`: no shared traversal/ranking policy |

The Graph service can read outgoing and incoming edges. An incoming read is a
query operation over the stored child→parent direction; it is not an inverse
predicate and must not be treated as permission to walk every edge backwards.
`about` and `depicts` are broad registered direct relations, not permission to
infer ownership, subject identity or a new relation between their endpoints.

The six technical predicates are registered in the current working tree, but
no physical edges are created by this documentation checkpoint. Existing
physical edge distribution remains a separate read-only/data-compatibility
question.

## 3. RelatedEntityQuery contract

`RelatedEntityQuery` is the single application/query concept for entity, Post,
Media, Video, Knowledge and other registered endpoint pages. Page-specific
assemblers may choose sections, but they must not implement independent Graph
traversal algorithms.

### 3.1 Input

```text
source_entity_id   required; canonical UUID for semantic endpoints, the
                    registered stable key for wp_post
source_entity_type required registered endpoint type
target_types[]     required bounded list of registered endpoint types
mode               RELATED | FEATURED | LATEST
max_hops           1 or 2; default 2; never greater than 2
limit              positive bounded projection limit
cursor             opaque pagination cursor, when pagination is supported
```

Input is validated against the endpoint and predicate registries. Unknown
types, malformed identities, unsupported mode, `max_hops > 2`, unbounded
limits, invalid cursors and unavailable dependencies fail closed. A query must
not load the whole Graph for a page request.

### 3.2 Candidate and traversal rules

1. Resolve the source through the registered endpoint resolver.
2. Read active Graph edges through `GraphService`/the governed repository
   boundary; the query must not use direct semantic SQL.
3. Accept a direct candidate only for one valid registered hop.
4. Accept a derived candidate only when every hop is active, registered,
   directionally permitted, endpoint-resolvable and within `MAX_HOPS = 2`.
5. Prevent cycles by canonical endpoint identity and bound target types,
   predicates, page size and hop count.
6. Resolve public active/readiness/visibility/eligibility and routeability
   before emitting a public item. An active row that is not publicly eligible
   is not a public related result.
7. Deduplicate by target canonical identity. Preserve the best path and,
   where the reader/admin contract allows it, alternative paths.

No traversal rule may be inferred from a display name, slug, URL, payload
field, WordPress taxonomy, post meta, checksum, visual similarity or an
unregistered inverse. No derived result is persisted.

### 3.3 Query-layer result

The application result retains the following fields for explainability and
ranking. These are query-result fields, not new canonical entity fields:

```text
target_entity_id
target_entity_type
relationship_class   DIRECT | DERIVED
hop_count            1 or 2
best_path             ordered registered endpoint/predicate hops
alternative_paths    zero or more ordered paths
ranking_reason       structured reason, not opaque recommendation text
rank_facts           optional facts from an existing approved signal
```

`best_path` contains the source/target identity and predicate for each hop;
provenance is retained when the underlying relation contract provides it. A
reader-safe serializer may replace internal IDs with display labels and
canonical public routes, but it must preserve enough path explanation to show
why the item appears. Internal UUIDs, stable keys, lifecycle, raw Graph rows
and provenance internals must not leak into public HTML or public APIs.

### 3.4 Empty, unavailable and conflict outcomes

| Condition | Result |
|---|---|
| Valid Graph query with no eligible candidates | Successful empty result |
| Graph/runtime/database dependency unavailable | Explicit unavailable/runtime failure; never successful empty |
| Unknown registry member or unsupported traversal | Typed gap/unsupported result; never taxonomy fallback |
| Ambiguous identity, endpoint, direction or public eligibility | Explicit conflict/blocked result; never guessed relation |

## 4. RelatedSection projection contract

`RelatedSection` is presentation configuration, not semantic storage:

```text
key
title
target_entity_types[]
mode
max_hops
limit
items[]
empty_state / unavailable_state
next_cursor (optional)
```

The section assembler may request visitor-facing groups such as direct
relations, Models, Movements, Variants, Knowledge, Articles, Media, Videos,
Specimens or Products. Each item must carry a valid query origin/path. A
Collection/Album section is unavailable until that boundary is registered.

The same query engine serves Brand, Model, Movement, Variant, Knowledge,
WordPress Post/Article workflow, Media, Video, Specimen, Product and every
other page with a registered endpoint. Brand is not granted a special
ownership shortcut. For example, a Brand page may display a Model discovered
through the incoming `model_of` read, or a Variant through the two registered
structural hops, only after the traversal policy and public eligibility gates
are implemented. A Movement or Music shown on Brand is still shared/derived
context, not Brand ownership.

Projection responsibilities include layout location, title, card/grid,
pagination, load-more, item limit and visitor-facing labels. Authority/Graph
must never receive these concerns. No section may source candidates from
global latest posts before semantic relation filtering.

## 5. Ranking contract

The ranking pipeline is:

```text
registered Graph relation filter
    → direct/derived classification
    → deduplication and best-path selection
    → mode-specific ranking
    → diversity policy
    → limit/pagination
```

Minimum precedence:

1. `DIRECT` relation;
2. `DERIVED` relation with at most two hops;
3. quality/editorial significance only when an existing approved signal is
   available;
4. freshness only for `LATEST`, and only inside the related candidate set;
5. diversity to avoid repeating one Graph branch when a valid policy exists.

`RELATED` ranks semantic strength first. `FEATURED` may not invent candidates
and remains unavailable or falls back to an explicitly documented semantic
mode when no quality signal contract exists. `LATEST` applies time ordering
only after relation filtering. Direct and derived paths to the same target
produce one item; direct wins and derived paths remain alternatives.

No opaque AI recommendation is part of this contract. If a future ranker is
introduced, it may order only the already-authorized Graph candidate set and
must not create relationship truth.

## 6. Cache and performance policy

Related results may be cached only in the query/projection layer. Cache keys
must include source identity/type, target type set, mode, hop bound, projection
contract version and relevant public eligibility/revision inputs. Invalidation
must cover relation changes, source/target revisions that affect projection,
eligibility/readiness changes and projection-contract changes.

The current repository has no verified related-result cache or invalidation
contract. This is an `IMPLEMENTATION_GAP`; this document does not add Redis,
plugin infrastructure or a cache table. Until bounded caching is implemented,
use the existing repository boundaries and conservative limits, with no
unrestricted recursive traversal or N+1 page-wide Graph load.

## 7. Runtime gap report

This report is based on the current working-tree code and registry, not on
invented rows or fixtures.

### P0 — conformance or semantic-truth risk

| Gap | Evidence | Required treatment |
|---|---|---|
| Predicate direction/inverse/traversal policy is not represented by the runtime definition; `RelatedContentQuery` reads both outgoing and incoming edges without a central policy | `PredicateDefinition.php`, `GraphService.php`, `RelatedContentQuery.php` | Record `CONSTITUTION_CONFLICT` risk if treated as complete; define and test a registry-backed read policy before relying on derived navigation; do not invent inverse predicates |
| Public related readers gate active/readiness but do not share the complete public eligibility/route decision used by the collection boundary | `RelatedContentQuery.php`, `BrandAggregationQuery.php`, `PublicEntityCollectionQuery.php` | Route every public related result through the existing eligibility/identity/route policy; no public item when routeability or eligibility is unresolved |
| Brand aggregation deduplicates by target but does not replace a previously collected `DERIVED` item with a later equivalent `DIRECT` item | `BrandAggregationQuery.php` (`appendUnique`) | Add a failing direct-beats-derived regression before changing the read model; retain alternative paths without duplicate presentation |

### P1 — missing required traversal/query/projection capability

| Gap | Evidence | Required treatment |
|---|---|---|
| No reusable bounded 2-hop traversal engine; current `RelatedContentQuery` is one-hop and `BrandAggregationQuery` is a Brand-specific manual traversal | `Application/Entity/RelatedContentQuery.php`, `Application/Graph/BrandAggregationQuery.php` | Implement one registry-driven engine with max-hop, target filtering, cycle prevention and path objects |
| Current related output has no standardized relationship class, hop count, best/alternative paths, ranking facts or opaque cursor | `RelatedContentQuery.php`, `BrandAggregationQuery.php` | Introduce the query contract in a TDD slice without changing canonical storage |
| Related pagination, dedupe/ranking and diversity are not a shared contract; current reads use fixed Graph page sizes and page-specific arrays | Same query classes; `EntityPageQuery.php` and theme `entity.php` | Add bounded pagination and projection assemblers after traversal behavior is proven |
| Entity pages have hard-coded generic related groups and a separate Brand aggregation path rather than one reusable section assembler | `EntityPageQuery.php`, `public/wp-content/themes/nhk-v3/entity.php` | Converge Brand and all registered endpoint pages on the shared query/projection boundary |
| MCP exposes no related/Graph read; raw Graph REST remains administrator-only | `MCP_V3_CONTENT_OPERATIONS.md`, `GraphApi.php` | Add only a future read contract review; no MCP tool or WordPress Ability is authorized by this documentation task |
| Product–Specimen relation mechanism is not registered, and Album/Collection has no semantic boundary | Constitution §11; MCP content-operations audit | Keep Product linkage fail-closed and Album/Collection as `SEMANTIC_GAP`; do not use `about` or a section name as a workaround |

### P2 — ranking, cache and operational enhancement

| Gap | Evidence | Required treatment |
|---|---|---|
| No verified quality/editorial significance signal or diversity policy for `FEATURED`/large related sections | Current query services and frontend contracts | Keep ranking limited to relation strength/freshness where contracted; add signals only through a later contract |
| No verified query/projection cache or invalidation mechanism | Repository-wide architecture audit | Implement only after correctness and invalidation dependencies are tested; no new cache infrastructure in this task |
| No dedicated related-query performance budget/telemetry contract | Current query services and execution state | Add bounded query metrics and N+1 regression coverage after the shared engine exists |

## 8. Acceptance test contract

The implementation plan must write failing tests first and eventually prove:

1. A valid direct relation appears once.
2. A valid two-hop derived relation appears with `DERIVED`, `hop_count=2` and
   an explicit path.
3. A three-hop candidate never appears.
4. Direct plus derived paths to the same target produce one item, with direct
   as best path and derived retained only as an alternative when allowed.
5. Traversal honors registered source/target direction and does not assume an
   inverse.
6. Cycles terminate without recursion or duplicate output.
7. A wrong target type is excluded.
8. No relation returns an honest empty result and never falls back to taxonomy.
9. `LATEST` sorts only inside the semantic candidate set.
10. `FEATURED` cannot invent a candidate outside the Graph set.
11. Brand receives only sections backed by valid paths and does not create
    Brand ownership shortcuts.
12. Model uses the same query engine as Brand and other entity pages.
13. Knowledge, Post/Article workflow, Media and Video remain navigable without
    becoming new entity types or body owners.
14. Every derived result contains a traceable path and predicate sequence.
15. WordPress taxonomy/post meta/direct semantic SQL cannot substitute for the
    governed Graph query boundary.

## 9. Non-goals and rollout gates

This checkpoint does not implement PHP, JavaScript, SQL, MCP, WordPress,
frontend or cache changes. It does not create relation data, repair missing
parents, migrate article bodies, seed entities, assign slugs, publish content,
modify V2/live data or claim parity.

Future phases must complete registry/contract audit, TDD traversal,
ranking/deduplication, reusable projection, page integration, MCP read review,
performance/cache work and the full constitutional regression audit in the
sequence recorded in
`docs/superpowers/plans/2026-09-02-related-semantic-navigation.md`.
