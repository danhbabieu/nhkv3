# Public Routing and SEO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace public stable-key/English semantic URLs with canonical Vietnamese routes while preserving safe one-hop redirects and SEO contracts.

**Architecture:** A pure `PublicRouteResolver` maps registered semantic entities to public slugs and parent-aware paths. HTTP routes and the theme consume that boundary; stable keys and UUIDs remain internal lookup inputs only.

**Tech Stack:** PHP 8+, PHPUnit 11, WordPress rewrite/template hooks, existing NHK V3 Authority/Graph/query contracts.

**Spec:** `docs/superpowers/specs/2026-09-01-public-routing-seo-design.md`

## Global Constraints

- Do not modify WordPress article bodies or populate semantic data.
- Do not invent entity types, predicates, fields or relation types.
- Ambiguous identity, slug collisions and missing parent context fail closed.
- WordPress Posts remain the editorial title/body/URL source of truth.
- `nhk_v3_test` is the only destructive integration database; this slice is read-only.

### Task 1: Canonical resolver contract

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteResolverTest.php`

- [ ] Write failing tests for Brand root, Model/Variant hierarchy, Vietnamese namespaces, reserved roots, and ambiguous slugs.
- [ ] Run `vendor/bin/phpunit --filter PublicRouteResolverTest` and confirm failure because the resolver does not exist.
- [ ] Implement the minimal resolver against `AuthorityRepository` and the existing `EntityTypeRegistry`.
- [ ] Run the focused test and the full unit suite.
- [ ] Commit the resolver checkpoint.

### Task 2: Route and template integration

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/LegacyUrlRedirects.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/themes/nhk-v3/functions.php`
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/themes/nhk-v3/front-page.php`
- Modify: `public/wp-content/themes/nhk-v3/header.php`
- Modify: `public/wp-content/themes/nhk-v3/sidebar.php`
- Modify: `public/wp-content/themes/nhk-v3/comparison.php`

- [ ] Add route-contract assertions before changing consumers.
- [ ] Run the focused contract tests and confirm the expected failures.
- [ ] Wire the resolver into canonical href, detail matching, breadcrumbs and SEO canonical generation.
- [ ] Add Vietnamese namespace aliases and one-hop legacy redirect coverage without changing editorial data.
- [ ] Run route smoke, PHPUnit and lint.
- [ ] Commit the integration checkpoint.

### Task 3: Constitution and audit evidence

**Files:**
- Create: `docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md`
- Modify: `docs/constitution/10_PUBLICATION_SEO_FRONTEND_LAW.md`
- Modify: `docs/constitution/03_BRAND_BACKBONE_LAW.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

- [ ] Record every discovered route, owner, conflict and canonical target, including unresolved data-gated cases.
- [ ] Update the public SEO and Brand laws with the approved route contract and resolver boundary.
- [ ] Add acceptance tests for canonical links, no identity leakage, redirects, breadcrumbs and sitemap exclusions.
- [ ] Run full verification, PHP lint, `git diff --check`, and secret review.
- [ ] Commit the documentation and evidence checkpoint.
