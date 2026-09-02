# NHK V3 Entity Hubs and Brand Graph Design Specification

Status: approved design specification, read-only checkpoint, 2026-09-02.

Implementation status — 2026-09-02: The approved route, public-query,
Graph-registry and read-only diagnostics contract is implemented in the current
working tree. `PredicateRegistry` contains all six approved definitions with
the exact endpoint/cardinality boundaries; existing physical Graph rows remain
untouched. Earlier paragraphs describing those definitions as future registry
gaps are historical design-stage context and are superseded by this status.

This specification turns the approved public discovery and structural Graph
design into implementation boundaries. It does not authorize physical Graph
repair, semantic-data mutation, database replacement, seed/import/recreation,
V2 mutation, or legacy article-body migration/import/parsing.

## 1. Public information architecture

The final public hub matrix is maintained in
`docs/architecture/V3_PUBLIC_HUB_MATRIX.md`. Vietnamese discovery hubs are the
canonical visitor destinations:

```text
/thuong-hieu/  /mau/       /bo-may/     /ban-nhac/
/linh-kien/    /phan-loai/ /hien-vat/   /san-pham/
/tri-thuc/     /video/     /so-sanh/
```

Brand details use a bare first-level slug. Model and Variant details retain
their parent context in the path. Movement, Music, Component, Classification,
Specimen and Product use their Vietnamese namespaces. Variant has no global
hub by default; it is discovered through Model/Brand context. Comparison is a
query-driven surface over two public Authority references and has no persisted
comparison identity.

The public menu is domain-led, not registry-led:

```text
Tri thức · Thương hiệu · Mẫu · Bộ máy · Bản nhạc · So sánh ·
Linh kiện · Hiện vật · Video
```

Góc chia sẻ remains conditional on a real editorial section. Classification
is not a primary-menu item by default. Product is not a primary-menu item
merely because Product is registered; it enters navigation only when the real
public commerce/product section is intended to exist.

Technical hubs `/brand/`, `/model/`, `/movement/`, `/music/`, `/component/`,
`/specimen/`, `/product/` and `/comparison/` are compatibility inputs only.
Where a legacy route is approved for retention, it performs one 301 directly
to the matrix destination. It must not be linked from the menu, canonical
metadata, breadcrumbs or collection cards. Ambiguous identity or parent
resolution fails closed instead of redirecting by guess.

## 2. Shared public collection contract

`PublicEntityCollectionQuery` becomes the single Authority collection boundary
for homepage modules, hubs/archives, public search, REST collections and cards.
It composes these existing or explicitly approved boundaries:

```text
AuthorityRepository
PublicEntityEligibilityPolicy
PublicIdentityContract
PublicRouteResolver
EntityTypeRegistry
```

The query accepts a registered Authority type, page, bounded page size and
optional search term, and returns a read model with the type, normalized page
parameters, total eligible count, items and each item's canonical public URL
when routeable. It must use the same identity and eligibility result for
membership, URL emission, totals and detail resolution. A route-less active
row is not silently serialized as a public card.

The query must not add a persistence path or a new semantic field. It uses
`EntityTypeRegistry` for allowed public payload fields, `PublicIdentityContract`
for canonical display identity and slug/alias decisions, and
`PublicEntityEligibilityPolicy` for active/public/structural readiness. The
policy returns a bounded eligible/ineligible result with existing reason
classifications; it does not invent readiness fields. Templates render the
read model and do not repeat state filters, slug generation, routeability,
parent resolution or eligibility logic.

Knowledge, Media and Video retain their existing public query services because
their identity and publication contracts are different. They must still use
their existing active/public/readiness gates and canonical routes, and the
homepage must consume those read-only modules without inventing data.

## 3. Exact current disappearance diagnosis

The disappearance is not a storage repair task. The current data is present in
the Authority/runtime evidence; the failure is **DISCOVERY/ROUTING CONTRACT
DIVERGENCE**.

The current homepage semantic module filters active records and requires
`PublicRouteResolver::path()`. Its visible quick links in
`public/wp-content/themes/nhk-v3/front-page.php`, fallback menu in
`functions.php` and sidebar still target technical namespaces such as
`/brand/`, `/model/`, `/movement/`, `/music/`, `/component/` and `/specimen/`.
The current `PublicEntityRoutes` archive branch likewise sends visitors to
`EntityPageQuery::archive()` under `/{type}/`, while the resolver emits
Vietnamese namespaces for most detail routes and parent-aware bare Brand/Model/
Variant details. The three consumers therefore disagree about the canonical
destination and about whether routeability is part of public collection
eligibility.

The fix is shared query/route/eligibility wiring and canonical-link updates.
It is not seed/import/recreation, payload-parent rewriting or Graph backfill.

## 4. Brand backbone

The structural backbone is:

```text
Brand ← model_of ← Model ← variant_of ← Variant
```

