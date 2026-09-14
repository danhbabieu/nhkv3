# NHK V3 Final Frontend Presentation Pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (recommended) or superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the read-only relationship-driven presentation system across the existing NHK V3 public surfaces without changing semantic ownership, routes, data, or deployment state.

**Architecture:** Keep WordPress posts, Authority, Graph, Knowledge, Media, MediaAsset, MediaUsage and Video as the existing owners. Add one shared presentation assembler/view-model seam over existing query services, expand only presentation profiles for registered families, and make the theme a thin profile-driven renderer composed from shared partials.

**Tech Stack:** PHP 8.x, WordPress theme PHP/CSS/vanilla JS, PHPUnit, existing NHK V3 application/query services and runtime registries.

**Spec:** User-provided NHK V3 Final Frontend / Presentation Pass requirements plus `docs/architecture/V3_FRONTEND_DESIGN_CONTRACT.md`, `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`, `docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md` and `docs/architecture/SHARED_FEED_ORDERING_CONTRACT.md`.

## Global Constraints

- `clock_type` remains internal `classification` family vocabulary; public label remains `Nhóm đồng hồ`.
- `/loai-dong-ho/` remains the Clock Group archive route.
- No Authority, Graph, Knowledge, Media, Video, Product/Specimen or WordPress semantic mutation.
- No proposal, apply, ingest, article publication, public URL mutation or deployment.
- Templates consume application contexts only; no raw database queries or semantic inference in theme code.
- Direct and derived relation origins remain reader-safe internally and are never persisted as shortcut relations.
- Dynamic feeds use `published_at DESC → created_at DESC → canonical tie-breaker DESC` before slicing; updated views opt into `updated_at`.
- Empty, unavailable, blocked and unsupported branches remain distinguishable.
- Existing tokens, palette, fonts, routes and brand tone remain the design basis.

---

### Task 1: Lock presentation behavior with failing tests

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationViewModelTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticProfileComposerTest.php` if present

**Interfaces:**
- Consumes: existing `PresentationReadiness`, `LatestFirstOrder`, `SemanticProfileComposer`, entity profiles and dossier arrays.
- Produces: executable expectations for shared presentation fields, section states, origin precedence, readiness exclusion, and profile coverage.

- [ ] **Step 1: Write failing tests** for: direct origin outranking derived origin; same item deduplicating once while retaining strongest origin; Clock Group hierarchy preserving parent/children order; active-without-route being excluded from presentation-ready archive output; dynamic newest ordering being applied before page slicing; and every registered Authority family resolving a presentation profile without exposing internal labels.
- [ ] **Step 2: Run the focused PHPUnit tests** and confirm they fail for missing behavior rather than fixture/bootstrap errors.
- [ ] **Step 3: Keep the tests as the contract** while implementing Tasks 2–5.

### Task 2: Normalize the shared presentation read model

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Presentation/EntityPresentationViewModel.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Presentation/RelationOrigin.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Presentation/SectionStatus.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Presentation/PresentationProfile.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticProfileComposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`

**Interfaces:**
- `EntityPresentationViewModel::fromDossier(string $type, array $dossier): array` returns reader-safe `identity`, `route`, `title`, `subtitle`, `summary`, `description`, `hero_media`, `breadcrumbs`, `parent`, `children`, `siblings`, `knowledge`, `articles`, `media`, `videos`, `models`, `variants`, `movements`, `melodies`, `parts`, `specimens`, `products`, `derived_brands`, `related_entities`, `counts`, `section_status`, `presentation_readiness`, `relation_origin`, `relation_depth`, `timestamps` where available.
- `RelationOrigin::normalize(array $origin): array` preserves `DIRECT` or bounded `DERIVED`, hop count and ordered path metadata, and computes a presentation-only provenance bucket such as `DERIVED_VIA_SUBTYPE`, `DERIVED_VIA_MODEL`, `DERIVED_VIA_VARIANT`, `DERIVED_VIA_SPECIMEN`, `DERIVED_VIA_PRODUCT` or `DERIVED_VIA_ARTICLE` from existing path evidence only.
- `SectionStatus::forItems(array $items, string $ownerStatus = 'AVAILABLE'): array` maps successful populated/empty branches and owner-unavailable branches without collapsing failures into empty.

