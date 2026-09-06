# Semantic Frontend Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the public theme a governed semantic discovery layer with deterministic, reader-safe profiles for Brand, Movement and Variant and bounded homepage discovery modules.

**Architecture:** Extend the existing `SemanticDossierQuery`, `RelatedSemanticQuery`, `EntityKnowledgeProjection`, media/video projections and `HomeSemanticQuery`. Add one application-level profile composer that groups already-projected data into type-specific sections; templates render that reader-safe shape and never query storage or infer relations.

**Tech Stack:** PHP 8.1+, PHPUnit, WordPress theme PHP/CSS/HTML, existing NHK V3 repositories, Graph registries and SEO/public-URL projections.

**Spec:** `docs/superpowers/specs/2026-09-06-semantic-frontend-discovery-design.md`

## Global Constraints

- Projection-only: no semantic writes, Graph mutations, identity repair, article-body migration or fixture insertion into live/demo data.
- Use only registered entity types, endpoints, predicates, facets and existing public route/SEO contracts.
- Public output must omit canonical UUIDs, stable keys, lifecycle state, raw storage metadata and operational Graph identifiers.
- Articles appear only through governed canonical references; title/keyword/slug matching is forbidden.
- Media uses existing representative/usage projection; Video uses poster-first deferred embeds and never exposes external IDs in public routes.
- Empty and unavailable dependencies remain distinct; no fabricated counts, confidence scores, facts, URLs or evidence.
- Vietnamese-first copy, semantic headings, accessible focus states, responsive 390px/768px/1440px layouts and honest error/empty states are required.

### Task 1: Read-model profile contract seam

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticProfileComposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticProfileComposerTest.php`

**Interfaces:**
- Consumes `SemanticDossierQuery::forEntity(AuthorityEntity): array` and existing `relation_sections`, `knowledge`, `primary_media`, `media_gallery`, `seo_projection`, `coverage`, `availability`, `warnings`.
- Produces `SemanticProfileComposer::compose(string $type, array $dossier): array` with keys `identity`, `hierarchy`, `relation_sections`, `knowledge`, `evidence_context`, `primary_media`, `media_gallery`, `videos`, `articles`, `navigation`, `coverage`, `availability`, `warnings`, `seo_projection`.

- [ ] Step 1: Add tests asserting the exact keys, reader-safe identity, deterministic ordering, and distinction between `AVAILABLE`, empty and unavailable inputs.
- [ ] Step 2: Run `vendor/bin/phpunit public/wp-content/plugins/nhk-core/tests/Unit/SemanticProfileComposerTest.php`; expected: FAIL because the composer class/method does not exist.
- [ ] Step 3: Implement the minimal composer as a pure mapper. Copy only whitelisted public values, normalize missing arrays to `[]`, preserve status strings, and derive `videos`/`articles` from already supplied relation sections without querying.
- [ ] Step 4: Wire `SemanticDossierQuery` to return `profile` from the composer while retaining existing top-level keys for compatibility.
- [ ] Step 5: Run the focused test and existing `SemanticDossierQueryTest`; expected: PASS.
- [ ] Step 6: Commit `feat: add semantic profile read-model seam`.

### Task 2: Relation section composition

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticProfileComposer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticProfileComposerTest.php`

**Interfaces:** Uses the Task 1 composer input and produces ordered section arrays retaining each relation item's `origin.kind`, `origin.hop_count`, `origin.predicates` and `origin.path` when present.

- [ ] Step 1: Add tests for direct-before-derived, lower-hop-before-higher-hop, canonical-identity dedupe, and ineligible/malformed item omission.
- [ ] Step 2: Run the focused test; expected: FAIL on missing ordering/dedupe behavior.
- [ ] Step 3: Implement stable grouping by registered semantic group, dedupe by public canonical target identity when available, and sort by directness, hop count, title, then original position. Never create inverse or flattened relations.
- [ ] Step 4: Run the focused test and `RelatedSemanticQueryTest`; expected: PASS.
- [ ] Step 5: Commit `feat: compose path-aware relation sections`.

