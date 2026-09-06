# Brand Public Dossier Design

Status: implementation design for the six-step Brand projection work.
Date: 2026-09-06

## Goal

Make one Brand detail page expose the public information already present across Authority, Graph, Knowledge, Evidence, Source, Media, Video, and WordPress Article stores without changing semantic ownership or inventing shortcut relations. Brand is the first complete implementation; the read-model pattern must be reusable by Model, Movement, Variant, Music, and other entity pages later.

## Architectural boundary

Authority, Graph, Knowledge, Media, Video, and WordPress remain canonical owners. The new work is read-only projection. A child claim never becomes a Brand claim merely because the child is structurally related to the Brand. The projection may surface derived context, but every derived item carries its origin path and scope.

The public page consumes one dossier-shaped read model. The template must not independently discover semantic relations or merge claims.

## External design evidence

The design follows three useful outside constraints:

1. Schema.org `isVariantOf` describes a local variant relationship and explicitly states that it is not transitive. NHK therefore must not generalize arbitrary descendant facts to a Brand.
2. Google recommends exposing as many structured properties as are relevant to a page while keeping markup aligned with what the page actually describes. Public structured data must therefore be generated from the same resolved dossier, not from hidden or broader graph state.
3. Bounded graph-path patterns are preferable to unbounded traversal for predictable semantics and cost. Brand projection therefore uses registered, typed path recipes instead of increasing generic BFS depth.

References:
- https://schema.org/isVariantOf
- https://schema.org/ProductModel
- https://developers.google.com/search/docs/appearance/structured-data/organization
- https://neo4j.com/docs/cypher-manual/current/patterns/reference/variable-length-paths/
- https://www.w3.org/TR/shacl/

## Current root cause

The repository already contains `SemanticDossierQuery`, `SemanticProfileComposer`, `EntityKnowledgeProjection`, `EntityMediaProjection`, and a dossier-aware `entity.php` template. The public runtime does not currently construct and attach `SemanticDossierQuery` to entity detail payloads. `PublicEntityCollectionQuery` also has an optional `EntityKnowledgeProjection` dependency but runtime construction leaves it null.

`BrandAggregationQuery` currently produces Model and Variant aggregation plus direct `about` neighbors, but its declared `knowledge`, `media`, `videos`, `sources`, and `evidence` buckets remain empty. It also cannot reach valid structural paths such as Brand <- Model <- Variant -> Movement because those paths exceed the generic dossier reader's two-hop bound.

The template additionally suppresses Brand relation sections for structural entity groups because it expects legacy `aggregation` to own those groups. This prevents dossier relations from being rendered even if they are available.

## Design choice

Use the existing `SemanticDossierQuery` as the canonical detail read model and add one focused Brand path-recipe reader for structural aggregation. Do not create a second Brand page subsystem.

### Components

1. `BrandAggregationQuery`
   - remains the Brand-specific structural recipe reader for compatibility;
   - expands from Model/Variant-only traversal to the approved explicit path set;
   - returns only public resolvable entities;
   - records `origin.kind`, `origin.hop_count`, and ordered predicate path;
   - never creates Graph edges or semantic ownership shortcuts.

2. `SemanticDossierQuery`
   - remains the unified dossier assembler;
   - accepts optional `BrandAggregationQuery`;
   - for Brand entities, merges Brand recipe relations into dossier relation sections using the same direct-before-derived deduplication rules;
   - keeps direct Brand Knowledge separate from descendant Knowledge.

3. `PublicEntityCollectionQuery`
   - receives optional `SemanticDossierQuery` and attaches `dossier` only on detail reads;
   - retains legacy `knowledge`, `media`, and `aggregation` fields during transition for compatibility;
   - archives remain lightweight and do not execute full dossier queries.

4. `Plugin`
   - wires the complete read stack in dependency order: Graph, routes/eligibility, media repositories, knowledge repositories, `EntityKnowledgeProjection`, `EntityMediaProjection`, `RelatedSemanticQuery`, `BrandAggregationQuery`, `SemanticDossierQuery`, `PublicEntityCollectionQuery`;
   - no migrations or writes are added.

5. `entity.php`
   - uses dossier/profile as the primary detail source;
   - stops deleting Brand structural relation sections;
   - avoids duplicate rendering by preferring dossier relation sections and using legacy `aggregation` only as fallback;
   - labels direct versus derived context and renders the origin path when available.

## Brand path recipes

Only registered and approved predicates are traversed. Every recipe starts from a public Brand and follows stored edge direction semantically, even though reads may use incoming lookups.

### Structural recipes

- Model: `Model --model_of--> Brand`
  - displayed as direct structural child of Brand;
  - origin predicates: `[model_of]`.

- Variant: `Variant --variant_of--> Model --model_of--> Brand`
  - displayed as derived Brand context;
  - origin predicates from Brand perspective: `[model_of, variant_of]`.

