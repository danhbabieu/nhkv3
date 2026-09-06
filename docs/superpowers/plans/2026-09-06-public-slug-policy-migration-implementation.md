# Canonical Public Slug Policy and Existing-URL Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the approved canonical public slug policy, governed existing-data audit/dry-run/apply/reprojection flow, and verify every public URL consumer without changing semantic identity.

**Architecture:** A single `CanonicalPublicSlugPolicy` owns public token normalization. A governed migration service inventories registered public owners through repository interfaces, emits deterministic typed dry-run rows, resolves only meaningful collisions, and applies changes through CAS/idempotency-bound public-identity writes. A canonical route projection is then reused by frontend links, SEO, structured data, breadcrumbs, sitemap, search and Video.

**Tech Stack:** PHP 8+, PHPUnit 11, WordPress/WPDB repositories, existing NHK Core contracts and migration ledger.

**Spec:** `docs/superpowers/specs/2026-09-06-public-slug-policy-migration-design.md`

## Global Constraints

- UUID, stable key, `nhk:*` identity, database ID, external video ID, idempotency key, source key, hash, revision and internal contract identifier remain unchanged.
- `apply` is a governed writer/executor; direct SQL/search-replace is forbidden.
- Dry-run is strictly read-only and emits one deterministic row per registered public candidate.
- Apply is retry-safe, idempotent and fails closed on stale revision/state/fingerprint.
- Collision suffixes use meaningful governed context only; unresolved duplicates require manual review.
- Native WordPress editorial URLs remain WordPress-owned; legacy media filenames, article bodies, Graph edges and V2/staging/production data are untouched.
- Migration sequence is `audit → dry-run → collision detection → apply → reprojection → read-back verification`.

### Task 1: Reconcile the shared policy and Video route contract

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/CanonicalPublicSlugPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoUrlPolicy.php`
- Modify: `docs/seo/PUBLIC_URL_SLUG_CONTRACT.md`
- Modify: `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`
- Test: existing policy, route and Video URL unit tests plus new focused cases in the same test files

**Interfaces:** Preserve existing public method signatures. Ensure Video default route is semantic-only with external ID remaining metadata; all route consumers call the shared policy.

- [ ] Write failing tests for NFC/NFD Vietnamese, separators, `nhk` token boundaries, `nhk:*` preservation, separate Media filename behavior, and Video IDs absent from default canonical slugs.
- [ ] Run the focused PHPUnit tests and confirm they fail for the missing policy/contract behavior.
- [ ] Implement the smallest shared-policy and Video-route changes, retaining existing historical compatibility behavior only where covered by current contracts.
- [ ] Run focused tests until green and refactor without adding a second slugifier.

### Task 2: Add deterministic candidate inventory, collision resolution, and dry-run contract

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Migration/PublicSlugMigrationService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Migration/PublicSlugMigrationCandidate.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/PublicIdentity/PublicSlugMigrationSource.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Migration/DryRunService.php` only if shared row/status helpers are reusable
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicSlugMigrationServiceTest.php`

**Interfaces:** `PublicSlugMigrationSource::candidates(): array` returns governed records with `type,id,current_slug,current_url,title,scope,revision,fingerprint,meaningful_context,route_owner`. `PublicSlugMigrationService::audit(): array`, `dryRun(): array`, and `apply(array $rows, string $authorization, string $fingerprint): array` return deterministic count/row/typed-result arrays.

- [ ] Write failing tests proving inventory includes Authority public types, Knowledge projections, Video, WordPress post/page/category/tag owners and all other registered public candidates.
- [ ] Write failing tests asserting every dry-run row contains the required fields and distinguishes no-op, changed, collision, ambiguous, missing identity, invalid route and unavailable runtime.
- [ ] Write failing tests for unique base slugs, meaningful deterministic discriminators, unresolved duplicate/manual review, and zero writes during dry-run.
- [ ] Run the new test file and verify expected RED failures.
- [ ] Implement immutable candidate normalization, route-scope collision grouping, deterministic meaningful resolution and typed blockers.
- [ ] Run the new tests and the migration unit suite until green.

### Task 3: Implement governed CAS/idempotent apply and read-back

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/PublicIdentity/PublicIdentityRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicIdentityService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Migration/PublicSlugMigrationService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicSlugMigrationServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicIdentityServiceTest.php`

**Interfaces:** Add a repository-level migration write/read-back seam that accepts expected revision, expected current slug, source fingerprint, idempotency key and proposed route, returning `CHANGED`, `NOOP`, `STALE`, `COLLISION`, `BLOCKED` or `UNAVAILABLE` with the persisted snapshot.

- [ ] Write failing tests for first-run changes, second-run no-op, stale revision, changed source fingerprint, collision, and idempotency replay.
- [ ] Write failing identity-preservation tests for UUID, stable key and relations, plus historic-route read-back when the existing history facility applies.
- [ ] Run focused tests and verify RED is caused by absent governed migration behavior.
- [ ] Implement the narrow repository/service seam using existing revision/history conventions and no direct migration SQL.
- [ ] Run focused tests until green, then verify no semantic fields are changed.

### Task 4: Reproject and verify all canonical URL consumers

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Seo/PublicSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Seo/EntitySeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/SearchApi.php` and relevant route/link builders identified by failing tests
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicSlugReprojectionTest.php`

**Interfaces:** Every consumer receives or resolves one canonical route snapshot and must not concatenate title/slug/identifier independently. Reprojection returns affected consumer names and read-back paths.

- [ ] Write failing tests asserting canonical, OG, schema URL/`@id`, breadcrumb, sitemap, search, related/internal and frontend card links all equal one persisted route.
- [ ] Add Video-specific tests proving external ID remains available for identity/lookup but is absent from the default public slug.
- [ ] Run focused projection tests and verify RED.
- [ ] Implement shared route reuse and explicit unavailable/error states; keep native WP URLs on WP-owned surfaces.
- [ ] Run all public identity, SEO, route, search and Video tests until green.

### Task 5: Add migration integration/checkpoint tooling and documentation

**Files:**
- Create or modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/PublicSlugMigration015.php` if the migration ledger requires a schema/versioned checkpoint
- Modify: `public/wp-content/plugins/nhk-core/tests/Integration/` with exact guarded test-database coverage
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md` only for verified status evidence

- [ ] Write failing guarded integration tests for dry-run zero writes, apply counts, collision counts, CAS/idempotency and post-apply read-back.
- [ ] Run the exact guarded integration tests against `nhk_v3_test`, never development/production data.
- [ ] Add only additive migration/schema changes if required; verify migration checks and no DOWN on `nhk_v3`.
- [ ] Update execution state with evidence, not assumptions; record remaining redirect backlog explicitly if not implemented.

### Task 6: Fresh full verification, commit, merge and push

- [ ] Run focused PHPUnit, full PHPUnit, PHP lint, migration checks, `git diff --check`, secret review and route/SEO smoke checks when runtime is available.
- [ ] Re-read this plan, the spec, Constitution and execution state; report any gap or constitutional conflict.
- [ ] Commit the implementation on `main` only after fresh evidence.
- [ ] Merge/push `origin/main` according to repository policy; verify remote SHA and clean working tree.
- [ ] Report commit SHA, merge SHA, migration counts, collision counts, test results and working-tree status.