- [ ] **Step 1: Add the smallest implementation** that maps existing dossier/profile packets without introducing new Graph vocabulary or storage.
- [ ] **Step 2: Add bounded deduplication** using canonical identity when present, then existing public URL/title fallback only for reader-safe non-canonical resources; choose direct, then lower hop count, then existing feed ordering.
- [ ] **Step 3: Preserve ordering metadata only inside the application assembler** and remove it before the theme-facing packet is returned.
- [ ] **Step 4: Make `SemanticProfileComposer` delegate normalization** and retain existing keys for compatibility so current templates/tests do not break while the shared renderer migrates.
- [ ] **Step 5: Run the focused tests and all existing entity/profile tests.**

### Task 3: Expand profile-driven page families and public collection readiness

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileDefinition.php` only if presentation defaults need a typed accessor
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Home/HomeSemanticQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/FrontendSemanticBootstrap.php`

**Interfaces:**
- Consumes: registered nine Authority types, existing route/eligibility services, existing Graph/query readers and Task 2 view model.
- Produces: presentation-only profiles for `brand`, `clock_type`, `model`, `variant`, `movement`, `music`, `component`, `classification`, `specimen`, `product`; shared archive/detail packets with readiness and section limits.

- [ ] **Step 1: Add profiles only for registered types** with Vietnamese visitor labels, existing canonical archive paths, section order, visual priority and centralized preview limits. Do not add an Authority type, relation, route or field.
- [ ] **Step 2: Make archive items use the same readiness policy** where a profile requires usable route/identity/content/media, while leaving semantic ACTIVE untouched.
- [ ] **Step 3: Ensure Clock Group archive cards expose representative media, summary and only non-zero counts from the same query source.**
- [ ] **Step 4: Ensure HomeSemanticQuery requests bounded media/video/article/entity previews once, newest-first before slicing, and does not build per-page relation logic.**
- [ ] **Step 5: Add/update tests for profile coverage, readiness exclusion, hierarchy, representative media precedence, counts and latest-first page boundaries.**

### Task 4: Create shared theme presentation partials and converge dossier rendering

**Files:**
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/breadcrumbs.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/entity-hero.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/local-section-nav.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/hierarchy-nav.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/section-header.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/empty-state.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/entity-card.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/article-card.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/media-card.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/video-card.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/media-grid.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/relationship-section.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/presentation/visual-rail.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/front-page.php`
- Modify: `public/wp-content/themes/nhk-v3/index.php`
- Modify: `public/wp-content/themes/nhk-v3/single.php`
- Modify: `public/wp-content/themes/nhk-v3/media.php`
- Modify: `public/wp-content/themes/nhk-v3/video.php`
- Modify: `public/wp-content/themes/nhk-v3/knowledge.php`
- Modify: `public/wp-content/themes/nhk-v3/comparison.php`
- Modify: `public/wp-content/themes/nhk-v3/404.php`

**Interfaces:**
- Consumes: one theme-facing view model/context, existing `nhk_core_*_context` globals and native WP post loop.
- Produces: semantic, reusable presentation markup with one H1, relationship-aware links, available-section navigation, visual previews before CTAs and honest empty states.

- [ ] **Step 1: Extract shared partials** using only escaped reader-safe values and `nhk_v3_public_url()`/existing route projections for links.
- [ ] **Step 2: Make `entity.php` a coordinator** that selects profile sections and invokes shared partials; remove inline graph traversal, duplicated cards and entity-name special cases.
- [ ] **Step 3: Render Clock Group hierarchy from projected parent/children/siblings** and never treat editorial context labels as subtype children.
- [ ] **Step 4: Render direct/derived labels only where useful to public discovery, never raw origin identifiers; keep internal origin/path available to tests/debug serialization.
- [ ] **Step 5: Convert media/video modules to preview cards and only show “Xem tất cả” when total exceeds preview limit; defer iframe creation to interaction/viewport using existing JS policy.
- [ ] **Step 6: Keep Product and Specimen identity distinct; hide unsupported Product–Specimen linkage rather than using `specimen_uuid` or broad `about` as a semantic shortcut.
- [ ] **Step 7: Add structural template tests** for hub cards, dossier section visibility, empty states, local nav, hierarchy, visual rail, image/video preview and CTA rules.

### Task 5: Finish navigation, responsive/accessibility styling and archive ordering

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/functions.php`
- Modify: `public/wp-content/themes/nhk-v3/header.php`
- Modify: `public/wp-content/themes/nhk-v3/footer.php`
- Modify: `public/wp-content/themes/nhk-v3/navigation.js`
- Modify: `public/wp-content/themes/nhk-v3/style.css`
- Modify: `public/wp-content/themes/nhk-v3/entity.css`
- Modify: `public/wp-content/themes/nhk-v3/media-video.css`
- Modify: `public/wp-content/themes/nhk-v3/knowledge.css`

