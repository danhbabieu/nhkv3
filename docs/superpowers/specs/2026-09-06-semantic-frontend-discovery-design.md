# NHK V3 Semantic Frontend Discovery Design

Status: proposed design, 2026-09-06

## 1. Goal and scope

Turn the NHK V3 public theme into a reader-facing discovery layer over governed
Authority, Graph, Knowledge/Evidence/Source, Media, Video and native WordPress
editorial data. The first vertical slice covers the homepage and representative
Brand, Movement and Variant profiles. The same read-model boundaries must remain
usable by Model, Music, Component, Knowledge, Article, Media and Video pages.

This work is projection-only. It does not create semantic records, repair graph
edges, migrate article bodies, assign identities, or treat WordPress taxonomy as
Authority.

## 2. Audit findings

The repository already contains the principal read boundaries:

- `HomeSemanticQuery` for bounded homepage modules;
- `SemanticDossierQuery` for detail-only entity assembly;
- `RelatedSemanticQuery` for bounded, path-aware Graph navigation;
- Knowledge, Media, Video and SEO projections;
- theme templates for homepage, entity, article, media and video routes.

The current root cause of thin public pages is the seam between these boundaries
and the theme: homepage modules are small and loosely shaped, while entity
templates expose a generic payload/fact list instead of composing entity-specific
semantic profiles. The local database and HTTP runtime were unavailable during
the audit, so live Odo coverage remains an environment-gated verification item,
not an assumption.

## 3. Design principles

The data flow is:

`canonical owners → bounded read queries → public projection → theme composition → SEO`

Projection is deterministic, relation-driven, evidence-aware, public-eligibility
checked and deduplicated. Templates never query raw tables or infer relations
from names, slugs, taxonomy, captions, OCR, filenames or visual similarity.

Empty or unavailable dependencies remain distinguishable. Empty sections disappear;
unavailable data produces an honest, concise state. The UI never fabricates
confidence scores, counts, facts, URLs or media roles.

## 4. Read-model architecture

Reuse `SemanticDossierQuery` as the detail assembly boundary and evolve its
reader-safe output only where the existing contract is insufficient. Add a small
profile composition layer that maps registered entity types to ordered section
definitions. It owns presentation grouping and limits, not semantic truth.

The public profile shape is:

```text
identity
hierarchy
relation_sections
knowledge
media_gallery
primary_media
articles
navigation
coverage
availability
warnings
seo_projection
```

Each relation item must retain its direct/derived origin and bounded path
explanation. Knowledge sections group existing public claims by registered facet;
evidence summaries expose only states supported by canonical records. Media uses
the existing representative/usage projection. Video uses poster metadata and
deferred external embeds. Articles remain native WordPress truth.

The homepage keeps `HomePageQuery` as its WordPress editorial boundary and uses
`HomeSemanticQuery` for bounded semantic discovery modules. The semantic query
must return purpose-labelled modules rather than a single undifferentiated list:
featured entities, hierarchy hubs, knowledge highlights, media, videos and
dictionary/editorial discovery where current public contracts permit them.

## 5. Profile composition

The shared shell uses the same accessible primitives—Container, SectionHeading,
Breadcrumb, Search, Card, Pagination, EmptyState and RelatedSection—but profile
sections are type-specific.

Brand profile order: identity, summary, hierarchy, models, movements, variants,
knowledge, evidence/source context, media, video, articles and explore-next.

Movement profile order: identity, parent context, related movements, technical
configuration, music, components, recognition, variants, knowledge/evidence,
media, video, articles and comparison navigation.

Variant profile order: identity, parent context, configuration, music,
components, recognition/observations, evidence state, nearby variants, media,
video and articles.

Sections render only when their projection contains eligible public items. The
generic payload is limited to safe identity attributes and is not the primary
page body.

## 6. Homepage composition

The homepage is a compact discovery gateway:

1. concise Vietnamese hero and prominent global search;
2. quick routes into the registered semantic hubs;
3. featured/latest editorial content from WordPress;
4. featured entities with representative media when available;
5. hierarchy discovery without mixing entity types into one unexplained grid;
6. knowledge highlights with lightweight evidence state;
7. media and video discovery using posters/thumbnails;
8. articles and thematic editorial sections;
9. relation-safe explore-next links.

No module contains hard-coded entity names, semantic counts or fixture content.
Modules disappear when their data is empty or unavailable. Result limits are
bounded and selected to avoid N+1 behavior.

## 7. UX, accessibility and responsive behavior

Use Vietnamese-first visitor language, warm restrained editorial tokens already
defined by the frontend contract, semantic headings, visible keyboard focus,
meaningful alt text, touch-sized controls and reduced-motion-safe behavior.

Desktop may use a contextual rail only when it contains navigation or evidence
context. Mobile collapses this into in-flow sections; specifications and evidence
remain readable without horizontal overflow. Images carry dimensions and lazy
loading below the fold. Homepage video uses deferred embeds.

## 8. SEO and public safety

Reuse existing SEO projections for canonical URL, indexability, metadata,
breadcrumbs, structured data, sitemap and RSS behavior. The frontend may not
invent schema fields or expose internal UUIDs/stable keys. Any public link must
pass the existing URL validation policy. Changed routes require existing redirect
and migration-ledger handling.

## 9. Testing and verification

Tests are added before implementation for:

- profile section ordering and visibility;
- direct/derived relation grouping and dedupe;
- thin and unavailable data behavior;
- evidence-state mapping without fabricated scores;
- representative media selection and safe URLs;
- homepage module bounds and no hard-coded content;
- Brand/Movement/Variant projection fixtures;
- accessible headings, labels and link purposes.

Verification includes PHP lint, focused PHPUnit tests, full relevant test suite,
`git diff --check`, secret review and route/browser QA at mobile, tablet and
desktop widths. When the local runtime is available, Odo is used as an
integration case only; the implementation remains generic and must also pass a
semantic-thin fixture.

## 10. Explicit non-goals and data gaps

This slice does not repair missing Graph relations, create a Product–Specimen
relation, activate unverified Public Identity data, migrate legacy article
bodies, add automatic Media-to-Knowledge enrichment, or add new semantic
vocabulary. The audit must report these as data/runtime gaps rather than hiding
them in the UI.

## 11. Implementation checkpoints

1. Add projection/profile tests and a focused read-model seam.
2. Implement shared profile composition and type-specific sections.
3. Rework homepage semantic modules and presentation.
4. Align Brand, Movement and Variant templates with the profile contract.
5. Run responsive/accessibility/SEO verification and update execution state.

Each checkpoint preserves existing working-tree changes, uses only governed
read paths, and records fresh verification evidence before claiming completion.
