# Clock Type Presentation Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a curated, reusable Presentation Navigation boundary for
`clock_type`, then route LOẠI index/detail/menu/homepage/admin/breadcrumbs
through that projection without changing semantic Classification ownership.

**Architecture:** A dedicated `presentation_navigation` store references
canonical Authority UUIDs. `NavigationRepository` owns persistence and
optimistic writes; `NavigationTreeProjector` owns placement-aware tree
projection and hidden-parent reattachment; Clock Type adapters consume the
projection for public routes and admin. Existing Graph, Authority, Public
Identity and semantic dossier owners remain unchanged.

**Tech Stack:** PHP 8+, WordPress plugin, `$wpdb`/`dbDelta`, PHPUnit, existing
NHK migration guard/ledger, existing public route and admin workbench seams,
theme PHP/CSS/JS.

**Spec:** `docs/superpowers/specs/2026-09-26-clock-type-presentation-navigation-design.md`

## Global Constraints

- `Classification` remains `entity_type=classification` with exact `family=clock_type`.
- Presentation Navigation stores references, not semantic identity or payload ownership.
- Semantic `subtype_of` and navigation `parent_id` remain independent.
- Only curated, enabled, placement-visible nodes may reach a presentation consumer.
- No template may query semantic archive data and filter it as a compatibility hack.
- Migration is guarded UP-only and idempotent; no live/staging semantic mutation or full Classification seed.
- Preserve unrelated pre-existing worktree changes.

## Review Focus

- Missing/ambiguous/wrong-family seed target must report review/blocked without creating Classification — test in seed task.
- A visible child under a hidden parent must project to the nearest visible ancestor or root without mutation — test in projector task.
- Optimistic revision conflict must preserve the stored row — test in repository/admin task.
- Missing navigation storage must remain unavailable rather than looking like an empty curated tree — test in integration contract task.
- Public Identity/route ineligibility must omit only the navigation item, not semantic search/dossier data — test in public adapter task.

### Task 1: Navigation domain contracts and pure projector

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/PresentationNavigation/NavigationNode.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/PresentationNavigation/NavigationPlacement.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/PresentationNavigation/NavigationRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Presentation/NavigationTreeProjector.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Presentation/InMemoryNavigationRepository.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationNavigation/NavigationTreeProjectorTest.php`

**Interfaces:** `NavigationRepository` reads `list(string $key): array` and
supports `save(NavigationNode $node, int $expectedRevision): NavigationNode`;
`NavigationTreeProjector` exposes `roots`, `children`, `breadcrumb`, and
`menu`, each accepting `navigation_key` plus a placement and returning a
stable read model with diagnostics.

- [ ] Write failing tests for root/direct-child/multi-level projection, sort order, every placement flag, disabled nodes, hidden-parent nearest-visible-ancestor, root fallback, empty-parent suppression, and semantic parent independence.
- [ ] Run the focused PHPUnit file and confirm the expected missing-class failures.
- [ ] Implement immutable node/value objects, placement mapping, in-memory repository, and pure projection algorithm.
- [ ] Re-run focused tests and verify PASS.
- [ ] Commit `feat: add presentation navigation projection core`.

### Task 2: Persistence schema, WPDB repository, guarded migration

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/PresentationNavigationMigration023.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Presentation/WpdbNavigationRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` migration imports, target and runner registration
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationNavigation/PresentationNavigationMigrationTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationNavigation/WpdbNavigationRepositoryTest.php`

**Interfaces:** WPDB repository implements Task 1 contract and validates
canonical target/profile through injected Authority/Profile resolver,
navigation-key scope, parent/cycle rules, and revision CAS. Migration creates
`{$wpdb->prefix}nhk_presentation_navigation` with the spec's unique/indexed
columns and raises the ledger to 023.

- [ ] Write failing schema/repository tests for idempotent schema, canonical target validation, parent scope/cycle rejection, sort persistence, and revision conflict preservation.
- [ ] Run focused tests to confirm RED.
- [ ] Implement migration and repository following existing guarded migration patterns; add Plugin wiring without touching unrelated pending changes.
- [ ] Run focused tests, PHP lint for changed files, and `git diff --check`.
- [ ] Commit `feat: persist presentation navigation nodes`.

### Task 3: Read projection composition and idempotent initial seed

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Presentation/ClockTypeNavigationProjection.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Presentation/ClockTypeNavigationSeed.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` composition wiring
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationNavigation/ClockTypeNavigationSeedTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationNavigation/ClockTypeNavigationProjectionTest.php`

**Interfaces:** Clock Type adapter resolves only active exact-family
Classifications and delegates placement reads to the generic projector. Seed
returns per-candidate `SEEDED`, `ALREADY_PRESENT`, `REVIEW_REQUIRED`, or
`BLOCKED` diagnostics and never creates an Authority.

- [ ] Write failing tests for all nine configured labels, unresolved/wrong-family/ambiguous targets, seed replay preserving admin edits, uncurated exclusion, and semantic UUID/stable-key/family/revision snapshot preservation.
- [ ] Run focused tests to confirm RED.
- [ ] Implement adapter, resolver callback, and seed service.
- [ ] Run focused tests and verify PASS.
- [ ] Commit `feat: add curated clock type navigation seed`.

### Task 4: Public LOẠI routes, detail children, breadcrumbs, home, menus/sidebar

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Home/HomeSemanticQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Presentation/PublicNavigationDefinition.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/FrontendSemanticBootstrap.php` or existing composition seam identified during implementation
- Modify: `public/wp-content/themes/nhk-v3/header.php`, `sidebar.php`, `entity.php`, `front-page.php`, `functions.php`, `template-parts/presentation/breadcrumbs.php`
- Test: existing frontend/entity/home route contract tests plus new `PresentationNavigationFrontendContractTest.php`