**Interfaces:**
- Consumes: one canonical navigation definition, existing token source, shared partial class names and current WordPress shell.
- Produces: synchronized desktop/mobile nav, responsive dossier layout, consistent card variants, visible focus states, stable image ratios, accessible video affordances and no horizontal overflow.

- [ ] **Step 1: Replace fallback-only nav duplication** with a single visitor-facing definition consumed by fallback and registered-menu rendering, placing `Nhóm đồng hồ` after `Thương hiệu` and before `Mẫu`.
- [ ] **Step 2: Preserve configured WordPress menu behavior** while ensuring the responsive menu exposes the same required item when the fallback/registry path is used.
- [ ] **Step 3: Add/normalize shared CSS for `wide-content`, `dossier-grid`, `visual-rail`, card variants, image containers, scroll-snap strips and mobile hierarchy disclosure using existing tokens and breakpoints.
- [ ] **Step 4: Ensure keyboard toggle state/ARIA, landmarks, heading order, alt text, focus-visible, touch targets and lazy/deferred media remain valid.
- [ ] **Step 5: Add/update theme contract tests** for nav order, mobile parity, class/token reuse, heading/landmark/empty behavior and media/video visibility.

### Task 6: Verification, audit report and execution-state evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with a dated read-only frontend checkpoint only
- Create: `docs/architecture/FINAL_FRONTEND_PRESENTATION_PASS_2026-09-14.md`

**Interfaces:**
- Consumes: all implementation/test results and current page-family acceptance matrix.
- Produces: evidence-backed final report with `COMPLETE`, `PARTIAL`, `EMPTY_BY_DATA`, or `NOT_APPLICABLE`, explicit missing data/relations, and the required no-mutation/no-deploy declarations.

- [ ] **Step 1: Run focused presentation/unit tests and the full Unit suite** with no database mutation.
- [ ] **Step 2: Run PHP lint across changed PHP files, `git diff --check`, and repository secret review.**
- [ ] **Step 3: Run the existing read-only frontend route smoke and, if the local server is available, responsive/visual checks at practical widths without creating or changing data.
- [ ] **Step 4: Read back `V3_EXECUTION_STATE.md` and `V2_V3_PARITY_MATRIX.md`, then record only evidence actually observed.**
- [ ] **Step 5: Write the final report in the exact requested format and stop frontend work. Do not deploy, mutate, ingest, publish, allocate routes, or perform Git cleanup.**

## Self-review

- The plan reuses the existing query/projector seams and does not create semantic types, predicates, relations, entities or persistence.
- Product–Specimen remains fail-closed because the Constitution records the relation mechanism as a registry gap.
- Media detail remains delivery-oriented; no standalone indexable Media entity route is invented.
- Missing live Video/Media/identity data is reported as data/runtime evidence, not fabricated content.
- Sorting and pagination are centralized at query/application boundaries, never in templates after slicing.
- All public visitor copy stays Vietnamese-first and internal identifiers remain hidden.
