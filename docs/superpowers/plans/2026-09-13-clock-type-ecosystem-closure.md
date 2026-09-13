# Clock Type Ecosystem Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the read/write contracts and read-only dossier projections for Clock Type hierarchy, object membership, derived Brand context, Article/Knowledge/Media/Video context, while preserving all existing owners and performing no live semantic mutation.

**Architecture:** Keep `classification` as the only Authority type and resolve Clock Type only through `EntityProfileRegistry` with persisted `family=clock_type`. Graph remains the sole relation owner; hierarchy and membership writes are proposal-bound and revision-bound, while dossier/admin/public outputs are read-only compositions over existing Authority, Graph, Article, Knowledge, Media and Video readers. Brand↔Clock Type is a bounded registered query recipe with no persisted shortcut edge.

**Tech Stack:** PHP 8+, PHPUnit 11, WordPress WPDB adapters, existing NHK V3 registries/services, canonical documentation snapshot generator.

**Spec:** `docs/superpowers/specs/2026-09-13-clock-type-organization-closure-design.md`

## Global Constraints

- Do not change ontology or add `ClockType` entity type.
- Canonical family is exactly `clock_type`; never infer family from stable key, name, title or slug.
- Preserve old `clock-type` family data as compatibility read only; do not rewrite stable keys or family values.
- Do not add predicates; reuse registered `subtype_of`, `classified_as`, `model_of`, `variant_of`, `about` and `depicts` only.
- Graph is the only relation store; no Brand↔Clock Type shortcut edge or parallel projection datastore.
- All semantic writes remain `nhk.capture.ingest`/Governance/Controlled Apply; this implementation run performs no live mutation, backfill or PR7.
- Article, Knowledge, Media and Video owners retain exact subject, scope, provenance, identity, route and readiness semantics.
- Public/Admin dossier projection is read-only and distinguishes `AVAILABLE_WITH_ITEMS`, `AVAILABLE_EMPTY`, `UNAVAILABLE_IMPLEMENTATION_GAP` and `BLOCKED`.
- Root Public Identity and route allocation/reprojection remain out of scope.
- Preserve unrelated dirty changes and stage only closure-task hunks.

---

### Task 1: Freeze the ecosystem contract and runtime matrix

**Files:**
- Create: `docs/architecture/CLOCK_TYPE_ECOSYSTEM_CONTRACT.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Contract/ClockTypeEcosystemContractTest.php`

**Interfaces:**
- Consumes: current Authority, Entity Profile, Graph, Article, Knowledge, Media, Video, Capture, Dossier, Public Identity and Governance contracts.
- Produces: one current contract declaring owner, predicate, source/target scope, write boundary and read projection for each requested Clock-Type ecosystem relation.

- [ ] **Step 1: Write the failing contract test** asserting the contract names only registered predicates, canonical `clock_type`, the four `classified_as` source types, no Brand shortcut, exact-subject Knowledge, direct/derived media distinction, explicit Video `about`, and no route allocation.
- [ ] **Step 2: Run the contract test** with `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Contract/ClockTypeEcosystemContractTest.php`; expect failure because the contract file does not exist.
- [ ] **Step 3: Add the contract** with the audited matrix, `CONTRACT_GAP` statements for unsupported Product/Specimen→Brand paths and any unavailable direct conceptual Media path, and explicit no-mutation boundary.
- [ ] **Step 4: Run the contract test** and require PASS.
- [ ] **Step 5: Update execution state** with the contract checkpoint and current gaps; do not alter historical parity claims.
- [ ] **Step 6: Commit** only the contract/test/execution-state hunk with `docs: close clock type ecosystem contract`.

### Task 2: Add canonical Clock-Type hierarchy read projection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/ClockTypeHierarchyProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/ClassificationReadModel.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeHierarchyProjectionTest.php`

**Interfaces:**
- Consumes: `AuthorityRepository`, `GraphService`, `EntityProfileResolver`, registered `subtype_of` edges.
- Produces: `project(string $clockTypeUuid): array` with `parent`, `children`, `status`, `diagnostics`, exact family checks, deterministic ordering and no writer dependency.

- [ ] **Step 1: Write failing tests** for same-family ACTIVE parent/children, missing parent as `AVAILABLE_EMPTY`, cross-family/inactive/dangling edges omitted with diagnostics, and cycle detection as `BLOCKED`/diagnostic without mutation.
- [ ] **Step 2: Run the focused test** and confirm it fails for the missing projection.
- [ ] **Step 3: Implement the projection** using bounded Graph reads, profile resolution from payload family, canonical UUID/revision read-back and deterministic name/UUID ordering; never derive family from a key.
- [ ] **Step 4: Add parent support to `ClassificationReadModel`** through the same read path while preserving existing facet separation and legacy compatibility behavior.
- [ ] **Step 5: Run focused hierarchy tests** and require PASS.
- [ ] **Step 6: Commit** with `feat: add clock type hierarchy read projection`.