Only child-to-parent direct structural facts are persisted:

```text
Model   --model_of-->   Brand
Variant --variant_of--> Model
```

`model_of` has exactly one active canonical Brand parent per Model and many
Models per Brand. `variant_of` has exactly one active canonical Model parent
per Variant and many Variants per Model. Brand-to-Model and Model-to-Variant
views are reverse Graph queries. Variant-to-Brand is the two-edge derived path,
never a stored shortcut.

The approved relationship matrix, including Movement and the three distinct
Music scopes, is maintained in
`docs/architecture/V3_BRAND_RELATIONSHIP_MATRIX.md`.

The two structural predicates are currently `REGISTRY_GAP`s because the
runtime `PredicateRegistry` registers only `about` and `depicts`. The future
registry change may add only the explicitly approved definitions, reusing
`PredicateDefinition`, `GraphService`, the endpoint registry and the existing
repository/audit path. No free-form predicate, reverse predicate, special
relation table, JSON relation rule or direct persistence shortcut is allowed.

## 5. Movement and Music scope

`uses_movement` is Variant → Movement and means the Variant is
documented/configured to use that reusable Movement. Movement is not copied
per Brand and does not acquire a fake Brand parent.

The three Music facts are intentionally distinct:

* `supports_music`: Movement → Music, a Movement-scope technical/documentary
  capability.
* `configured_with_music`: Variant → Music, a Variant-scope configuration or
  offered feature.
* `observed_playing_music`: Specimen → Music, an observation about one concrete
  physical object.

No fact is inferred from rod count, hammer count, Brand, case style or visual
similarity. Movement support does not prove that every Variant or Specimen
plays the Music. A Specimen observation is never promoted to Variant or Model
configuration without evidence and Governance.

All four relation requirements remain `REGISTRY_GAP`s until registered. The
implementation plan must therefore add contract tests and registry definitions
as a separate Graph phase after public discovery; it must not write candidate
edges in that phase.

## 6. Component, Classification, Specimen and Product boundaries

Component is a reusable technical component or assembly with semantic
identity, independently recognizable and reusable across Models, Variants or
Specimens. A descriptive adjective alone is not a Component.

Classification is a reusable descriptive/configuration category, such as a
domain-supported case form, dial style, material category, display arrangement
or technical configuration class. It is not a physical identity and does not
require Brand ancestry. The implementation may use only categories supported
by actual domain evidence and the active registry; it may not create generic
predicates merely to make a display group.

Specimen is a concrete physical object. Product is a listing/offer. A Product
cannot replace a Specimen identity, and no Product-to-Specimen or Brand
shortcut is inferred without a registered, evidenced relation.

## 7. Public eligibility and transitional compatibility

Brand is eligible by identity and public rules. During transition, Model and
Variant may remain public when their payload parent evidence is clear, unique,
valid and resolves to the required active ancestor. This is a compatibility
allowance, not canonical Graph truth, and the result carries an internal
`DATA_COMPATIBILITY_GAP` warning.

Missing, malformed, inactive or unresolved parent evidence blocks structural
completeness as `STRUCTURAL_PARENT_MISSING`. Conflicting or multiple parent
evidence blocks it as `STRUCTURAL_PARENT_AMBIGUOUS`. If code treats
`brand_uuid` or `model_uuid` as canonical Graph ownership, that behavior is a
`CONSTITUTION_CONFLICT` and cannot be silently preserved as the target
architecture.

After a separately approved governed Graph cutover, the canonical source for
structural reads becomes the active `model_of`/`variant_of` edges. The cutover
must not happen implicitly as a side effect of a route or query change.

Independent shared entities—Movement, Music, Component and Classification—do
not require Brand. Their absence from a Brand path is not an eligibility error.

## 8. Brand aggregation read model

Brand pages are query aggregations, not ownership stores. They may include the
Brand identity, Models, Variants, Movements, Music, Components,
Classifications, Knowledge, Media, Video, Specimens, Products and
Source/Evidence presentations when each item is independently eligible.

Each aggregated item carries a relation-origin label when practical:

```text
DIRECT
DERIVED: Variant → variant_of → Model → model_of → Brand
DERIVED: Variant → uses_movement → Movement → supports_music → Music
```

The path explains why the item appears and prevents Brand-page presence from
being misread as Brand ownership. No transitive shortcut edge is persisted for
faster display.

## 9. MediaAsset, Source and Evidence visibility

MediaAsset is a binary/derivative resource. It can be retrieved to render an
approved page, but it is not a standalone semantic public entity page by
default. No `/media-asset/{uuid}/` indexable route is created. Media owns
semantic meaning and relations; delivery remains behind the existing active,
ready, visibility, containment, MIME, size and checksum checks.

