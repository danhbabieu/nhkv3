# NHK V3 Semantic Dossier Projection Contract

> **NON-NORMATIVE.** This contract is subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md` and the current domain contracts. It authorizes no semantic write, new entity type, predicate, migration or data repair.

Status: owner-directed frontend/read-model contract, 2026-09-06.

## 1. Purpose

The public website must stop treating an Authority row as a complete page. A public page is a read model assembled from already-governed domains:

```text
Authority identity
  + bounded Graph paths
  + Current Knowledge / Evidence / Source
  + Media / MediaAsset / MediaUsage
  + Video
  + native WordPress Article/Post
  + Dictionary lexical projection
  + public eligibility / route / SEO
  = Semantic Dossier
```

`Semantic Dossier` is an application/read-model concept only. It is never persisted as semantic truth and never becomes an Authority entity or Graph endpoint.

## 2. Ownership boundaries

- Authority owns identity and lifecycle.
- Graph owns typed relations and path truth.
- Knowledge owns atomic claims.
- Source/Evidence owns provenance, support, qualification and contradiction.
- Media, MediaAsset and MediaUsage retain their existing distinct ownership.
- Video owns its canonical external reference and approved public projection.
- WordPress owns Article/Post title, body, author, dates, categories and permalink.
- Dictionary owns approved lexical concepts/labels only; lexical matches never establish semantic relations.
- SEO owns public canonical/indexability/schema/sitemap projection.
- Semantic Dossier owns only page assembly, reader-safe grouping and presentation diagnostics.

No template may reconstruct semantic truth from names, slugs, payload fields, taxonomy, post meta, captions, OCR, filenames or visual similarity.

## 3. Data-first audit

Before data repair, a read-only coverage audit must distinguish at least:

- public entity identity/route eligibility;
- Graph direct/derived path coverage;
- subject-scoped public Knowledge coverage;
- public Evidence/Source coverage;
- public MediaUsage/asset coverage;
- public Video path coverage;
- public Article/Post path coverage;
- unresolved registry/identity/availability conflicts.

The audit may report a missing link but cannot infer or create the link. A missing Graph path is not proof that a relation should exist.

## 4. Relation engine

The dossier must consume `RelatedSemanticQuery` for path-aware navigation and must not add presentation metadata to the legacy `RelatedContentQuery` response contract.

`RelatedSemanticQuery` remains bounded to two hops and supplies:

- `DIRECT | DERIVED`;
- hop count;
- best registered path;
- alternative registered paths when present.

Reader-safe projection strips canonical IDs/stable keys and may expose only display title/type, canonical public URL, media/video presentation fields and a path explanation expressed with registered predicate/type labels.

The existing `RelatedContentQuery` remains a compatibility seam for existing public consumers.

## 5. Knowledge and certainty presentation

The dossier groups public Knowledge by the registered `KnowledgeFacetProfile` facets. Private, draft, unverified or otherwise non-public Knowledge/Evidence/Source stays hidden.

The frontend must never invent a numeric confidence score. It may display only evidence states derived from current canonical records, for example:

- sourced public claim;
- public claim with no public Evidence currently projected;
- public qualification present;
- public contradiction present;
- specimen-observation scope;
- infrastructure unavailable.

A warning such as “observation scope only” is allowed only when the claim/profile metadata actually establishes specimen-observation scope. Absence of Evidence alone is not proof of field observation.

## 6. Dossier packet

A public entity dossier has the logical shape:

```text
status
identity
seo_projection
primary_media
knowledge
knowledge_coverage
relation_sections
media_gallery
video_items
article_items
dictionary_terms
warnings
availability
```

`relation_sections` are page-assembly sections, not persisted relations. Every relation item must come from a valid `RelatedSemanticQuery` candidate and pass public eligibility/readiness/route policy.

## 7. Page profiles

All registered Authority types use the same dossier engine but may order sections differently.

### Brand
Identity → representative media → models → variants → movements → music → components → classifications → specimens/products → Knowledge → media/video → Articles → Dictionary → evidence/gap notes.

### Model
Identity → parent Brand context → variants → movements → music/components → recognition/configuration Knowledge → specimens → media/video → Articles → Dictionary.

### Variant
Identity → Model/Brand context → movement/music configuration → components → recognition → specimens → media/video → Knowledge/Evidence → Articles.

### Movement
Identity → direct/derived structural context → variants/models/brands → configuration → movement construction → music/components → recognition → specimen observations → media/video → Knowledge/Evidence → Articles.

### Music / Component / Classification
Identity → actual Graph-backed usage/context → Knowledge → specimens → media/video → Articles. No ownership shortcut is inferred from display context.

### Specimen
Concrete object identity → explicitly linked semantic context → specimen observations → media/video → Knowledge/Evidence → Articles. Specimen observations never automatically promote upward.

### Product
Listing identity → only registered/explicit Graph context → public media → Articles. Product–Specimen remains fail-closed until a dedicated approved relation exists.

## 8. Article/Post profile

Article remains native WordPress editorial truth. The page may consume a dossier-style context projection for:

- direct semantic entities;
- derived semantic context;
- canonical MediaUsage gallery;
- related Video;
- related Articles through Graph only;
- approved Dictionary terms occurring lexically in the rendered text.

The article body is never rewritten by the dossier except the separately contracted Dictionary render-time linker.

## 9. Images and fallback

Real public media is preferred whenever eligible. A theme fallback image is presentation-only and:

- is not Media;
- is not Evidence;
- is not a Graph relation;
- is not a preferred SEO image;
- must not enter image sitemap/structured-data ownership.

All article cards and dossier cards may use the presentation fallback when no real eligible image exists so layout remains stable.

## 10. Frontend UX

The design is Vietnamese-first, editorial and collection/archive oriented: warm neutral surfaces, restrained wood/brass accents, large useful imagery, compact contextual side rails, strong reading typography and mobile-first responsive behavior.

The homepage is a discovery gateway that surfaces real content across editorial posts, Authority dossiers, Media, Video, Knowledge and Dictionary. It must preserve all existing visitor-facing discovery routes and comparison entry points.

Templates consume dossier/read-model packets and must not query semantic tables or implement their own Graph traversal.

## 11. SEO

Semantic Dossier does not own canonical identity. Each page continues to consume the existing public SEO projection.

- canonical/indexability follows current route/SEO policy;
- fallback presentation images never become SEO preferred images;
- real Media image projection may feed OG/schema only through existing eligible image policy;
- direct/derived related links are internal discovery links, not new canonical ownership;
- no duplicate Dictionary detail competes with an existing canonical owner.

## 12. Performance

- Graph traversal remains bounded to two hops and bounded limits.
- Dossier assembly must avoid per-card full dossier queries on archives.
- Archives use lightweight collection projections; full dossier assembly is detail-only.
- Page-level memoization is allowed in the projection layer; no new cache persistence is authorized here.

## 13. Cleanup / compatibility

The rollout must preserve:

- `RelatedContentQuery` legacy result shape;
- `PublicEntityCollectionQuery` as the shared discovery boundary;
- existing public route/canonical/SEO contracts;
- Media readiness and Video reference gates;
- Media detail rendering where an existing route requires it;
- native WordPress permalink ownership;
- Brand aggregation compatibility until the shared dossier sections fully replace it without duplicate presentation.

Temporary feature CI may be removed only after equivalent repository verification evidence is recorded.

## 14. Acceptance

A compliant implementation proves at minimum:

1. One dossier engine serves every registered Authority type.
2. Movement/Model/Variant pages can expose valid two-hop context without persisted shortcut edges.
3. Legacy `RelatedContentQuery` exact result shape remains unchanged.
4. Direct wins over derived for the same target; no three-hop output.
5. Reader-safe path explanation contains no canonical UUID/stable key.
6. Knowledge is grouped by registered facet/scope and public gates.
7. Contradiction/qualification/evidence coverage is visible without invented confidence percentages.
8. Real related Media images and Video thumbnails render when eligible.
9. Article page keeps WordPress body/permalink truth while adding dossier context.
10. Brand does not duplicate generic Authority relations already represented by its grouped dossier sections.
11. Archives remain lightweight and every card has either real public media or presentation fallback.
12. Media detail and existing route contracts remain functional.
13. Homepage retains all current public discovery routes and surfaces Media, Video, Knowledge and Dictionary.
14. Public canonical/indexability remains controlled by existing SEO projection.
15. Read-only coverage audit distinguishes missing data/path from unavailable infrastructure.
16. No data repair occurs without a separately governed proposal and read-back.
