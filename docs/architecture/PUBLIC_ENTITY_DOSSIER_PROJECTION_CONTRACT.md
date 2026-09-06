# NHK V3 Public Entity Dossier Projection Contract

> **CURRENT APPROVED PROJECTION CONTRACT — subordinate to the Constitution.**
> This contract defines read-only public dossier assembly. It does not authorize
> semantic mutation, Graph repair, identity migration, new predicates, or data
> backfill. If it conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the
> Constitution controls.

Status: implemented for the shared Entity dossier seam with Brand as the first
complete path-recipe projection, 2026-09-06.

## 1. Purpose

A public Entity page must be able to expose the information already stored
across the canonical NHK owners without copying that information into a second
semantic store. The detail page therefore consumes one dossier-shaped read
model assembled from Authority identity, direct Knowledge/Evidence/Source,
MediaUsage/MediaAsset, Graph relations, Video, and WordPress Article projection.

The dossier is a projection only. Authority, Graph, Knowledge, Source, Evidence,
Media, Video, and WordPress remain the owners of their respective truth.

## 2. Detail seam and performance boundary

Entity detail enrichment uses the existing
`nhk_v3_entity_detail_projection` hook exposed by `EntityPageQuery`. The runtime
bootstrap constructs the dossier readers and attaches `dossier` on detail reads.

Archive/list queries remain lightweight. They must not execute the full dossier
assembler merely to render collection cards. Archive projection continues to
use the existing public collection/card read model.

The shared generic relation reader remains bounded to two hops. A type that
needs a longer semantically meaningful path must define an explicit typed recipe
rather than raising the global traversal bound.

## 3. Public dossier shape

When available, a public Entity dossier contains:

- `identity` — public Authority identity/payload and resolved canonical URL;
- `seo_projection` — canonical/indexability/internal-link projection;
- `primary_media` — direct representative MediaUsage for the subject;
- `media_gallery` — direct public gallery MediaUsage for the subject;
- `knowledge` — public claims whose `subject_id` is exactly the subject Entity;
- `relation_sections` — public, resolvable direct and derived Graph context;
- `coverage` — counts derived from the emitted public dossier;
- `warnings` / `availability` — read-time projection state;
- `profile` — reader-safe ordering/deduplication packet for templates.

Canonical UUID, stable key, lifecycle, state, and revision are internal identity
and must not leak into the public profile.

## 4. Knowledge scope rule

Direct Knowledge on an Entity page is selected only when the public claim's
metadata `subject_id` equals that Entity's canonical identity. Evidence and
Source are projected through the existing public Evidence boundary.

A claim owned by a Model, Variant, Movement, Music, Specimen, Media, Video, or
other child/related subject does not become a claim of the Brand merely because
that subject is reachable from the Brand.

Derived pages may expose links/context to child dossiers, but they must preserve
the child's ownership and relation path. Observation scope must never be silently
promoted to Variant, Model, or Brand scope.

## 5. Direct versus derived relations

Each relation item must retain public origin metadata sufficient for the reader
to distinguish direct context from derived context:

- `origin.kind`: `DIRECT` or `DERIVED`;
- `origin.hop_count`: number of approved path edges;
- `origin.predicates`: ordered semantic path from the public subject's reading
  perspective;
- `origin.via_types`: intermediate public Entity types when useful for display.

If the same public item is reachable through several paths, a direct path wins.
Otherwise the shortest approved path wins. A longer alternative path must not
create a duplicate card or broaden semantic scope.

## 6. Brand structural recipe

Brand is the first complete typed recipe implementation. The following paths
are approved for read projection because every edge is already registered and
has an explicit semantic meaning.

### 6.1 Model

Stored edge:

`Model --model_of--> Brand`

Public Brand origin:

`[model_of]`

The Model is a direct structural child of the Brand.

### 6.2 Variant

Stored path:

`Variant --variant_of--> Model --model_of--> Brand`

Public Brand reading path:

`[model_of, variant_of]`

The Variant is derived Brand context. No `Variant -> Brand` shortcut may be
persisted for display acceleration.

### 6.3 Movement

