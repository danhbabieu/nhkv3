# NHK V3 Dictionary Semantic Enrichment and Projection Contract

## Public source-display cross-reference — 2026-10-06

All public semantic enrichment projections apply
`PUBLIC_RESEARCH_SOURCE_DISPLAY_POLICY` after active/public Source and Evidence
checks. Vietnamese sources may remain provenance but cannot appear in public
citations. The Component owner route for `Côn hoa thị` is
`/linh-kien/con-hoa-thi/`; delegated lexical entries resolve there without a
competing indexable Dictionary page, while ambiguous terms remain fail-closed.

> **APPROVED CONTRACT-FIRST DESIGN — 2026-10-05.**
> This contract is subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md`.
> It defines the implementation boundary for Dictionary semantic enrichment.
> It does not authorize schema migration, runtime mutation, staging acceptance,
> canary population or deployment. Until the implementation gates below pass,
> this document is design law and the current runtime remains unchanged.

## 1. Status and non-negotiable law

The design is approved for implementation planning only. The implementation
must preserve:

1. one canonical owner for every semantic payload;
2. relation/reference storage only between owners;
3. no duplicated labels, titles, descriptions, URLs, images or claim text;
4. Dictionary remains a lexical owner and never becomes a Graph endpoint;
5. Graph remains the only semantic relation store;
6. public output resolves payload live from canonical owners;
7. semantic mutation remains governed, revision-bound, idempotent and
   read-back verified;
8. normal deletion is lifecycle retirement, never hard deletion;
9. `Côn hoa thị` is a reference dataset, never a code path, schema branch,
   registry exception or authorization scope.

The terms **implemented**, **planned**, and **not authorized** are distinct.
This contract does not change the executable predicate registry or database.

## 2. Ownership map

| Data | Canonical owner | Dictionary behavior |
|---|---|---|
| Entry/Form/Sense wording and lexical context | Dictionary | Owns and projects |
| Semantic entity identity and attributes | Authority | Stores only typed reference |
| Atomic factual claim | Knowledge | Stores only typed reference |
| Claim support/provenance | Source/Evidence | Stores only canonical Evidence references |
| Semantic relation | Graph | Requests/provides governed relation intent |
| Binary/media placement | Media/MediaAsset/MediaUsage | Reads eligible projection only |
| External video identity | Video | Reads eligible projection only |
| Article title/body/permalink | WordPress `wp_posts` | Reads eligible projection only |

Dictionary must not copy any owner payload into an Entry relation, semantic
reference, lexical relation, facet packet, or cache that is treated as truth.

## 3. `semantic_reference`

The existing Entry↔Sense mapping remains the sole Dictionary-owned semantic
reference boundary:

```text
Entry → Sense/DictionaryConcept → semantic_reference(type, id, revision)
                                      → canonical owner → current projection
```

The mapping stores only canonical owner identity and the last observed owner
revision. A read must revalidate owner type, identity, active state, revision,
public eligibility and current canonical route. A stale mapping is not an
identity and is never used to publish a link.

No second semantic-reference table is permitted. Legacy Concept destination
snapshots remain compatibility input only and never outrank a valid mapping.

### 3.1 Dictionary content operating law

A Dictionary item is operationally one piece of content, not a custom semantic
architecture. Normal intake is `term + definition/context + optional
semantic-owner hint`; the operator does not manually design a relation tree.
The shared model is:

```text
Dictionary Content
├── term
├── definition
├── context
└── semantic_reference
      ↓
Canonical Owner
      ↓
