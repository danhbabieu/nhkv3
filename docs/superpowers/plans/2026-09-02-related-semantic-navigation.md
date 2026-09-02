# V3 Related Semantic Navigation Implementation Plan

> **NON-NORMATIVE.** This plan is subordinate implementation guidance. The
> sole normative source is `docs/constitution/NHK_V3_CONSTITUTION.md`.

> **For agentic workers:** Read the Constitution and the related projection
> contract before each phase. Use TDD: write the failing contract test,
> observe the red result, implement the smallest compliant slice, then run the
> focused tests and repository quality gates. Do not treat this plan as
> authorization to create data or vocabulary.

## Goal

Make every registered canonical endpoint a bounded Semantic Graph navigation
entry point and provide one reusable related-query/projection path for Brand,
Model, Movement, Variant, Knowledge, WordPress Post/Article workflow, Media,
Video, Specimen, Product and other registered endpoints.

The target behavior is:

```text
registered endpoint
  → governed Graph read
  → bounded direct/derived candidate set
  → path-aware dedupe and ranking
  → public eligibility/readiness/route policy
  → RelatedSection projection
```

## Non-negotiable constraints

- Do not invent an entity type, endpoint, predicate, relation type, field,
  operation, inverse, traversal flag or knowledge profile.
- Use only the current runtime registry and reviewed contracts. Album/Collection
  remains a `SEMANTIC_GAP`; Article remains a WordPress workflow, not an
  Article entity or Graph endpoint.
- `MAX_HOPS = 2`. Never persist derived results or create transitive shortcut
  edges.
- Graph is the only relation source. Do not use taxonomy, post meta,
  hard-coded IDs, display names, slugs, checksums or direct semantic SQL as a
  fallback.
- Keep WordPress `wp_posts` as editorial title/body/author/date/category/URL
  truth. Do not migrate or parse legacy article bodies.
- Keep Product and Specimen identities separate. Do not create or repair a
  Product–Specimen link in this plan without a separately approved relation
  contract.
- Preserve distinction between successful empty, unavailable infrastructure,
  registry gap and identity/eligibility conflict.
- No phase authorizes seed, backfill, migration, slug assignment, publication,
  V2/live mutation or production cutover.

## Required evidence per phase

Each implementation phase is its own checkpoint. Before advancing, record:

- focused PHPUnit/TDD evidence and relevant guarded integration evidence;
- PHP lint and migration checks when schema is touched;
- `git diff --check` and a secret review;
- read-only runtime/route evidence where available;
- any unavailable dependency as an explicit blocker, never as empty data;
- an update to `docs/architecture/V3_EXECUTION_STATE.md`.

## Phase 0 — Registry / contract audit

**Purpose:** Freeze the executable vocabulary and direction rules before code.

**Read/verify:**

- `CanonicalEntityTypeCatalog.php`, `EntityTypeRegistry.php`;
- `EndpointTypeRegistry.php`, `CoreEndpointResolverRegistrar.php`;
- `PredicateRegistry.php`, `PredicateDefinition.php`;
- `GraphService.php`, `GraphRepository.php`;
- `RELATED_SEMANTIC_PROJECTION_CONTRACT.md`, Brand relationship contracts,
  public eligibility/identity/route contracts and MCP contracts.

**TDD first:** Add/extend registry contract tests that assert the exact current
Authority types, 15 endpoint types, eight predicate definitions, cardinality
and source/target allow-lists. Assert that unknown predicates, endpoints and
types fail closed. Add a test documenting that incoming Graph reads are not an
implicit inverse traversal permission.

**Implementation:** No registry expansion is part of this plan. If a needed
path is absent, emit `REGISTRY_GAP`/`IMPLEMENTATION_GAP` and stop that path.
Resolve any `CONSTITUTION_CONFLICT` before traversal work.

## Phase 1 — Graph traversal engine + tests

**Purpose:** Implement one bounded read engine over active Graph edges.

**TDD first:** Prove direct one-hop results, valid two-hop results, no
three-hop results, wrong target filtering, cycle termination, canonical
identity dedupe, max-limit enforcement and successful-empty versus unavailable
Graph failure.

**Implementation seam:** Extend or replace the current application query seam
with a registry-driven traversal service. It may use `GraphService` outgoing or
incoming reads only when the contract explicitly permits that direction. It
must carry source/target endpoint identity, predicate and hop per path, prevent
cycles and never call a write method.

**Do not:** add a recursive unrestricted walker, inverse predicates, a relation
table, a Graph cache or a data repair command.

## Phase 2 — Related ranking / deduplication / path explanation

**Purpose:** Make candidate selection deterministic and explainable.

**TDD first:** Prove that direct beats derived for the same target, alternative
paths are retained only where allowed, `RELATED` ranks relation strength,
`LATEST` sorts only after semantic filtering, `FEATURED` cannot invent a
candidate, and no duplicate target is rendered.

**Implementation seam:** Add query-layer result/value objects for
`DIRECT`/`DERIVED`, hop count, best path, alternative paths, ranking reason and
existing rank facts. Keep raw IDs/provenance internals out of reader-safe
serialization. Do not add canonical fields.

**Signals:** Use only existing approved editorial/quality/freshness signals.
If a signal or diversity policy is absent, keep the mode bounded and report the
gap rather than inventing a score.

## Phase 3 — Related query service and projection