### Task 3: Harden governed hierarchy and membership planning contracts

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/ClassificationHierarchyPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/ClassifiedAsPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ClockTypeMembershipPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ClockTypeMembershipGovernanceService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeEcosystemWriteContractTest.php`

**Interfaces:**
- Consumes: canonical Authority revisions, `EntityProfileResolver`, `GraphService`, existing `ClockTypeMembershipCandidate` and Governance lifecycle.
- Produces: fail-closed proposal packets for `subtype_of` and `classified_as` with exact source/target UUID+revision, family, provenance and duplicate/retired-edge rules.

- [ ] **Step 1: Write failing tests** for all four membership sources, Brand/Movement rejection, canonical-vs-legacy family behavior, exact source/target revision binding, retired edge non-revival, active duplicate reuse, hierarchy self/cross-family/cycle rejection, and no writer calls from planners.
- [ ] **Step 2: Run focused write-contract tests** and confirm failures identify missing behavior rather than test setup errors.
- [ ] **Step 3: Implement minimal validation** so `subtype_of` accepts only ACTIVE Classification nodes resolved to the same canonical family and `classified_as` accepts only Model/Variant/Specimen/Product sources to Classification Clock Type targets.
- [ ] **Step 4: Ensure proposal payloads retain source/target UUID, source/target revision, predicate, scope, provenance, candidate identity and dependency revisions; do not add a new predicate or entity type.
- [ ] **Step 5: Run focused tests plus existing `ClockTypeMembershipPr5Test.php` and `GraphCoreContractTest.php`; require PASS.
- [ ] **Step 6: Commit** with `test: close clock type governed relation contracts`.

### Task 4: Complete bounded derived Brand↔Clock Type projection

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/ClockTypeDerivedRelationshipQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/BrandAggregationQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeDerivedRelationshipQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/BrandAggregationQueryTest.php`

**Interfaces:**
- Consumes: Graph read boundary, Authority read boundary, `EntityProfileResolver`, existing `model_of`, `variant_of`, `classified_as` paths.
- Produces: deterministic `DIRECT`/`DERIVED` items with bounded path, hop count, alternative paths and ranking reason; no persisted shortcut relation.

- [ ] **Step 1: Write failing tests** for canonical underscore-family inclusion, legacy hyphen-family compatibility exclusion from canonical projection, Brand→Clock Type and Clock Type→Brand derived paths, duplicate path collapse, inactive/dangling omission, bounded failure, and no `brand classified_as classification` edge.
- [ ] **Step 2: Run focused tests** and record the current legacy-family failure.
- [ ] **Step 3: Implement profile-resolved canonical filtering and deterministic sorting** in the derived query; retain only registered Model/Variant structural paths and report Product/Specimen Brand paths as unavailable rather than inferred.
- [ ] **Step 4: Keep `BrandAggregationQuery` compatibility output behavior while preventing it from treating legacy `clock-type` as canonical Clock-Type truth; add explicit diagnostic where needed.
- [ ] **Step 5: Run focused Brand/Clock-Type tests and existing PR1 regressions; require PASS.**
- [ ] **Step 6: Commit** with `feat: complete derived clock type brand projection`.

### Task 5: Add profile-aware Clock-Type dossier composition

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/ClockTypeDossierProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticProfileComposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfilePublicDossier.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeDossierProjectionTest.php`

**Interfaces:**
- Consumes: existing dossier reader, hierarchy projection, derived Brand query, `RelatedSemanticQuery`, `EntityKnowledgeProjection`, `EntityMediaProjection`, public eligibility/route readers.
- Produces: read-only Clock-Type dossier sections: identity, hierarchy, exact Knowledge, direct/derived Article/Media/Video context, Model/Variant/Specimen/Product membership, derived Brands, diagnostics and explicit section states.

- [ ] **Step 1: Write failing dossier tests** covering the canonical `Đồng hồ công cộng` shape, child/parent hierarchy, all object membership buckets, direct Article/Video/Media, derived object Video/Media, exact Knowledge subject, Brand-derived context, brandless objects and explicit unavailable/blocked states.
- [ ] **Step 2: Run the focused dossier test** and confirm missing Clock-Type composition behavior.
- [ ] **Step 3: Implement `ClockTypeDossierProjection`** as a pure composition layer with injected read-only collaborators; expose only reader-safe fields and preserve origin path metadata.
- [ ] **Step 4: Wire it through the existing dossier bootstrap** without adding a persistence store, route allocator or writer dependency.
- [ ] **Step 5: Add `direct` and `derived` media buckets** from existing usage/Graph projections; do not turn MediaUsage into `depicts` or Evidence.
- [ ] **Step 6: Update `SemanticProfileComposer` and `EntityProfilePublicDossier`** to preserve profile-specific section order and states while retaining Brand behavior.
- [ ] **Step 7: Run focused dossier tests and existing Brand/Article/Media/Video/Knowledge dossier regressions; require PASS.**
- [ ] **Step 8: Commit** with `feat: compose clock type dossier projections`.

### Task 6: Add article, Knowledge, Media and Video golden semantics

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeEcosystemGoldenRegressionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleSemanticDossierTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoCoreTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticDossierTest.php`

