# Clock Type Frontend Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with TDD checkpoints.

**Goal:** Make the shared public Entity/Profile frontend discover and render any canonical `classification` with `family=clock_type` through the existing dossier, archive, search, route, and related-content seams.

**Architecture:** Extend the existing `EntityProfileRegistry`-driven read path. Public collection items and dossier relation items carry safe profile metadata resolved from canonical entity metadata; archive routing uses the registered `clock_type` archive intent and filters by resolved profile. The WordPress theme remains a shared renderer and never identifies Clock Type from a title, UUID, stable key, or slug.

**Tech Stack:** PHP 8.2+, PHPUnit 11, WordPress theme PHP templates, existing NHK Core application/query/route services.

**Spec:** User-provided Frontend Clock Type Integration requirements, grounded in `docs/architecture/ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md`, `docs/architecture/CLOCK_TYPE_ECOSYSTEM_CONTRACT.md`, `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`, and `docs/architecture/V3_FRONTEND_DESIGN_CONTRACT.md`.

## Global Constraints

- `family=clock_type` is canonical and is read from validated entity payload; never infer it from stable key, title, slug, or UI label.
- No ontology, predicate, Authority, Graph, Public Identity, route allocation, backfill, PR7, or live semantic mutation changes.
- `EntityProfileRegistry` is the only Brand/Clock-Type profile seam; other Classification families remain generic/non-Clock-Type.
- Public dossier, archive, search, SEO, and internal links are read-only projections and use existing route/SEO owners.
- Direct and derived content remains distinguishable; Graph traversal stays bounded and no shortcut relation is materialized.
- Missing Public Identity, route collision, wrong family, retired relation, and unavailable owner remain fail-closed and distinct from empty data.

---

### Task 1: Add profile-aware public archive and safe profile metadata

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityRoutesTest.php`

**Interfaces:**
- Consumes: `EntityProfileRegistry`, `EntityProfileResolver`, `PublicIdentityContract`, and the existing `PublicEntityCollectionQuery`/`PublicRouteResolver` boundaries.
- Produces: `PublicEntityCollectionQuery::archiveProfile(string $profileKey, int $page, int $perPage, string $query): array`, profile-safe item fields (`profile_key`, `profile_label`, `profile_badge`), and a profile route context for `/loai-dong-ho/`.

- [ ] **Step 1: Write failing tests** for `archiveProfile('clock_type')` including a canonical clock type, a `case_form` classification with the same title, a missing public identity, and an unavailable authority store. Assert only the canonical profile is returned, no URL is fabricated, and unavailable is not empty.
- [ ] **Step 2: Run the focused tests and verify the failures** are caused by the missing profile archive method/route wiring.
- [ ] **Step 3: Implement the minimum profile-aware query** by resolving each active classification through `EntityProfileResolver`; include only `RESOLVED` profile `clock_type`, and add only safe visitor-facing profile metadata to serialized items.
- [ ] **Step 4: Add the registered profile archive path** from `EntityProfileRegistry` to the route resolver/route registration without changing generic `/phan-loai/` behavior or allocating any Public Identity.
- [ ] **Step 5: Run the focused tests and verify green.**
- [ ] **Step 6: Commit** with `git add` limited to Task 1 files and `git commit -m "feat: expose clock type public archive profile"`.

### Task 2: Propagate profile identity through dossier relations and hierarchy presentation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/BrandAggregationQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/ClockTypeDossierProjection.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/functions.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeDossierProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendSemanticProjectionV2Test.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/BrandPublicDossierAcceptanceTest.php`

**Interfaces:**
- Consumes: existing dossier relation packets, `ClockTypeHierarchyProjection`, `BrandAggregationQuery`, and `EntityProfileResolver`.
- Produces: safe `profile` metadata on classification relation cards, shared visitor label resolution, and rendered parent/children/direct-derived sections using existing dossier data.

- [ ] **Step 1: Write failing tests** asserting a `clock_type` relation renders `[LOẠI ĐỒNG HỒ]`/`Loại đồng hồ`, a `case_form` relation does not, hierarchy parent/children are rendered, and derived Brand/Media/Article/Video context keeps its origin.
- [ ] **Step 2: Run the focused tests and verify red** for missing profile metadata and template contract markers.
- [ ] **Step 3: Implement safe profile propagation** from canonical entity resolution; do not expose canonical UUID/stable key in public profile data and do not change relation ownership.
- [ ] **Step 4: Extend the shared theme helpers/template** to use profile metadata for labels, render hierarchy only when available, and keep unavailable sections hidden or explicitly unavailable rather than treating them as empty.
- [ ] **Step 5: Run focused dossier/template tests and verify green.**
- [ ] **Step 6: Commit** only the Task 2 files with `git commit -m "feat: render clock type profile dossier context"`.

