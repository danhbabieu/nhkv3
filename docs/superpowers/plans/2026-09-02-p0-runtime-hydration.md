# P0 Runtime Hydration and Deployment Reliability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore valid Authority hydration and make runtime/deployment failures observable without changing semantic data.

**Architecture:** Extract explicit row hydration from the WPDB repository, catching only known malformed-row/domain validation failures while allowing infrastructure/programming failures to propagate. Add a registry-backed parity service used by layered HealthCheck and a standalone read-only deployment preflight.

**Tech Stack:** PHP 8.1+, WordPress/WPDB, Symfony UID, PHPUnit 11, Composer root project.

**Spec:** `docs/architecture/P0_RUNTIME_HYDRATION_SPEC.md`

## Global Constraints

- The repository root Composer installation is the only dependency installation boundary.
- Do not create `public/wp-content/plugins/nhk-core/vendor/`.
- Do not import, restore, seed, migrate, or rewrite database/domain/Graph data for this fix.
- Row-level malformed data may be omitted with a precise reason; infrastructure/programming failures must propagate.
- Registry-driven checks must inspect every registered Authority type; zero records are valid.
- Preserve unrelated server `public/error_log`; never reset, clean, overwrite, delete, or commit it.
- Update existing canonical docs and `docs/architecture/V3_EXECUTION_STATE.md`; do not create competing architecture law.

### Task 1: Lock the hydration and parity contracts with failing tests

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityHydrationTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityParityAuditTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/HealthCheckTest.php`

**Interfaces:**
- Tests will define the required behavior for `AuthorityRowHydrator::hydrate()` and `AuthorityParityAudit::run()`.

- [ ] **Step 1: Write tests for valid binary UUID and malformed-neighbor behavior.** Assert one valid row hydrates with the exact RFC UUID and one malformed row returns a bounded row error while a neighboring valid row remains returned by the collection path.
- [ ] **Step 2: Write a test with a throwing UUID dependency seam.** Assert a programming/infrastructure `Error` is not converted into `null` or an empty collection.
- [ ] **Step 3: Write registry-driven parity tests.** Register Brand, Music, Model and Variant plus an empty registered type; assert all registered types appear and valid query rows equal hydrated rows.
- [ ] **Step 4: Replace the placeholder HealthCheck test.** Assert storage/runtime/hydration/application/REST keys and reason-code shape, and assert zero physical rows do not fail generic health.
- [ ] **Step 5: Run the focused tests and confirm they fail for missing production interfaces.**

### Task 2: Implement explicit Authority hydration and parity audit

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Authority/AuthorityRowHydrator.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Authority/AuthorityParityAudit.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Authority/WpdbAuthorityRepository.php`

**Interfaces:**
- `AuthorityRowHydrator::hydrate(array $row): AuthorityEntity` throws only known row-data/domain exceptions for malformed rows; it does not catch `Throwable` broadly.
- `AuthorityParityAudit::run(EntityTypeRegistry $types, AuthorityRepository $authority): array` returns one status record per registry type.

- [ ] **Step 1: Implement the minimal hydrator.** Decode JSON with `JSON_THROW_ON_ERROR`, validate persisted state and construct `AuthorityEntity` from binary UUID; catch only `JsonException`, `InvalidArgumentException`, `ValueError` and `InvalidEndpointReference` as row-data failures, rethrow all other failures.
- [ ] **Step 2: Make repository single-row reads use the hydrator.** Return `null` only for the explicit row-data exception boundary; allow `Error`, `TypeError`, missing classes and unexpected exceptions to escape.
- [ ] **Step 3: Make collection reads distinguish malformed rows.** Omit malformed rows, record a bounded reason through an injectable optional logger/callback, and never hide query/runtime failure.
- [ ] **Step 4: Implement parity audit from `EntityTypeRegistry::all()`.** Count physical rows with WPDB, compare hydrated/query counts, use `EMPTY_VALID` for legitimate zero-row types, and use `HYDRATION_LOSS` when valid physical rows are omitted.
- [ ] **Step 5: Run focused tests to green, then refactor only while green.**

### Task 3: Extend the existing layered HealthCheck

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Shared/Health/HealthCheck.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/HealthCheckTest.php`

**Interfaces:**
- Existing `/nhk/v1/health` route remains unchanged.
- `HealthCheck::read()` adds `layers.storage`, `layers.runtime`, `layers.hydration`, `layers.application`, and `layers.rest` without using entity-count thresholds.

- [ ] **Step 1: Add constructor dependencies as optional injected probes.** Keep production defaults rooted in Composer, WordPress, `MigrationStatus`, registry and repository; tests can provide deterministic probes.
- [ ] **Step 2: Implement bounded layer checks.** Runtime checks root `vendor/autoload.php`, `class_exists('Symfony\\Component\\Uid\\Uuid')`, and required NHK classes; hydration uses parity capability; application checks repository query; REST checks route/bootstrap readiness.
- [ ] **Step 3: Preserve existing top-level storage/migration fields and add stable reason codes.** Do not declare zero entity rows unhealthy.
- [ ] **Step 4: Run HealthCheck focused tests and the existing unit suite.**

### Task 4: Add read-only deployment preflight

**Files:**
- Create: `tools/deployment-preflight.php`
- Modify: `composer.json`
- Create or modify: `docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md`
- Modify: `docs/constitution/05_BOUNDARIES_AND_PROJECTIONS.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

**Interfaces:**
- Command: `php tools/deployment-preflight.php [--root=/absolute/repository/root]`
- Exit 0 only when all checks pass; exit 1 with machine-readable check names and reason codes otherwise.

- [ ] **Step 1: Write a shell-level test fixture or direct unit coverage for missing autoload and missing class failures.**
- [ ] **Step 2: Implement preflight checks.** Verify intended HEAD, `composer.lock`, root `vendor/autoload.php`, Symfony UID, WordPress bootstrap, nhk-core bootstrap, expected migration, Authority capability and REST bootstrap; never mutate database state.
- [ ] **Step 3: Add a Composer script entry using the repository root dependency contract.**
- [ ] **Step 4: Document the safe staging sequence and explicit preservation of `public/error_log`.**
- [ ] **Step 5: Record the separate Graph Backbone status without changing edges.**

### Task 5: Full verification and release checkpoint

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] **Step 1: Run PHPUnit unit tests, guarded integration tests when available, PHP lint, `git diff --check`, and secret review.**
- [ ] **Step 2: Run deployment preflight and registry-wide parity smoke against the local runtime.**
- [ ] **Step 3: Run REST and frontend Brand/Music/Model/Variant/Movement read smokes without data mutation.**
- [ ] **Step 4: Review the diff against the spec and record exact results, blockers, local/origin/server HEADs, and Graph status.**
- [ ] **Step 5: Commit logical checkpoints; attempt push and staging synchronization only if the environment provides the required external access, otherwise record the precise blocker.**