Stored path:

`Brand <-model_of- Model <-variant_of- Variant -uses_movement-> Movement`

Public Brand reading path:

`[model_of, variant_of, uses_movement]`

The Movement is derived configuration context. It does not acquire Brand
ownership.

### 6.4 Music from documented Variant configuration

Stored path:

`Brand <- Model <- Variant -configured_with_music-> Music`

Public Brand reading path:

`[model_of, variant_of, configured_with_music]`

This means a documented Variant configuration exists. It must not be rewritten
as a universal Brand capability.

### 6.5 Music from Movement capability

Stored path:

`Brand <- Model <- Variant -uses_movement-> Movement -supports_music-> Music`

Public Brand reading path:

`[model_of, variant_of, uses_movement, supports_music]`

This means a related Movement has the documented capability. It must not imply
that every Variant, Model, Specimen, or Brand product uses that Music.

### 6.6 Direct contextual relations

Registered active `about` relations directly touching the Brand may expose
public Authority entities, Media, Video, or WordPress Article endpoints through
the generic dossier reader. `about` is contextual, not structural ownership.

Keyword similarity, matching names, payload text, shared images, or external IDs
do not count as relations.

## 7. Media rule

`primary_media` and `media_gallery` remain subject-scoped MediaUsage projection.
A Brand gallery therefore contains Media directly assigned to that Brand unless
an explicit Graph relation separately exposes another Media item in a relation
section.

The implementation must not recursively scrape every descendant's MediaUsage
into one Brand gallery, because doing so erases the distinction between direct
Brand media and media belonging to a Model, Variant, Movement, or Specimen.

## 8. Video and Article rule

Video and Article items may appear when an approved Graph path exposes them.
Their public URL ownership does not move into the dossier:

- Video canonical/internal URL remains owned by the current Video URL/SEO
  projection contract;
- WordPress Article permalink remains owned by WordPress editorial state.

The dossier consumes those resolved URLs and must not independently manufacture
or slugify them.

## 9. Frontend consumption

`entity.php` treats `dossier.profile` / `dossier.relation_sections` as the primary
detail source. Legacy Brand `aggregation` remains a compatibility fallback only
when no available dossier exists. It must not be rendered in parallel with the
same dossier structural sections.

Reader labels must distinguish direct context from derived context. Where an
intermediate path is useful, the frontend may display `via_types` but must not
expose internal IDs.

## 10. Availability and failure behavior

- Invalid, inactive, private, or publicly ineligible child entities are omitted.
- Graph unavailability produces dossier warnings; it never authorizes inferred
  shortcut relations.
- Missing public Evidence is reported through existing Knowledge coverage and
  warning states; no citation is fabricated.
- Missing descendant context does not trigger semantic writes or repair.
- Dossier assembly is read-only and may fail closed on an unavailable public
  route.

## 11. Reusable recipe pattern for other entity types

Future Model, Movement, Variant, Music, Component, Classification, Specimen, and
Product dossier expansion must follow the same pattern:

1. keep direct subject Knowledge subject-scoped;
2. define exact registered path recipes for longer derived context;
3. preserve path provenance and scope;
4. resolve only public eligible targets;
5. deduplicate direct before derived, then shortest approved path;
6. never persist a shortcut solely to make frontend projection easier;
7. keep archives outside the heavy detail-dossier path.

A new path is not approved merely because a graph traversal can technically
reach it. If the path has no current contract/registered semantic meaning, stop
and extend the semantic contract through the proper governance process first.

## 12. Acceptance standard

The Brand acceptance fixture must prove, in one assembled dossier, that existing
canonical stores can produce Model, Variant, Movement, Music, direct Brand
Knowledge with Evidence/Source, representative Media, Video, and Article
context while all of the following remain true:

- descendant Knowledge is not promoted to Brand Knowledge;
- duplicate path targets collapse deterministically;
- every structural descendant retains origin metadata;
- public profile contains no internal UUID/stable key;
- legacy aggregation does not duplicate dossier structural rendering;
- generic graph traversal remains bounded at two hops;
- no canonical store is mutated by dossier assembly.