- Movement: `Variant --uses_movement--> Movement` where Variant belongs to a Model of the Brand
  - displayed as derived;
  - origin predicates: `[model_of, variant_of, uses_movement]`.

- Music by Variant configuration: `Variant --configured_with_music--> Music`
  - displayed as derived configuration context;
  - origin predicates: `[model_of, variant_of, configured_with_music]`.

- Music by Movement capability: `Variant --uses_movement--> Movement --supports_music--> Music`
  - displayed as derived capability context;
  - origin predicates: `[model_of, variant_of, uses_movement, supports_music]`.

### Direct contextual recipe

Active `about` edges directly touching the Brand may expose public Authority entities, Media, Video, and WordPress Article endpoints through the generic dossier reader. They are not treated as structural ownership.

### Non-recipes

No Brand -> Movement, Brand -> Music, Brand -> Component, Brand -> Media, or Variant -> Brand shortcut is persisted merely to accelerate display. No keyword matching counts as a relation. Specimen observations are not promoted to Variant, Model, or Brand facts.

## Knowledge and evidence rules

Direct Brand Knowledge is selected only when a public claim's metadata `subject_id` equals the Brand canonical ID. Evidence and Source are shown through the existing public Evidence projection. Descendant Knowledge is not copied into the Brand Knowledge facet list.

The Brand page may link to child dossiers and summarize relation coverage, but the child page remains owner of its claims.

## Media rules

`primary_media` and direct gallery remain subject-scoped through MediaUsage. Derived media may appear in relation sections only when an explicit graph relation exposes it. The implementation does not recursively scrape all descendant MediaUsage records into one Brand gallery because that would erase scope.

## Video and Article rules

Direct or path-derived Video and Article items are rendered from dossier relation sections. Video canonical URL remains owned by `VideoUrlPolicy`; Article permalink remains owned by WordPress. The Brand dossier does not manufacture URLs.

## Projection output

Brand detail payload must contain:

- `dossier.status = AVAILABLE` when public route, graph, and direct entity requirements are satisfied;
- `dossier.identity` with public payload and canonical public URL;
- `dossier.knowledge` with direct public Brand claims and evidence;
- `dossier.primary_media` and `dossier.media_gallery`;
- `dossier.relation_sections.models`;
- `dossier.relation_sections.variants`;
- `dossier.relation_sections.movements` when approved paths exist;
- `dossier.relation_sections.music` when approved configuration/capability paths exist;
- direct `articles`, `media`, `videos`, and other public relation sections when Graph exposes them;
- `origin` metadata on each relation item;
- `coverage`, `availability`, and `warnings`.

No internal canonical UUID, stable key, revision, or lifecycle field may leak into the public profile.

## Frontend behavior

The Brand page order is:

1. identity / hero;
2. direct Brand facts;
3. direct Brand Knowledge and Evidence;
4. structural Models;
5. Variants grouped in relation output order;
6. related Movements;
7. Music context;
8. direct Media gallery;
9. Videos;
10. Articles;
11. source/evidence context already attached to Knowledge;
12. navigation.

The first implementation does not add a separate bibliography query because existing Evidence already carries public Source title/type/locator. A later generalized dossier may add a deduplicated bibliography without changing semantic ownership.

## Error handling and safety

- Missing graph storage: dossier returns graph warning; identity and direct Knowledge/Media may still render where safe.
- Invalid/inactive/private child entity: omit it from relation sections.
- Duplicate entity reachable by several paths: keep the best direct path first; otherwise keep the shortest approved path and retain no hidden identity in the public packet.
- Relation recipe failure never writes repairs.
- Missing Evidence produces existing public warning; it does not fabricate a citation.
- Generic BFS remains bounded at two hops; Brand completeness comes from explicit recipes, not by raising the global traversal bound.

## Acceptance criteria

A fixture Brand with one Model, one Variant, one Movement, two Music paths, one direct Brand claim with Evidence/Source, one representative image, one direct Video, and one Article relation must produce a detail dossier where:

- Model count = 1;
- Variant count = 1;
- Movement count = 1;
- Music deduplicates to the expected unique count while preserving the best origin;
- direct Brand claim count and Evidence count equal repository fixture counts;
- primary Media is present;
- Video/Article counts equal graph fixture counts;
- no child claim appears inside Brand direct Knowledge facets;
- no internal UUID/stable key leaks into the serialized public profile;
- frontend contract no longer suppresses Brand dossier structural groups;
- archives do not invoke the heavy dossier path.

## Non-goals

- no semantic backfill;
- no Graph mutation;
- no database search/replace;
- no new public entity type;
- no global traversal depth increase;
- no automatic inheritance of descendant claims;
- no redesign of non-Brand pages beyond making the dossier wiring reusable.