### Task 3: Wire archive, search, breadcrumb, and SEO through the shared profile contract

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/index.php`
- Modify: `public/wp-content/themes/nhk-v3/functions.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Search/SearchSemanticQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/SearchApi.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SearchSemanticQueryTest.php`

**Interfaces:**
- Consumes: profile-aware collection item metadata and persisted Public Identity URLs.
- Produces: Clock-Type archive cards, search badge, breadcrumb/archive labels, and shared SEO projection inputs without title/slug inference.

- [ ] **Step 1: Write failing tests** for archive card labels, search result badge, `/loai-dong-ho/` breadcrumb, missing identity no-link behavior, wrong-family exclusion, and legacy `clock-type` compatibility display.
- [ ] **Step 2: Run the focused tests and verify red** for the missing profile-aware frontend contract.
- [ ] **Step 3: Implement archive context wiring** so `/loai-dong-ho/` calls `archiveProfile('clock_type')`; preserve `/phan-loai/` for generic classifications and do not create detail URLs.
- [ ] **Step 4: Implement profile-aware search serialization/rendering** while keeping canonical route/SEO ownership in `PublicIdentity` and `PublicSeoProjection`.
- [ ] **Step 5: Implement breadcrumb and shared SEO labels** from resolved profile/archive context only.
- [ ] **Step 6: Run focused search/frontend tests and verify green.**
- [ ] **Step 7: Commit** only the Task 3 files with `git commit -m "feat: integrate clock type discovery surfaces"`.

### Task 4: Add synthetic frontend acceptance coverage and documentation checkpoint

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeFrontendAcceptanceTest.php`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` only if the current frontend contract checkpoint requires a status pointer
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md` only if the current frontend parity row needs a dated evidence note

**Interfaces:**
- Consumes: all shared profile, dossier, collection, route, search, and theme contract seams from Tasks 1–3.
- Produces: synthetic in-memory `Đồng hồ công cộng` render/projection acceptance evidence with no database persistence.

- [ ] **Step 1: Write the synthetic fixture tests first** for detail, archive, search, breadcrumb, object membership link, derived Brand, hierarchy, direct/derived Article/Video/Media, direct Knowledge, unavailable identity, collision, wrong family, legacy family, retired relation, and no shortcut edge.
- [ ] **Step 2: Run the new acceptance test and verify each missing behavior fails explicitly.**
- [ ] **Step 3: Complete only the minimum missing projection/template wiring** and keep the fixture entirely in memory.
- [ ] **Step 4: Run the acceptance test plus all focused Clock-Type regressions and verify green.**
- [ ] **Step 5: Update execution state** with exactly `CLOCK_TYPE_FRONTEND_IMPLEMENTED`, `LIVE_PUBLIC_IDENTITY_NOT_ALLOCATED`, and `NO_SEMANTIC_MUTATION`.
- [ ] **Step 6: Regenerate canonical documentation snapshot and manifest**, then verify documentation parity.
- [ ] **Step 7: Commit** docs and tests separately from unrelated changes with `git commit -m "docs: checkpoint clock type frontend integration"`.

### Task 5: Full verification and clean handoff

**Files:**
- No production files unless a failing test identifies an in-scope defect from Tasks 1–4.

- [ ] Run full Unit, Contract, and relevant guarded Integration suites.
- [ ] Run PHP lint on all affected PHP files and repository composer lint.
- [ ] Run `git diff --check` and a secret review.
- [ ] Regenerate canonical docs snapshot and verify manifest/hash parity again.
- [ ] Verify no Authority/Graph/Public Identity/Capture/Proposal/Article/Knowledge/Media/Video mutation occurred.
- [ ] Verify working-tree status and report commits, files, tests, docs, gaps, and final frontend status.

## Self-review

- Coverage: detail, archive, search, breadcrumb, object, Brand-derived, hierarchy, Article, Video, Media, Knowledge, SEO, fail-closed states, performance bounds, no mutation, and docs checkpoint are assigned to Tasks 1–5.
- Placeholder scan: no implementation step relies on an unspecified predicate, datastore, route allocator, or generic writer.
- Type consistency: profile filtering uses `EntityProfileResolver`; route/archive consumers remain keyed by canonical `classification` plus explicit `clock_type` profile context; public payloads never expose internal identity fields.
- Scope: no new ontology, relation, route allocation, persistence, backfill, PR7, or separate frontend stack is introduced.