Source and Evidence are provenance objects. They have no standalone public
indexable page by default. They may be shown inside Knowledge, Brand, Model,
Variant, Movement, Music and other eligible semantic pages through the existing
reader-safe public policy. Raw internal Source/Evidence endpoints are not SEO
pages. A dedicated source archive requires a separate public-projection law.

## 10. Graph audit and future governed repair

Before any future Graph repair, run a read-only distribution audit with these
columns:

```sql
SELECT source_node.endpoint_type AS source_type,
       predicate.predicate_key AS predicate,
       target_node.endpoint_type AS target_type,
       COUNT(*) AS edge_count
FROM wp_nhk_graph_edges AS edge
JOIN wp_nhk_graph_nodes AS source_node ON source_node.id = edge.source_node_id
JOIN wp_nhk_graph_predicates AS predicate ON predicate.id = edge.predicate_id
JOIN wp_nhk_graph_nodes AS target_node ON target_node.id = edge.target_node_id
WHERE edge.state = 1
GROUP BY source_node.endpoint_type, predicate.predicate_key,
         target_node.endpoint_type
ORDER BY source_node.endpoint_type, predicate.predicate_key,
         target_node.endpoint_type;
```

The table prefix is resolved from the active WordPress installation; the SQL
above is illustrative of the read-only grouping and must not be run against a
production database without the approved runtime connection. The current
checkpoint has known aggregate evidence of 189 Graph nodes, 241 edges and two
registered predicates. The local WordPress database was unavailable during
this checkpoint, so the exact 241-row source/predicate/target matrix is
`UNVERIFIED`; no distribution is fabricated from the total.

Physical repair, if approved later, follows this immutable sequence:

```text
discover candidate
→ inspect evidence
→ proposal
→ human approval
→ eligibility
→ Controlled Apply
→ Graph
→ durable audit
```

No bulk edge creation, payload-parent change, synthetic relationship data,
database replacement or V2/live mutation is part of this specification.

## 11. MCP and stale-route findings

The runtime catalog exposes 19 tools. The additional tool beyond the stale
18-tool expectation is `nhk.semantic.resolve`, a read-only resolver added by
commit `3c41bda` (`feat: expose MCP semantic context resolver`). It is
intentional and governed=false; it resolves Authority context by UUID, stable
key or exact name/alias and returns ambiguity candidates without mutation.
The stale count appears in the guarded assertions in
`McpTransportIntegrationTest`; the implementation plan updates that contract
to assert the actual catalog names and intentional count.

The stale frontend route expectations are in the fallback navigation,
homepage/sidebar links, `PublicEntityRoutes`, `PublicComparisonRoutes`,
`tools/frontend-route-smoke.php` and string-based frontend contract tests.
They currently treat technical namespaces as canonical or successful archive
destinations. Phase A converts canonical route assertions to the matrix and
turns retained technical paths into one-hop redirect assertions, only after
the canonical route behavior is proven. Existing V2 migration fixtures that
assert an internal target path are migration-bound tests and are not changed
by the public menu decision unless their approved target contract changes.

## 12. Gap register and human gates

| Gap or decision | Classification | Current treatment |
|---|---|---|
| `model_of`, `variant_of`, `uses_movement`, `supports_music`, `configured_with_music`, `observed_playing_music` | Registered contract | Exact definitions are now in `PredicateRegistry`; no physical edges are written by this implementation |
| Existing 241 edges lack a verified source/predicate/target distribution in this workspace | `DATA_COMPATIBILITY_GAP` / evidence unavailable | Run the read-only distribution audit when the local database is available |
| Payload parent fields currently drive Model/Variant route resolution | `CONSTITUTION_CONFLICT` if treated as canonical Graph truth; `CODE_GAP` for the missing shared structural policy | Keep as transitional evidence until an approved Graph-backed cutover |
| Archive/list/detail/route consumers do not share one public eligibility result | `CODE_GAP` / `PUBLIC_ELIGIBILITY_FAILURE` | Implement `PublicEntityCollectionQuery`, identity contract and policy in Phase A |
| Persisted public slug/history contract is absent | Existing `SLUG_CONTRACT_FAILURE` | Preserve current read-only evidence; add only an approved contract in a later scope |
| Physical backbone edges are absent or unverified | `DATA_COMPATIBILITY_GAP` | No repair; future governed proposal flow only |
| Brand aggregation membership versus ownership needs visitor-safe path labels | `CODE_GAP` | Implement derived read-model explanations without shortcut edges |
| Public Product navigation/commerce intent | Human decision | Keep Product out of primary menu until the actual section is approved |
| Publication/privacy for MediaAsset, Source and Evidence | Human decision | Keep standalone pages unavailable and public reads fail closed by existing policy |
| Case-level identity, provenance and retirement choices | Human decision | Resolve deterministic evidence first; human review remains for ambiguous cases |

The next implementation checkpoint is the public discovery phase. The
physical Graph repair gate remains out of scope and must not be smuggled into
route, registry or query work.
