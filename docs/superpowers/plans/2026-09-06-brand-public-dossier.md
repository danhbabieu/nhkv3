# Brand Public Dossier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the existing semantic dossier into public entity detail pages and make Brand detail aggregation complete across approved structural paths without changing semantic ownership.

**Architecture:** `SemanticDossierQuery` remains the unified detail read-model. `BrandAggregationQuery` becomes the explicit Brand structural recipe reader for paths longer than the generic two-hop relation query; `PublicEntityCollectionQuery` attaches the dossier only on detail reads; `Plugin` wires all dependencies; `entity.php` renders dossier structural groups instead of suppressing them.

**Tech Stack:** PHP 8.2, WordPress, PHPUnit 11, existing NHK V3 Authority/Graph/Knowledge/Media/Video repositories.

**Spec:** `docs/superpowers/specs/2026-09-06-brand-public-dossier-design.md`

## Global Constraints

- Constitution and current V3 contracts remain authoritative.
- All new behavior is read-only projection; no semantic repair, backfill, migration, or direct SQL.
- Generic `RelatedSemanticQuery::MAX_HOPS` remains `2`.
- Child claims remain owned by child subjects and never become direct Brand claims.
- Public packets must not expose canonical UUIDs, stable keys, lifecycle, state, or revision.
- Archives must stay lightweight and must not execute the full dossier assembler.

---

### Task 1: Prove Brand recipe gaps with a failing unit test

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/BrandAggregationQueryTest.php`
- Modify if needed: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticDossierQueryTest.php`

**Interfaces:**
- Consumes: `BrandAggregationQuery::forBrand(string $brandId): array`
- Produces: expected Brand aggregation for Model, Variant, Movement, and Music with `origin` metadata.

- [ ] **Step 1: Add a Brand fixture with Model -> Brand, Variant -> Model, Variant -> Movement, Movement -> Music, and Variant -> Music edges.**
- [ ] **Step 2: Assert Model and Variant are present as today, then assert Movement and Music are present with exact predicate paths.**
- [ ] **Step 3: Assert duplicate Music reached by two paths is returned once with deterministic best origin.**
- [ ] **Step 4: Run `vendor/bin/phpunit --filter BrandAggregationQueryTest` and verify RED because Movement/Music are absent.**
- [ ] **Step 5: Commit the RED test evidence.**

### Task 2: Implement approved Brand structural path recipes

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/BrandAggregationQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/BrandAggregationQueryTest.php`

**Interfaces:**
- Produces per-group public items shaped as `['type'=>string,'name'=>string,'url'?:string,'origin'=>['kind'=>string,'path'=>list<string>,'hop_count'=>int]]`.

- [ ] **Step 1: Reuse the existing inbound `model_of` and `variant_of` traversal.**
- [ ] **Step 2: From each public Variant, traverse outgoing `uses_movement` and add public Movements using origin path `[model_of, variant_of, uses_movement]`.**
- [ ] **Step 3: From each public Variant, traverse outgoing `configured_with_music` and add public Music using `[model_of, variant_of, configured_with_music]`.**
- [ ] **Step 4: From each public Movement reached by the Brand recipe, traverse outgoing `supports_music` and add public Music using `[model_of, variant_of, uses_movement, supports_music]`.**
- [ ] **Step 5: Keep existing direct `about` behavior and direct-before-derived / shortest-path deduplication.**
- [ ] **Step 6: Run focused test and verify GREEN.**
- [ ] **Step 7: Run all Brand aggregation and graph relation unit tests.**
- [ ] **Step 8: Commit.**

### Task 3: Prove dossier wiring is absent with a failing contract test

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticDossierQueryTest.php`

**Interfaces:**
- `PublicEntityCollectionQuery` detail reads should expose `dossier`; archive reads should not.
- `SemanticDossierQuery` should accept Brand aggregation and merge structural sections.

- [ ] **Step 1: Add a source-order/wiring assertion requiring runtime construction of `EntityKnowledgeProjection`, `RelatedSemanticQuery`, and `SemanticDossierQuery` before `PublicEntityCollectionQuery`.**
- [ ] **Step 2: Add a collection test whose injected dossier reader returns a sentinel dossier; assert `detailForEntity()` contains it and `archive()` does not call it.**
- [ ] **Step 3: Add a dossier test asserting Brand relation sections contain Movement/Music from the Brand recipe while direct Brand Knowledge remains subject-scoped.**
- [ ] **Step 4: Run the three focused tests and verify RED for missing constructor/wiring behavior.**
- [ ] **Step 5: Commit RED evidence.**

### Task 4: Wire the unified dossier read-model into public detail runtime

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicEntityCollectionQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: files from Task 3.

**Interfaces:**
- `SemanticDossierQuery::__construct(..., ?BrandAggregationQuery $brandAggregation = null)`
- `PublicEntityCollectionQuery::__construct(..., ?EntityKnowledgeProjection $entityKnowledge = null, ?SemanticDossierQuery $dossier = null)`

