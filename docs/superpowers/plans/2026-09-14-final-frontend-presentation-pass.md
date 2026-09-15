# NHK V3 Final Frontend Presentation Pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the read-only NHK V3 presentation seam so all public entity families use generic profiles, relationship-aware view models, visual-first previews, deterministic ordering, and shared responsive templates.

**Architecture:** Preserve Authority, Graph, Knowledge, Media, Video and WordPress ownership. Extend the existing profile registry/resolver and dossier composer, make the two frontend bootstraps compose one dossier instead of replacing one another, and keep templates consuming `presentation`/profile packets rather than traversing Graph. Use existing route services, media abstractions, and WordPress native post queries.

**Tech Stack:** PHP 8.1+, WordPress theme templates/CSS, PHPUnit 11, existing NHK V3 application/query services.

**Spec:** User-provided “NHK V3 — FINAL FRONTEND / PRESENTATION PASS”.

## Global Constraints

- `clock_type` remains the internal classification family and public copy is `Nhóm đồng hồ`.
- `/loai-dong-ho/` remains unchanged.
- Presentation is read-only; no Entity, Proposal, Apply, relation, Media, Video, Article, public URL, deployment, or Git mutation.
- Direct and derived relation origin/path remains in the view model but is not exposed as developer terminology.
- Dynamic feeds use published/created/stable descending order; `updated_at` is reserved for “Mới cập nhật”.
- Preview cards precede “Xem tất cả”; empty/unavailable sections fail soft and do not fabricate prose.
- Public templates contain Vietnamese visitor copy and no UUID, stable key, revision, Authority, Graph, projector, Proposal, or readiness terminology.

---

### Task 1: Extend the generic profile registry and resolver

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileResolver.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php`

**Interfaces:**
- Produces profiles for `brand`, `model`, `variant`, `movement`, `music`, `component`, `classification`, `specimen`, `product`, and `clock_type`.
- Resolver returns a resolved generic profile for registered non-classification entity types and keeps family matching fail-closed for classifications.

- [ ] Add failing assertions that every registered Authority type has a profile, with Vietnamese label, section order, relation targets, and no semantic vocabulary added.
- [ ] Run the focused registry test and observe the missing-profile failure.
- [ ] Add profile definitions using only presentation behavior (labels, sections, limits/layout hints, relation target groups, route/archive intent).
- [ ] Update resolver to resolve all registered non-classification types through the profile registry while retaining exact `clock_type` family matching.
- [ ] Run the focused registry/resolver tests and the existing profile tests.

### Task 2: Make dossier composition generic and single-pass

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticProfileComposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Presentation/EntityPresentationViewModel.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EntityPresentationViewModelTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EntityProfilePublicDossierTest.php`

**Interfaces:**
- `SemanticProfileComposer::compose()` remains the single profile-to-view-model boundary.
- `EntityPresentationViewModel::fromDossier()` preserves safe identity, section status, relation origin, relation depth, counts, and bounded items.

- [ ] Add failing tests for generic model/variant profile readiness and direct-over-derived deduplication across relation groups.
- [ ] Run the focused tests and observe the failure caused by unresolved generic profiles or duplicate bootstrap composition.
- [ ] Normalize profile section names/labels, preserve `relation_origin`/`relation_depth`, derive counts from emitted filtered items, and keep visual media separate from relation text.
- [ ] Change the detail bootstrap filter to enrich the dossier already produced by `FrontendSemanticBootstrap`, falling back to its own reader only when no dossier exists; never replace a complete dossier with a second read.
- [ ] Run presentation, dossier, brand, clock hierarchy, and profile tests.

### Task 3: Fix homepage/archive read projections and CTA/order semantics

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Home/HomeSemanticQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/PublicMediaGalleryQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php`
- Modify: `public/wp-content/themes/nhk-v3/inc/class-nhk-home-page-query.php`
- Modify: `public/wp-content/themes/nhk-v3/front-page.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/entity-card.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/media-card.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/video-card.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/HomeSemanticQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php`

**Interfaces:**
- Homepage semantic modules remain the existing filter shape, with hubs ordered by the canonical public navigation and clock groups limited to presentation-ready records.
- Media/video totals count the same public-filtered source used to render previews.

- [ ] Add failing tests for media totals excluding records with no usable public image, video previews carrying newest-first publication data, and homepage hub order placing Brand before Nhóm đồng hồ.
- [ ] Run focused tests and observe the expected ordering/count failures.
- [ ] Filter/count media and video only after public visual/route projection is available; include `published_at`, subject context, and representative metadata in cards.
- [ ] Replace hard-coded homepage hub order with a canonical presentation navigation definition while keeping the existing route paths.
- [ ] Add optional count badges to the shared entity card and keep CTA output conditional on `total > preview_count`.
- [ ] Run homepage/media/video/order tests.

### Task 4: Consolidate shared dossier rendering and navigation

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/header.php`
- Modify: `public/wp-content/themes/nhk-v3/footer.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/hierarchy-nav.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/local-section-nav.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/visual-rail.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/breadcrumbs.php`
- Modify: `public/wp-content/themes/nhk-v3/template-parts/presentation/empty-state.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php`

**Interfaces:**
- Desktop uses the existing three-column semantic layout when width permits; mobile collapses hierarchy and moves visual modules below main content.
- Desktop and mobile both call the same `nhk_v3_navigation_items()` definition.

- [ ] Add failing structural assertions for generic hierarchy/local-nav usage and shared visual rails.
- [ ] Run the focused contract test and observe missing shared seam assertions.
- [ ] Make breadcrumb inputs relationship-aware when the projector supplies them, with page-family fallback only when no relationship path is available.
- [ ] Render dossier sections from profile order/status, use shared Entity/Media/Video cards, and remove duplicated inline relation card markup where the shared part can express the role.
- [ ] Add family-aware left hierarchy fallback for model/variant/brand data without treating context as subtype and without inventing links.
- [ ] Keep empty/blocked/unavailable states fail-soft and public-safe.
- [ ] Run all theme contract tests.

### Task 5: Responsive/accessibility presentation pass and acceptance matrix

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/presentation.css`
- Modify: `public/wp-content/themes/nhk-v3/entity.css`
- Modify: `public/wp-content/themes/nhk-v3/media-video.css`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Create: `docs/architecture/FINAL_FRONTEND_PRESENTATION_PASS_REPORT.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php`

**Interfaces:**
- CSS reuses current tokens/breakpoints and provides stable image ratios, focus-visible states, scroll-safe local navigation, and mobile visual previews.
- The report records family status, code-complete/data-empty distinctions, missing relations, and explicit no-mutation/no-deploy scope.

- [ ] Add failing CSS/template assertions for focus-visible, image object-fit, mobile visual retention, and semantic navigation labels.
- [ ] Run the focused contract test and observe failures.
- [ ] Add the smallest shared CSS rules needed for card variants, three-column collapse, visual preview grids, keyboard focus, and 360–430px layouts.
- [ ] Run PHP lint, focused PHPUnit, full PHPUnit, `git diff --check`, and a secret scan over changed files.
- [ ] Write the acceptance matrix and exact data gaps to the frontend report; update execution state without changing semantic/runtime data.