### Task 3: Knowledge and evidence context

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticProfileComposer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticProfileComposerTest.php`

**Interfaces:** Consumes public `knowledge.facets`, `knowledge.coverage`, `knowledge.warnings`; produces `knowledge` grouped by registered facet plus `evidence_context` containing only supported public evidence states.

- [ ] Step 1: Add tests for valid facet grouping, mixed sourced/unsourced claims, unavailable source, and absence of numeric confidence fabrication.
- [ ] Step 2: Run focused test; expected: FAIL because evidence context is absent.
- [ ] Step 3: Map existing facet labels/claims and coverage states; omit raw IDs and source storage metadata; preserve `UNAVAILABLE` rather than converting it to empty.
- [ ] Step 4: Run focused test and `KnowledgeFragmentProjectionTest`; expected: PASS.
- [ ] Step 5: Commit `feat: expose reader-safe knowledge context`.

### Task 4: Media, Video and Article projection slots

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticProfileComposer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticDossierQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticProfileComposerTest.php`

**Interfaces:** `SemanticDossierQuery` supplies projected media/video/article relation items; composer exposes `primary_media`, `media_gallery`, `videos`, `articles` with safe URLs and no internal IDs.

- [ ] Step 1: Add tests for representative-media precedence, placeholder exclusion, poster-only video output, deferred embed marker, and article inclusion only from a canonical `wp_post` relation item.
- [ ] Step 2: Run focused tests; expected: FAIL for missing profile slots/deferred marker/article filtering.
- [ ] Step 3: Reuse `EntityMediaProjection`, `PublicMediaGalleryQuery`, existing Video route/projection and relation resolver. Add only a boolean `deferred_embed`/`thumbnail_url` projection field; filter articles to governed projected items and do not title-match.
- [ ] Step 4: Run focused tests and `MediaPresentationProjectionTest`, `VideoSemanticDossierTest`; expected: PASS.
- [ ] Step 5: Commit `feat: compose governed media video and article slots`.

### Task 5: Type-specific profile definitions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticProfileComposer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticProfileComposerTest.php`

**Interfaces:** Produces `section_order` and `visible_sections` for `brand`, `movement`, `variant`; unknown registered types use the shared fallback without inventing sections.

- [ ] Step 1: Add tests for exact Brand, Movement and Variant order, hidden empty sections, useful thin profiles, and unavailable dependencies.
- [ ] Step 2: Run focused test; expected: FAIL because type-specific order is not defined.
- [ ] Step 3: Implement explicit arrays from the approved spec; include evidence/video/article sections only when populated; keep unavailable diagnostics available to the template.
- [ ] Step 4: Run focused test; expected: PASS.
- [ ] Step 5: Commit `feat: define authority profile section order`.

### Task 6: Shared theme rendering primitives

**Files:**
- Create: `public/wp-content/themes/nhk-v3/template-parts/semantic-profile.php`
- Create: `public/wp-content/themes/nhk-v3/template-parts/semantic-section.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendSemanticProjectionV2Test.php`

**Interfaces:** Theme consumes only `$context['entity']['profile']` (with compatibility fallback to existing dossier fields); partials render identity, facts, relations, knowledge/evidence, media, video poster cards, article cards, navigation and unavailable state.

- [ ] Step 1: Add static contract assertions for no raw UUID/stable-key output, semantic heading levels, non-empty link purposes, alt text and deferred video markup.
- [ ] Step 2: Run the focused frontend contract test; expected: FAIL for missing partials/profile references.
- [ ] Step 3: Implement reusable partials with escaped output, allowlisted labels, hidden empty sections, concise Vietnamese copy and no public Source/Evidence route creation. Move type-specific section selection into the profile data, not template conditionals beyond presentation.
- [ ] Step 4: Add responsive rules for 390px/768px and preserve existing token system, focus styles, reduced-motion behavior and two-column desktop rail.
- [ ] Step 5: Run the focused test; expected: PASS.
- [ ] Step 6: Commit `feat: render semantic profiles with shared theme primitives`.