**Interfaces:**
- Consumes: existing owner services and read projections; no production writer changes unless a test exposes a real contract defect.
- Produces: regression proof that direct Clock-Type subject is allowed only when explicit, object-specific subjects remain object-specific, and reachable context never promotes semantic truth.

- [ ] **Step 1: Write failing tests** for direct Article `about` to Classification, object Article remaining object subject, exact Knowledge subject/scope/provenance, specific-object Media `depicts`, explicit Video `about` to Clock Type, and Video/Media/Knowledge identity preservation.
- [ ] **Step 2: Run focused golden tests** and confirm failures are limited to missing projection/contract seams.
- [ ] **Step 3: Implement only minimal adapter changes** needed to pass through existing registered predicates and owner readers; never alter `Video about`, MediaUsage semantics, Claim subject or SEO/route data.
- [ ] **Step 4: Add duplicate-edge and upward-promotion assertions** for all golden paths.
- [ ] **Step 5: Run all focused golden tests and require PASS.**
- [ ] **Step 6: Commit** with `test: protect clock type ecosystem semantics`.

### Task 7: Documentation snapshot, execution checkpoint and verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md` only if a dated evidence row is strictly required
- Generated: `public/wp-content/plugins/nhk-core/resources/canonical-docs/**`
- Test: no new production behavior; use existing unit/contract/integration suites

**Interfaces:**
- Consumes: completed tests, current runtime registries and all relevant contract files.
- Produces: canonical documentation version/manifest parity and a no-mutation closure report with typed current gaps.

- [ ] **Step 1: Run full Unit, Contract and relevant Integration suites**; record exact test/assertion counts and classify failures `INTRODUCED`, `PRE_EXISTING` or `ENVIRONMENT_BLOCKED`.
- [ ] **Step 2: Run Composer lint, PHP lint on affected files, `git diff --check`, secret review and existing frontend/route/semantic regression commands.
- [ ] **Step 3: Regenerate the canonical documentation snapshot** with `composer generate:mcp-docs`; bootstrap/read it fresh and compare documentation version and manifest hash.
- [ ] **Step 4: Perform fresh read-only runtime verification** for profile resolution, predicate registry, hierarchy/membership plan packets, derived Brand query and Clock-Type dossier; verify database row counts are unchanged by this task.
- [ ] **Step 5: Update `V3_EXECUTION_STATE.md`** with the evidence, explicit remaining contract/runtime gaps and no live mutation statement.
- [ ] **Step 6: Review `git diff`** and stage only closure-task files; preserve unrelated dirty changes.
- [ ] **Step 7: Commit** with `docs: close clock type ecosystem checkpoint` only after verification is green.

## Self-review checklist

- [x] Spec coverage: hierarchy, four membership sources, derived Brand projection, Article, Knowledge, Media, Video, dossier, Governance, regressions and no-mutation guard each have a task.
- [x] Ontology safety: no task adds an Authority type, Graph predicate, taxonomy shortcut or Public Identity route.
- [x] Family safety: all writes/read profiles resolve persisted `family`; legacy `clock-type` is never normalized or treated as canonical.
- [x] Owner safety: Graph, Article, Knowledge, Media and Video keep their existing owners and semantic fields.
- [x] Governance safety: relation proposals retain exact revisions and all apply paths remain governed; this run only tests/plans and performs no apply.
- [x] Projection safety: dossier/admin/public outputs are compositions only and preserve direct/derived origin plus unavailable/blocked distinctions.
- [x] Placeholder scan: no unfinished marker, invented method, or unspecified edge case appears in the plan.
- [x] Verification coverage: full Unit/Contract/relevant Integration, lint, diff check, documentation parity, runtime read verification and working-tree review are explicit.
