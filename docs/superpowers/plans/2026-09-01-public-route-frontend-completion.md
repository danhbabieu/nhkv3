# NHK canonical public routes and frontend completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete slug-safe public routing and the shared frontend cleanup required by the accepted checkpoint.

**Architecture:** Keep UUID/stable identity internal and centralize presentation slugs in `PublicRouteResolver`. Expose only legitimate public projections: Authority and Video pages are indexable when resolvable; MediaAsset remains an asset URL and Media is non-indexable unless a real public projection exists; atomic Knowledge Claims remain non-indexable unless an existing public projection is present. Normalize the theme through shared tokens and one responsive rule set.

**Tech Stack:** PHP 8+, WordPress rewrite/template hooks, PHPUnit, CSS custom properties, existing NHK Authority/Graph/query contracts.

**Spec:** `docs/superpowers/specs/2026-09-01-public-routing-seo-design.md` and accepted checkpoint brief `27c79ae`.

## Global Constraints

- Do not invent entity types, predicates, fields, relations, or fake public pages.
- Preserve unrelated changes in `docs/architecture/V3_EXECUTION_STATE.md` and `docs/superpowers/plans/2026-09-01-sync-local-v3-to-demo.md`.
- No UUID, stable key, graph key, or internal namespace may appear in an indexable canonical URL.
- WordPress Posts remain editorial title/body/URL truth.
- MediaAsset binary delivery, Media semantic identity, and MediaUsage are separate concerns.
- Run focused tests, full unit tests, PHP lint, `git diff --check`, and secret review before each checkpoint commit.

### Task 1: Shared public slug contract and route coverage

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverTest.php`

- [x] Add tests for Vietnamese normalization, reserved roots, collision fail-closed behavior, all registered Authority types, and no identity token in returned paths.
- [x] Implement one slug normalizer and resolver helpers that match canonical presentation slugs only; keep ambiguous names unresolved.
- [x] Add Video route resolution through existing Video identity/query contracts, with `/video/{slug}/` and legacy UUID/stable-key redirect only when uniquely resolvable.
- [x] Make Media and Knowledge route decisions explicit in query/context APIs: no public detail route for atomic Media/Claim without an existing legitimate projection; preserve asset delivery separately.
- [x] Update rewrites, canonical links, breadcrumbs, search links, and sitemap-facing paths to use the resolver and one-hop redirects.
- [ ] Run focused route tests and commit `feat: complete NHK canonical public route contracts`.

### Task 2: Constitution and route matrix

**Files:**
- Create: `docs/constitution/15_FRONTEND_DESIGN_UX_LAW.md`
- Modify: `docs/constitution/NHK_V3_CONSTITUTION_INDEX.md`
- Modify: `docs/constitution/START_HERE.md`
- Modify: `AGENTS.md`
- Modify: `docs/constitution/05_MEDIA_IMAGE_LAW.md`
- Modify: `docs/constitution/06_VIDEO_LAW.md`
- Modify: `docs/constitution/07_KNOWLEDGE_SOURCE_EVIDENCE_LAW.md`
- Modify: `docs/constitution/10_PUBLICATION_SEO_FRONTEND_LAW.md`
- Create or modify: `docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md`

- [x] Record the complete Brand/Model/Variant/Movement/Music/Component/Classification/Specimen/Product/Video/Knowledge/Media/Post matrix with public-page, canonical pattern, slug source, indexability, legacy behavior, resolver support, and tests.
- [ ] Record Media’s non-page/asset distinction and Knowledge Claim versus Post editorial projection decision.
- [x] Define stable, lowercase ASCII, Vietnamese-normalized, collision-safe, reserved-root-safe slug behavior and supported history redirects.
- [x] Add the frontend law covering Vietnamese-first UI, type scale, rhythm, containers, grids, image ratios, accessibility, and readable content-page typography.
- [ ] Commit `docs: add NHK V3 frontend design constitution`.

### Task 3: Shared frontend tokens and homepage cleanup

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/style.css`
- Modify: `public/wp-content/themes/nhk-v3/entity.css`
- Modify: `public/wp-content/themes/nhk-v3/knowledge.css`
- Modify: `public/wp-content/themes/nhk-v3/media-video.css`
- Modify: `public/wp-content/themes/nhk-v3/front-page.php`
- Modify: `public/wp-content/themes/nhk-v3/functions.php`

- [x] Add shared font, spacing, container, grid, radius, and image-ratio tokens; cap normal desktop H1 at 48px and mobile H1 at 36px.
- [x] Remove duplicated responsive blocks and repeated margin overrides; retain one mobile breakpoint strategy with 390px-safe gutters and no horizontal overflow.
- [x] Localize public technical labels without renaming internal PHP/registry identifiers.
- [x] Make homepage modules data-driven from existing HomeSemanticQuery results, hide empty sections, and prioritize Vietnamese discovery links for Brand, Model/Dòng, Knowledge/Post, Movement/Music, Video, and available Specimen/collection surfaces.
- [ ] Commit `style: normalize NHK frontend typography and spacing` and `fix: refine NHK homepage responsive layout` where the changes separate cleanly.

### Task 4: SEO, harness, and verification evidence

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/functions.php`
- Modify: route and frontend tests as needed
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/CUTOVER_READINESS_REPORT.md`

- [x] Ensure canonical, OpenGraph, breadcrumb, sitemap, search, MCP, and Admin links never emit UUID/stable-key URLs and use the same public resolver decisions.
- [x] Inspect the actual integration bootstrap and run the exact `NHK_WP_TEST_PATH` invocation; local DB availability is the remaining environment blocker.
- [x] Run unit suite, guarded integration suite, PHP lint, `git diff --check`, secret review, route smoke, and browser visual checks at 1440/1280/1024/768/390; browser/route smoke remains environment-gated.
- [x] Record pass/fail evidence and remaining external blockers without claiming production readiness.
- [ ] Commit `seo: align canonical metadata with public routes` and final execution-state evidence.