### Task 7: Homepage discovery modules

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Home/HomeSemanticQuery.php`
- Modify: `public/wp-content/themes/nhk-v3/front-page.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/HomeSemanticQueryTest.php`

**Interfaces:** `HomeSemanticQuery::extend(array $modules): array` produces purpose-labelled bounded modules: `hubs`, `editorial`, `entities`, `knowledge`, `media`, `videos`, `explore_next`, each with availability where applicable.

- [ ] Step 1: Add tests for per-module bounds, empty-module hiding, unavailable-domain state, and absence of literal Odo/entity names/counts.
- [ ] Step 2: Run focused test; expected: FAIL for missing labels/availability/bounds.
- [ ] Step 3: Refactor the current loops into bounded module builders without changing repository ownership; retain real WordPress editorial modules and hide empty semantic modules. Do not add N+1 reads or fixture data.
- [ ] Step 4: Update `front-page.php` to render compact hero/search, labelled hubs, editorial, entity hierarchy, knowledge, media/video and explore-next modules using existing partials.
- [ ] Step 5: Run focused test and existing frontend contract tests; expected: PASS.
- [ ] Step 6: Commit `feat: turn homepage into semantic discovery gateway`.

### Task 8: Brand, Movement and Variant route alignment

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php` only if context wiring is required
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityRoutesTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticDossierQueryTest.php`

**Interfaces:** Existing registered routes remain unchanged; the route context carries the profile, canonical URL and SEO projection from the existing resolver.

- [ ] Step 1: Add regression tests that Brand/Movement/Variant details use one canonical public URL and retain `seo_projection`/breadcrumb inputs.
- [ ] Step 2: Run focused tests; expected: FAIL if template/context drops the profile or canonical projection.
- [ ] Step 3: Align template branches with `section_order`, preserve native WordPress ownership for posts, and route every internal link through existing public URL helpers.
- [ ] Step 4: Run focused route/dossier tests; expected: PASS.
- [ ] Step 5: Commit `feat: align authority routes with semantic profiles`.

### Task 9: Performance, accessibility and SEO verification

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.css` only for verified defects
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test/commands: existing frontend route smoke, SEO tests, PHP lint and Composer validation

**Interfaces:** No new runtime interface; verification consumes all public routes and projections from Tasks 1–8.

- [ ] Step 1: Run focused PHPUnit, then the full Unit suite; record exact counts and failures.
- [ ] Step 2: Run `find public/wp-content/plugins/nhk-core/src public/wp-content/themes/nhk-v3 -name '*.php' -print0 | xargs -0 -n1 php -l`; expected: no syntax errors.
- [ ] Step 3: Run `composer validate --no-check-publish`, `git diff --check`, and repo secret scan convention; record output.
- [ ] Step 4: Run route/SEO smoke and browser QA at 390px, 768px and 1440px for homepage, Brand, Movement, Variant and a thin entity. Check overflow, headings, focus, alt, dead links, duplicate sections, raw IDs, console errors and deferred embeds.
- [ ] Step 5: If runtime/data is unavailable, record `ENVIRONMENT_BLOCKED` and `DATA_GAP` with the exact command/output; do not convert it to PASS.
- [ ] Step 6: Update execution state with implementation files, tests, visual findings and remaining gaps; commit `docs: record semantic frontend verification`.

### Task 10: Final sync and integration

- [ ] Step 1: Fetch `origin`, inspect divergence and preserve unrelated changes.
- [ ] Step 2: Re-run full verification after sync.
- [ ] Step 3: Integrate only if branch/worktree and repository policy permit; never force-push or mutate demo/production.
- [ ] Step 4: Record commit SHAs, merge SHA if applicable, push status and final worktree status.
