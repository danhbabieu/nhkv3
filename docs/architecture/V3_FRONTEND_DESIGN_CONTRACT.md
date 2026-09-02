# NHK V3 Frontend Design Contract

> **NON-NORMATIVE.** This records frontend implementation guidance and QA
> evidence. If it conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`,
> the Constitution controls.

Status: contract for P9 implementation; visual choices remain subject to
read-only theme/demo audit and route evidence.

## Product direction

NHK is a curated knowledge and collecting archive for vintage clocks. The
experience is editorial and exploratory: a visitor should move naturally from
an article to an entity, media, video or related article. The visual language
is warm, restrained and sophisticated rather than faux-vintage or commerce-led.
Use bright warm-neutral surfaces, strong readable text, large imagery, generous
but efficient spacing, and serif display typography only where it improves
hierarchy. Sans-serif remains the default for body and controls.

V2/demo is used for route and behavior research. Tinhte is used only to study
information density, feed rhythm, featured hierarchy, topic discovery and
reading comfort. Do not copy either site's branding, assets, markup, CSS,
icons or proprietary text.

The 2026-09-01 read-only reference check confirmed V2 samples of 12 Tri thức
cards and 15 Brand/Model cards, honest empty states for Video/Ảnh and Góc
chia sẻ, and a representative article with breadcrumb, standfirst, long-form
body and adjacent-article navigation. A Tinhte homepage check confirmed dense
feed rhythm, featured hierarchy and compact quick-view content; these are
interaction references only, not implementation or branding inputs.

## Tokens

The theme must define and consume these tokens from a single source:

```css
:root {
  --nhk-bg: ...;
  --nhk-surface: ...;
  --nhk-text: ...;
  --nhk-muted: ...;
  --nhk-border: ...;
  --nhk-accent: ...;
  --nhk-accent-secondary: ...;
  --nhk-radius: ...;
  --nhk-shadow: ...;
  --nhk-content-width: ...;
  --nhk-wide-width: ...;
}
```

The initial implementation selects warm neutral paper/surface tones, deep
charcoal text, muted taupe, wood accent `#8c4f2f`, secondary brass `#b18a5a`,
small radius, restrained shadow, 760px reading width and 1180px wide layout.
These values remain subject to contrast verification during browser QA. Tokens
are responsive-safe and must not be redeclared ad hoc in templates.

## Shell and navigation

Desktop navigation exposes visitor language: Tri thức, Thương hiệu, Mẫu, Bộ
máy, Bản nhạc, So sánh, Linh kiện, Hiện vật, Video and Góc chia sẻ. Global
semantic search is prominent and searches Posts, Brands, Models, Movements,
Music, Components, Classifications, Specimens/Products and Videos through a
unified application service. On small screens the shell becomes hamburger,
search and logo with touch-sized controls; no critical action is hover-only.

## Page contracts

The homepage is a discovery gateway, not a static landing page. It may contain
a concise hero/search, quick discovery links, featured editorial, latest feed,
Góc chia sẻ, Tri thức, featured entities, Video, collection/specimen content
and derived popular topics. Modules use real V3 query services and hide when
there is no content or metric evidence.

Post pages remain WordPress editorial truth: breadcrumb, category, H1,
standfirst, author/date, featured media, body, semantic entity chips, optional
TOC, inline media, source presentation, related entities/posts/videos and
sharing. Body text is constrained to roughly 720–800px; images may break out.

Brand, Model, Movement, Music, Component, Specimen and Product pages use
domain-specific `EntityPageQuery` data and Graph-backed sections. Specimen is
a concrete physical object; Product is a listing/offer and may link to a
Specimen. Video pages/modules show thumbnail, title, platform and approved
reader-facing display metadata only, and embed the external platform without
normal-flow MP4 downloads. Persisted internal metadata stays on governed
application/MCP paths.

Semantic search groups are bounded per page and expose totals so pagination
continues even when native WordPress Post results are exhausted. Archives provide appropriate search/filter/pagination, metadata and empty
states. They must not force every domain into one generic card layout.

## Component and application boundaries

Reusable presentation primitives include Container, SectionHeading, Card,
ArticleCard, EntityCard, MediaCard, VideoCard, Badge, Breadcrumb, Pagination,
Search, Filter, Tabs, Gallery, RelatedSection and EmptyState. Templates do not
query raw database tables. RelatedSection must consume the shared, bounded
Graph-backed contract in
`docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md`; page assemblers
may choose labels and limits but may not create page-specific semantic
traversal or taxonomy fallbacks. Use HomePageQuery, EntityPageQuery,
ArchiveQuery, RelatedEntityQuery (with the transitional
`RelatedContentQuery` seam documented there), SearchQuery, MediaGalleryQuery
and VideoQuery (or equivalent application services), avoiding N+1 queries and
requiring pagination for large collections.

## Accessibility, performance and SEO

Use semantic HTML, a valid heading hierarchy, keyboard navigation, visible
focus, accessible names, meaningful alt text, distinguishable links/buttons
and sufficient contrast. Images carry dimensions and responsive sizes; below-
fold media is lazy-loaded; JavaScript stays small. Validate long titles,
missing images, empty archives, large galleries and long articles.

Preserve editorial URLs where possible. Implement canonical URLs, title and
description metadata, OpenGraph, appropriate structured data, breadcrumbs,
sitemap/RSS compatibility and an explicit archive index/noindex policy. Any
changed URL is recorded in the migration ledger/redirect mapping.

The theme policy is explicit through WordPress's single `wp_robots` output:
canonical non-search surfaces emit `index,follow`; search results and
page-two-or-later archive states emit `noindex,follow` while retaining
followable links to their canonical content. This includes the custom entity,
Media, Video and Knowledge pagination query vars.

## Acceptance

Critical route smoke tests cover homepage, Post, Brand archive/single, Model
archive/single, Movement, Music, Component, search, Video and 404 behavior.
Visual QA covers wide desktop, laptop, tablet and mobile plus overflow,
broken-image, no-image, empty-state, gallery and long-content cases. Fixture
labels and hard-coded entity lists must never leak into public-facing output.