Existing / progressively governed relations
```

Entry/Sense remains the lexical owner, while Authority, Knowledge, Graph,
Media, Video and WordPress retain their canonical payloads and relationships.
Dictionary reads related context through the shared registries and bounded read
models; it does not copy those payloads or relations into a second structure.
The minimum valid state may be only:

```text
Entry → Sense → semantic_reference → canonical owner
```

Missing relations are not an error. Enrichment may become denser over time,
but a relation is added only with sufficient provenance/evidence and the normal
Governance lifecycle. A technically permitted source/target pair is not enough;
suggestions remain non-canonical until validation/Governance succeeds. Existing
relations retain their own create/add, read, retire and reactivate lifecycle.

Public Dictionary composition therefore derives Brand, Movement, Music,
Classification, Media, Video, Post and Knowledge context from canonical owners.
Those later relationships are enrichment of the canonical owner, not part of
the minimum Dictionary-entry creation contract.

## 3.2 Persisted mapping, owner hints and delegated public detail — 2026-10-08

The executable runtime now distinguishes canonical Dictionary mapping state from
curator/context hints.

- `persisted_semantic_reference` is the only Dictionary mapping truth. It must
  come from the Entry/Sense mapping repository and be revalidated against the
  canonical owner.
- `owner_hint` / `context.semantic_reference` is advisory planning metadata
  only. It may help resolve a candidate owner, but it is never proof that a
  mapping exists and it must not suppress `SET_SEMANTIC_REFERENCE` when the
  persisted mapping is absent.
- If a persisted mapping exists and revalidates, enrichment may return `NOOP`
  for semantic-reference mutation.
- If a hint conflicts with persisted mapping, or the persisted mapping is stale,
  invalid or ambiguous, the flow fails closed to review/blocked state; it must
  not silently replace canonical ownership.

Public detail projection follows the same persisted/revalidated mapping. An
approved Entry/Sense with one valid canonical semantic owner is a delegated
lexical surface:

```text
Dictionary Entry/Sense
  → persisted semantic_reference
  → revalidated canonical owner
  → delegated public detail