- [ ] Write failing tests proving index roots only, detail direct children only, homepage curated placement only, per-placement header/mobile/sidebar visibility, curated breadcrumb, and no semantic archive fallback.
- [ ] Run focused tests and confirm RED.
- [ ] Implement one navigation projection injection into the existing route/home/theme context; preserve fixed non-LOẠI hubs and remove only LOẠI node hard-code from `PublicNavigationDefinition`.
- [ ] Run focused tests, theme syntax checks, and inspect rendered template inputs.
- [ ] Commit `feat: route loai dong ho through curated navigation`.

### Task 5: Admin tree editor and governed presentation writes

**Files:**
- Create/modify: existing `Infrastructure/Admin` workbench registry/view/API seam located during implementation
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationNavigation/NavigationAdminTest.php`
- Modify: admin asset registration only where required

- [ ] Write failing tests for tree rendering, drag/drop parent/order payload validation, placement toggles, featured/enabled, and revision conflict response.
- [ ] Run focused tests and confirm RED.
- [ ] Implement guided admin tree UI/API using the existing internal/admin capability and Governance boundary; reject direct semantic writes and direct UI SQL.
- [ ] Run focused PHP/JS tests and syntax checks.
- [ ] Commit `feat: add clock type navigation tree admin`.

### Task 6: Migration/seed read-back and full verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: relevant frontend contract tests only for verified behavior

- [ ] Run changed-file PHP lint, focused Unit/Contract suites, full Unit suite where environment permits, `git diff --check`, and repository secret review.
- [ ] Run guarded migration/seed only in an explicitly authorized local/test runtime; if unavailable, record environment-gated without mutation.
- [ ] Run route smoke and real desktop/mobile frontend read-back; verify `/loai-dong-ho/` root-only, detail direct children, header/mobile no full expansion, sidebar and breadcrumb.
- [ ] Re-read actual templates and semantic Classification snapshots before/after projection tests.
- [ ] Update execution state with evidence and remaining gates.
- [ ] Commit verification evidence only if it contains no secrets or live identifiers beyond existing approved evidence.

## Execution order

Tasks are sequential because later consumers depend on the repository/projector
interfaces. Every task follows RED → GREEN → verification → commit. No
semantic or staging data mutation is part of this plan.