- [ ] **Step 1: Add optional `BrandAggregationQuery` dependency to `SemanticDossierQuery`.**
- [ ] **Step 2: For Brand only, merge recipe groups into `relation_sections`, converting aggregation items to dossier relation item shape (`type`, `title`, `url`, `origin`).**
- [ ] **Step 3: Deduplicate with DIRECT first, then lower hop count, preserving the generic dossier's existing safe resolution rules.**
- [ ] **Step 4: Add optional dossier dependency to `PublicEntityCollectionQuery`; on detail reads attach `dossier = $this->dossier->forEntity($entity)`. Do not call it from archives.**
- [ ] **Step 5: In `Plugin`, construct public Knowledge repositories before collection wiring; construct `EntityKnowledgeProjection`, `EntityMediaProjection`, `RelatedSemanticQuery`, `BrandAggregationQuery`, then `SemanticDossierQuery`, then `PublicEntityCollectionQuery`.**
- [ ] **Step 6: Pass the existing public Media/Video repositories and post projector dependencies already available in runtime. If WordPress post projection is unavailable at construction, use the dossier's existing WordPress-safe default projector behavior rather than a write path.**
- [ ] **Step 7: Run focused tests and verify GREEN.**
- [ ] **Step 8: Run `PluginBootWiringTest`, `PublicEntityCollectionQueryTest`, `SemanticDossierQueryTest`, `EntityKnowledgeProjectionTest` if present, and `EntityMediaProjectionTest`.**
- [ ] **Step 9: Commit.**

### Task 5: Make frontend consume Brand dossier structural sections without duplication

**Files:**
- Modify: `public/wp-content/themes/nhk-v3/entity.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendPresentationContractTest.php`

**Interfaces:**
- Consumes: `entity['dossier']['profile']['relation_sections']` as primary source.
- Legacy `entity['aggregation']` is fallback only when dossier is absent.

- [ ] **Step 1: Add failing template contract assertions that Brand no longer unsets `models`, `variants`, `movements`, `music`, or other dossier structural groups.**
- [ ] **Step 2: Add failing assertion that legacy Brand aggregation block is gated to dossier absence, preventing duplicate structural cards.**
- [ ] **Step 3: Run `FrontendPresentationContractTest` and verify RED.**
- [ ] **Step 4: Remove Brand-only relation-section suppression.**
- [ ] **Step 5: Render legacy `aggregation` only when `$dossier === null`; when dossier exists use profile `section_order` and relation sections.**
- [ ] **Step 6: Keep Direct/Derived labels and expose `via_types`/origin path context where available.**
- [ ] **Step 7: Run focused frontend contract test and verify GREEN.**
- [ ] **Step 8: Commit.**

### Task 6: Add end-to-end Brand dossier acceptance fixture and coverage count assertions

**Files:**
- Create or modify: `public/wp-content/plugins/nhk-core/tests/Unit/BrandPublicDossierAcceptanceTest.php`
- Modify if needed: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticDossierCoverageAuditTest.php`

**Interfaces:**
- Exercises real in-memory Authority + Graph + Knowledge + Evidence + Source + Media + Video repositories and the same application readers used by runtime.

- [ ] **Step 1: Build one Brand fixture with one Model, one Variant, one Movement, one Music reachable by two approved paths, one direct Brand claim, one child Movement claim, one Evidence/Source, one representative image, one direct Video relation, and one Article relation when WordPress projection can be safely represented by a closure.**
- [ ] **Step 2: Assert Brand dossier counts exactly match fixture repositories: 1 Model, 1 Variant, 1 Movement, expected unique Music, 1 direct Brand claim, 1 Evidence, 1 Media, 1 Video, 1 Article.**
- [ ] **Step 3: Assert Movement child claim text is absent from Brand `knowledge.facets`.**
- [ ] **Step 4: Assert public serialized profile contains no internal UUID/stable key.**
- [ ] **Step 5: Assert every structural child has origin metadata and the Movement path is exactly three predicates.**
- [ ] **Step 6: Run acceptance test and verify GREEN after Tasks 2-5.**
- [ ] **Step 7: Run the full NHK Unit suite.**
- [ ] **Step 8: Run PHP lint and `git diff --check`.**
- [ ] **Step 9: Commit.**

### Task 7: Document the reusable entity projection recipe contract

**Files:**
- Create: `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/constitution/READ_FIRST.md` only if the current routing index requires this contract to be read for entity/public projection work.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SeoDocumentationContractTest.php` or a more appropriate documentation contract test.

**Interfaces:**
- Documents Brand recipe paths, direct-vs-derived semantics, Knowledge scope, Media/Video/Article projection, and future extension to Model/Movement/Variant.

- [ ] **Step 1: Add failing documentation-contract test for the new current contract path if current test conventions require explicit indexing.**
- [ ] **Step 2: Write the contract with exact allowed Brand path recipes and anti-promotion rules.**
- [ ] **Step 3: Update current documentation index and read-order routing.**
- [ ] **Step 4: Run documentation contract tests.**
- [ ] **Step 5: Commit.**

### Task 8: Fresh verification and merge gate

**Files:** none unless verification finds a regression.

- [ ] **Step 1: Run full Unit suite on exact feature HEAD.**
- [ ] **Step 2: Run `composer lint` and `git diff --check`.**
- [ ] **Step 3: Attempt Integration/full suite in the available GitHub Actions runtime. Classify environment blockers separately from regressions; never edit expectations to hide a regression.**
- [ ] **Step 4: Review feature diff for semantic-owner violations, hidden ID leakage, unbounded traversal, duplicate frontend rendering, and archive performance regressions.**
- [ ] **Step 5: Open PR to `main`, record RED/GREEN evidence and any environment-blocked integration evidence.**
- [ ] **Step 6: Merge only if feature tests/unit/lint/diff are green and integration failures, if any, are proven environment/pre-existing rather than feature regressions.**
- [ ] **Step 7: Verify final `main` SHA and branch cleanup.**