```

For delegated detail:

- `route.mode=DELEGATED`;
- `route.delegated=true`;
- the SEO canonical is the canonical owner's absolute public URL;
- the Dictionary lexical URL is `noindex,follow` and excluded from sitemap;
- lexical content may remain viewable when the presentation contract permits,
  but it must not compete as a second canonical document.

Standalone approved lexical content with no valid persisted semantic mapping
remains `DEDICATED` and follows the existing Dictionary indexability policy.
A stale, invalid or conflicting mapping never causes delegation.

## 4. Semantic Graph relations

Semantic enrichment reuses the existing Graph edge:

```text
source endpoint → registered predicate → target endpoint
```

The Graph edge stores endpoint identity, predicate identity, lifecycle state,
revision and timestamps. It never stores target display payload.

### 4.1 `associated_with`

`associated_with` is one bounded generic predicate for real, curated,
provenanced associations that are weaker than a registered structural
predicate. It is not a catch-all predicate.

The initial whitelist is:

| Source | Target | Allowed meaning |
|---|---|---|
| `component` | `brand` | documented association of the component with the brand |
| `component` | `movement` | documented association with a movement/recognition family |
| `component` | `music` | documented, scope-qualified association |
| `component` | `classification` | documented association with a registered classification |
| `movement` | `music` | only where no stronger registered capability predicate applies |
| `variant` | `classification` | only where the association is not registered membership |

This whitelist is closed. Every additional pair requires a contract amendment
and registry update.

The following predicates always take precedence when semantically accurate:

- `model_of`
- `variant_of`
- `uses_movement`
- `supports_music`
- `configured_with_music`
- `observed_playing_music`
- `classified_as`
- `subtype_of`
- `about`
- `depicts`

The same fact must not be persisted once as a strong predicate and again as
`associated_with` merely to populate a facet.

### 4.2 Scope law

Every `associated_with` proposal must state its exact scope:

```text
scope_subject = source canonical identity
scope_level   = source | target | relation_context
scope_claim   = bounded human-readable scope code, not copied payload
```

Scope is not inherited across arbitrary Graph paths. In particular:

```text
Component associated_with Music
≠ every Specimen containing that Component plays that Music
```

Specimen, Variant, Model and Brand projections may display the relation only
through an explicitly registered path recipe whose scope law permits it.

## 5. Graph-owned relation context

The current Graph edge schema is sufficient for identity/lifecycle but not for
durable provenance, evidence and scope. Implementation must add one Graph-owned
relation-context boundary keyed by `edge_uuid`; it is not a second relation
store and it contains no semantic payload.

Planned logical shape:

```text
graph_relation_context
- context_uuid
- edge_uuid                 UNIQUE, canonical Graph edge identity
- source_revision
- target_revision
- scope_code
- scope_subject_type
- scope_subject_id
- provenance_class
- evidence_refs_json        list of {evidence_id: canonical UUID}
- approval_fingerprint
- idempotency_key
- revision
- created_at
- updated_at
- retired_at
```

The context must be read and written transactionally with the governed edge
transition. It may reference Evidence UUIDs and owner revisions, but may not
contain Evidence text, target names, URLs, descriptions, images or claim text.

Required provenance classes are the existing controlled classes:

- `OBSERVED_FROM_MEDIA`
- `EXPLICIT_USER_KNOWLEDGE`
- `CATALOG_SUPPORTED`
- `EXTERNAL_RESEARCH`
- `SYSTEM_INFERENCE`

`SYSTEM_INFERENCE` is never sufficient by itself for a public semantic edge.
The Evidence resolver must verify every referenced Evidence UUID at proposal
and apply time. Private Evidence may support an internal relation but must not
be exposed by public projection.

## 6. Dictionary lexical relations

Explicit related terms are Dictionary-owned lexical relations, not Graph
relations and not semantic owner relations.

Planned logical shape:

```text
dictionary_lexical_relations
- relation_uuid
- source_entry_uuid
- source_sense_uuid       nullable
- target_entry_uuid
- target_sense_uuid       nullable
- relation_kind
- provenance_json         attestation/curation metadata only
- idempotency_key
- state
- revision
- created_at
- updated_at
- retired_at
```

The relation stores IDs only. It never stores Entry labels, Sense definitions,
URLs, owner payload, article text or Graph endpoint payload.

Registered relation kinds:

- `RELATED`
- `SAME_TERM_FAMILY`
- `BROADER`
- `NARROWER`
- `NEAR_SYNONYM`

### 6.1 Entry-level versus Sense-level

- `RELATED` and `SAME_TERM_FAMILY` are Entry-level by default.
- `BROADER`, `NARROWER` and `NEAR_SYNONYM` require Sense-level endpoints
  unless an explicit contract rule declares the Entries single-Sense and
  unambiguous.
- A relation with a missing required Sense endpoint fails closed.
- Entry-level display may aggregate Sense-level relations only after resolving
  the public Sense and preserving the relation kind and scope.

Lexical similarity, keyword frequency, embedding distance and co-occurrence
may create review candidates only. They never create a durable relation.

## 7. Dynamic bounded public facets

Dictionary detail uses a registered facet registry and a bounded read recipe,
not hard-coded semantic fields or persisted facet rows.

Each facet definition contains:

```text
facet_key
allowed_target_types
allowed_predicates
allowed_classification_families
max_items
ordering
public_label
scope_policy
```

Initial facet keys may include:

```text
brands, clock_types, configurations, movements, music, countries,
models, variants, specimens, components
```

`countries` resolves only `classification` targets with
`family=country`. `clock_types` resolves only the registered clock-type
classification family. Facet labels are presentation metadata, not semantic
truth.

The projection packet contains only resolved public data:

```text
facet_key, target_type, public_identity, public_url,
origin(DIRECT|DERIVED), hop_count, predicates, via_types,
availability, has_more
```

Direct paths win over derived paths; shorter approved paths win over longer
ones. Generic unbounded traversal, recursive descendant scraping and shortcut
edge persistence are forbidden.

## 8. Media, Video, Article and Knowledge projection

- Media remains owned by Media/MediaAsset; contextual placement remains
  MediaUsage. A Dictionary facet may resolve an eligible existing Media item,
  but does not create or copy Media data.
- Video remains owned by Video. A direct `Video → about → semantic owner`
  relation remains the semantic path; a lexical Mention does not become a
  Video relation.
- Article title/body/permalink remain owned by WordPress. Article mentions are
  lexical observations unless a governed direct semantic relation exists.
- Knowledge claims remain subject-scoped. Reachability or Dictionary wording
  does not promote a child claim to the Dictionary subject or another owner.
- Private Evidence and Source metadata never enter public facet packets.

## 9. Governance and lifecycle

All semantic and lexical relation mutations use:

```text
resolve/reuse → plan → human approval → eligibility → Controlled Apply
→ repository CAS → canonical read-back → projection revalidation
```

Supported operations:

- `ADD`: create only when absent; active duplicate is idempotent;
- `REPLACE`: requires exact relation ID and expected revision;
- `RETIRE`: lifecycle transition retaining the row;
- `REACTIVATE`: explicit transition requiring current revision;
- normal hard-delete: forbidden.

The idempotency packet binds operation, source/target IDs, relation kind or
predicate, scope, evidence references, owner revisions, registry hash and
request fingerprint. Changed packets under the same idempotency key fail with
an idempotency conflict.

Canonical read-back must verify relation identity, state, revision, context,
endpoint revisions, Evidence resolution and public eligibility. A successful
database write without read-back is not completion.

## 10. Failure states

Implementations must preserve distinct outcomes, including:

- `REGISTRY_GAP`
- `ENDPOINT_NOT_FOUND`
- `ENDPOINT_INACTIVE`
- `RELATION_SCOPE_UNSUPPORTED`
- `STRONG_PREDICATE_REQUIRED`
- `EVIDENCE_REQUIRED`
- `EVIDENCE_INVALID`
- `PROVENANCE_REQUIRED`
- `OWNER_REVISION_STALE`
- `RELATION_REVISION_CONFLICT`
- `RELATION_RETIRED_REACTIVATION_REQUIRED`
- `IDEMPOTENCY_CONFLICT`
- `PUBLIC_PROJECTION_UNAVAILABLE`
- `PUBLIC_SCOPE_BLOCKED`
- `DICTIONARY_ENTRY_SENSE_SCHEMA_UNAVAILABLE`

Unavailable, blocked, ambiguous, retired and empty are not interchangeable.

## 11. MCP/Admin boundary

MCP/Admin may expose bounded read, preview, proposal, approval-queue,
retire/reactivate and read-back operations. They must use the registered
predicate/facet/relation-kind catalogs and the existing Governance capability
boundary.

No generic arbitrary-predicate writer, direct SQL writer, direct Graph bypass,
or Dictionary-to-Graph endpoint adapter is permitted. Normal new submissions
continue through the canonical Capture boundary; internal/admin compatibility
operations require the existing internal capability.

## 12. Backward compatibility

- Migration 015 Concept/Label/Candidate/Mention data remains readable and is
  never rekeyed, merged or silently rewritten.
- Migration 024 Entry/Form/Sense mapping remains additive and schema-gated.
- Existing `semantic_reference` remains authoritative over legacy destination
  snapshots.
- Existing Graph predicates and edges retain behavior.
- New predicate/context and lexical relation storage are additive and disabled
  until their runtime registry and migration gates are present.
- Existing public Dictionary routes remain valid; new facets are additive.
- Existing clients receiving legacy fields must continue to receive compatible
  empty/unavailable states rather than invented values.

## 13. Performance bounds

- Dictionary detail performs one bounded owner resolution per unique reference.
- Facets use registered predicates, target types, path recipes and per-facet
  item limits.
- Reverse lookup is paginated and cursor-bound.
- Graph traversal does not exceed the existing bounded dossier depth unless a
  separately registered recipe defines a stricter explicit path.
- Cache keys include owner revision, relation/context revision and registry
  version. Stale cache entries fail closed or revalidate; they do not become
  canonical truth.

## 14. Canary acceptance — `Côn hoa thị`

The canary must be representable using normal registries and contracts only:

- Component owner: `Côn hoa thị`;
- Brands: Junghans, Gustav Becker;
- Clock-type Classifications: Đồng hồ treo tường, Đồng hồ tủ;
- verified configurations;
- scoped Movement/recognition-family relations;
- Westminster, Trinity and Ave Maria where evidence supports the exact scope;
- Country Classification: Đức;
- eligible Media, Video and Article projections;
- subject-scoped Knowledge claims;
- explicit Dictionary lexical relations for Côn, Gông, Côn chữ M and Côn đồng
  bạch.

The canary must also prove that a Component association does not broaden to
every Specimen, Product, Variant or Article. This review creates no canary
owner, edge, Evidence, lexical relation or projection data.

## 15. Exact planned schema, migration and code seams

The following names are reserved design seams, not existing files or runtime
capabilities:

### 15.1 Graph relation context

Planned migration: `GraphMigration002RelationContext`.

Planned table: `{$wpdb->prefix}nhk_graph_relation_context` with:

```text
id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
context_uuid BINARY(16) UNIQUE NOT NULL
edge_uuid BINARY(16) UNIQUE NOT NULL
source_revision INT UNSIGNED NOT NULL
target_revision INT UNSIGNED NOT NULL
scope_code VARCHAR(64) NOT NULL
scope_subject_type VARCHAR(64) NOT NULL
scope_subject_id VARCHAR(191) NOT NULL
provenance_class VARCHAR(64) NOT NULL
evidence_refs_json LONGTEXT NOT NULL
approval_fingerprint CHAR(64) NOT NULL
idempotency_key VARCHAR(191) UNIQUE NOT NULL
state TINYINT UNSIGNED NOT NULL DEFAULT 1
revision INT UNSIGNED NOT NULL DEFAULT 1
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
retired_at DATETIME(6) NULL
KEY edge_state (edge_uuid,state)
KEY scope_lookup (scope_subject_type,scope_subject_id,state,id)
KEY provenance_lookup (provenance_class,state,id)
```

Planned seams: `GraphRelationContext`, `GraphRelationContextRepository`,
`WpdbGraphRelationContextRepository`, and Graph lifecycle integration in
`GraphService`/the governed relation apply adapter. The edge remains the
semantic relation identity; context is supporting relation metadata only.

### 15.2 Predicate registry

The implementation must increment the executable predicate registry version,
add `associated_with` with the closed whitelist from §4.1, and add a forward
registry migration that inserts the predicate idempotently into
`nhk_graph_predicates`. No registry update may be inferred from a fixture.

### 15.3 Dictionary lexical relations

Planned migration: `DictionaryLexicalRelationMigration025`.

Planned table: `{$wpdb->prefix}nhk_dictionary_lexical_relations` with:

```text
id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
relation_uuid BINARY(16) UNIQUE NOT NULL
source_entry_uuid BINARY(16) NOT NULL
source_sense_uuid BINARY(16) NULL
target_entry_uuid BINARY(16) NOT NULL
target_sense_uuid BINARY(16) NULL
relation_kind VARCHAR(32) NOT NULL
provenance_json LONGTEXT NOT NULL
idempotency_key VARCHAR(191) UNIQUE NOT NULL
state TINYINT UNSIGNED NOT NULL DEFAULT 1
revision INT UNSIGNED NOT NULL DEFAULT 1
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
retired_at DATETIME(6) NULL
UNIQUE KEY active_relation (source_entry_uuid,source_sense_uuid,
  target_entry_uuid,target_sense_uuid,relation_kind)