**Purpose:** Expose one reusable `RelatedEntityQuery` and `RelatedSection`
assembler to page consumers.

**TDD first:** Validate source identity/type, registered target types, modes,
`max_hops` 1/2, bounded limit, opaque cursor behavior, public eligibility and
routeability. Prove no direct semantic SQL or taxonomy/post-meta fallback.

**Implementation seam:** Converge existing `RelatedContentQuery` and
`BrandAggregationQuery` behavior behind the shared contract. Preserve existing
public serializers and routes while adding reader-safe path explanations.
Related sections are projection configuration only; they do not write
Authority/Graph.

## Phase 4 — Brand page integration

**Purpose:** Make Brand a complete, honest graph entry point.

**TDD first:** Prove Models, Variants, Movements, Music, Knowledge, Posts,
Media, Videos, Specimens and Products appear only when a registered, active,
eligible path exists; derived items show their path; shared Movement/Music does
not become Brand ownership; empty groups remain empty.

**Implementation:** Replace special-case assembly only after the shared query
tests are green. Keep `model_of`/`variant_of` storage child→parent and never
persist Brand shortcuts. If physical rows are missing, report the existing
data-compatibility gap; do not repair it.

## Phase 5 — Model / Movement / Variant integration

**Purpose:** Apply the same engine to structural and shared technical pages.

**TDD first:** Prove Model→Brand and Variant→Model direction, two-hop Variant→
Model→Brand context, Variant→Movement, Movement→Music and configured Music
scope. Prove missing, ambiguous, inactive and payload/Graph-conflicting
parents fail closed according to the existing transition contract.

**Implementation:** Wire page-specific section configuration only. Do not treat
`brand_uuid` or `model_uuid` payload fields as final Graph truth, and do not
create Variant→Brand, Movement→Brand or other ancestor shortcuts.

## Phase 6 — Knowledge / Article integration

**Purpose:** Keep Knowledge and native WordPress Article/Post navigation
connected without creating an Article entity or duplicating body truth.

**TDD first:** Prove Knowledge claims can show eligible subject/entity, source
and evidence context only through registered paths; Posts use `wp_post` stable
keys; article body remains native WordPress; Article Ingest completion rules
remain separate; no body parsing or copy occurs.

**Implementation:** Consume the shared related read from existing Post and
Knowledge application services. Do not add an Article endpoint, MCP Article
tool, FAQ entity or second body projection.

## Phase 7 — Media / Video / Collection integration

**Purpose:** Apply the same boundary to Media and Video, while preserving
Collection’s current gap.

**TDD first:** Prove Media/MediaAsset/MediaUsage remain separate, public Media
requires its existing readiness/visibility/delivery policy, Video requires a
valid supported external reference, and no thumbnail/local binary becomes an
implicit relation. Prove Collection/Album remains unavailable without a
registered contract.

**Implementation:** Wire Media and Video read services to the projection
assembler. Do not create an Album/Collection type, relation or workaround.

## Phase 8 — MCP read exposure review

**Purpose:** Decide whether and how a future reader-safe related query can be
exposed through MCP without bypassing Graph governance.

**TDD first:** At minimum, test that current `tools/list` and WordPress
Abilities remain unchanged; raw Graph storage and lifecycle/provenance internals
are not exposed; unauthenticated or invalid calls fail closed.

**Implementation gate:** This phase does not automatically add a tool. A new
MCP read ability requires a separate reviewed catalog/schema decision and must
delegate to the shared `RelatedEntityQuery`. Until approved, retain raw Graph
REST as administrator-only and report `IMPLEMENTATION_GAP`.

## Phase 9 — Performance / cache / invalidation

**Purpose:** Bound cost after correctness is proven.

**TDD first:** Prove max-hop/target/limit/cursor bounds, cycle protection,
duplicate suppression, no N+1 page-wide traversal, and deterministic invalidation
when an edge, source/target revision, eligibility/readiness state or projection
contract changes.

**Implementation gate:** Cache only query/projection results. Do not add Redis,
plugins, tables or new persistence without a separate approved architecture
decision. If no cache is needed, record that bounded uncached reads are the
current policy.

## Phase 10 — Full constitutional regression audit

**Purpose:** Prove the complete navigation law across runtime and public
surfaces.

**Required checks:**

- all tests from the contract’s 15-case acceptance list;
- Authority/endpoint/predicate registry exactness;
- Graph read-only and Governance write-boundary checks;
- public eligibility, identity, route, REST, theme, search, sitemap and RSS
  convergence;
- Brand, Model, Movement, Variant, Knowledge, Post/Article, Media, Video,
  Specimen and Product empty/error/ambiguous states;
- Album/Collection and Product–Specimen gaps remain fail-closed;
- no taxonomy/post-meta/hard-coded-ID/raw-SQL semantic fallback;
- PHP lint, guarded integration on exact `nhk_v3_test` where applicable,
  `git diff --check`, secret review and execution-state evidence.

The final report must distinguish implementation completion from data parity.
No final production cutover or external publish is implied.

## Current checkpoint disposition

This documentation checkpoint completes the constitutional and architecture
definition only. Runtime implementation remains `P0/P1/P2` work as listed in
`RELATED_SEMANTIC_PROJECTION_CONTRACT.md`. No PHP/JS/SQL code, Graph edge,
Authority record, WordPress Post, V2/live record, taxonomy, post meta, cache or
publication was changed by this plan.