KEY source_lookup (source_entry_uuid,source_sense_uuid,state,id)
KEY target_lookup (target_entry_uuid,target_sense_uuid,state,id)
```

The repository seam is a Dictionary-owned `DictionaryLexicalRelationRepository`
with `add`, `replace`, `retire`, `reactivate`, bounded forward/reverse reads,
revision checks, idempotency checks and canonical read-back. It must validate
Entry/Sense existence through the existing Entry repository and never resolve
or write Graph endpoints.

### 15.4 Projection and Governance seams

Planned application seams are:

- `SemanticEnrichmentRelationRegistry` for predicate/facet/kind rules;
- `DictionaryRelationFacetRegistry` for public facet definitions;
- `DictionarySemanticEnrichmentQuery` for bounded owner/dossier resolution;
- `GraphRelationContextPolicy` for scope/provenance/Evidence eligibility;
- `DictionaryLexicalRelationGovernanceAdapter` for proposal/apply lifecycle;
- MCP/Admin preview/proposal/read-back adapters using existing capabilities.

These seams must remain orchestration/projection boundaries. They must not
become alternate owners or generic writers.

## 16. Implementation gate

Implementation may begin only after:

1. this contract and all cross-references are included in the canonical
   documentation manifest;
2. Graph relation-context schema and lexical-relation schema are approved;
3. registry hashes and migration versions are specified;
4. Governance operation packets and failure codes are specified;
5. the test plan below is accepted as the implementation gate and tests are
   added before each corresponding runtime seam;
6. a read-only canary audit passes without data mutation before any apply path
   is enabled.

This contract authorizes none of the above runtime changes by itself.

## 16.1 TEST semantic pending lifecycle verification — 2026-10-05

The bounded semantic pending lifecycle is now live-verified on the authorized
TEST runtime through the existing registered Governance path. Verification
covered relation proposal discovery, eligibility, controlled apply, canonical
read-back, exact idempotent replay, retirement, reactivation and final
cleanup-retirement, including the GraphRelationContext lifecycle. The valid
context provenance was `EXPLICIT_USER_KNOWLEDGE`.

This closes the prior semantic pending-acceptance status for TEST only. It does
not claim that the full Dictionary semantic-enrichment design is implemented,
does not authorize migration or production/canary mutation, and does not claim
lexical lifecycle acceptance. The existing registry, scope, provenance,
Evidence, revision, idempotency and Governance boundaries remain mandatory.

## 17. Required implementation test plan

### Contract and registry tests

- `associated_with` accepts exactly the whitelisted source/target pairs.
- Unsupported endpoint types, classification families and self-relations fail
  closed.
- Strong predicates are selected when their semantics match; duplicate weak
  associations are rejected or suppressed.
- Country and clock-type facets enforce their registered Classification family.
- Dictionary relation kinds and Entry/Sense cardinality are registry-bound.

### Storage and repository tests

- Graph relation context round-trips without copied owner payload.
- Relation context is transactionally consistent with edge lifecycle changes.
- Evidence references resolve to canonical Evidence IDs and reject malformed,
  inactive or unavailable Evidence.
- Entry-level and Sense-level lexical relation rows remain distinct.
- Repositories reject labels, titles, URLs, descriptions and claim text in
  relation packets.
- Retired rows remain readable for audit and are excluded from active reads.

### Governance and concurrency tests

- ADD is idempotent for identical packets and conflicts for changed packets.
- REPLACE, RETIRE and REACTIVATE require the exact current revision.
- Stale source/target revisions fail before apply.
- Retired relations are never implicitly resurrected by ADD.
- Approval fingerprint, registry hash, scope and Evidence references are bound
  to the applied packet.
- Canonical read-back proves edge/context identity, state, revision and owner
  revisions after every mutation.

### Scope and provenance tests

- Component-scoped music does not appear as a universal Specimen, Variant,
  Model or Brand fact without an approved path recipe.
- `SYSTEM_INFERENCE` cannot produce a public semantic association by itself.
- Private Evidence never appears in public packets.
- Media observations, Video metadata, Article mentions and lexical wording do
  not become semantic relations without governed approval.
- Knowledge claims remain scoped to their canonical subject.

### Projection and frontend tests

- Dynamic facets are produced only from the registered facet catalog.
- Direct paths beat derived paths; shorter approved paths beat longer paths.
- Target payload, internal IDs, private provenance and unsupported groups are
  omitted from public output.
- Per-facet limits, cursors, deterministic ordering and `has_more` are honest.
- Empty, unavailable, ambiguous, blocked, retired and partial states remain
  distinct.
- Existing legacy Dictionary fields and `/tu-dien/` routes remain compatible.

### MCP/Admin and canary tests

- Only the bounded read/preview/proposal/approval/apply/retire/reactivate
  operations are discoverable after implementation.
- Missing capability, stale documentation checkpoint, missing scope or missing
  Evidence fails closed.
- Generic predicate writers, direct SQL, Dictionary Graph endpoints and hard
  delete are rejected.
- A read-only `Côn hoa thị` audit resolves every requested target category and
  reports missing/ambiguous/unavailable outcomes without writes.
- No test uses a canary-specific production or staging allowlist.
