# Clock Type Organization Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with TDD and verification checkpoints.

**Goal:** Complete the read-only organization and individual governed creation
seams for Brand and Clock Type while preserving the existing ontology and
preventing PR7 or legacy mutation.

**Architecture:** Reuse the existing Semantic Core, Entity Profile registry,
Public Identity owner, Graph registry, Authority planner and Governance/
Controlled Apply lifecycle. Add only narrow read interfaces and read-model
adapters; no new Authority type, Graph predicate, semantic datastore, route
allocation writer or bulk migration is introduced.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress plugin runtime, existing WPDB
repositories, Composer autoload, canonical documentation snapshot generator.

**Spec:** `docs/superpowers/specs/2026-09-13-clock-type-organization-closure-design.md`

## Global Constraints

- `Clock Type = entity_type: classification + family: clock_type + profile: clock_type`.
- Stable key `nhk:classification:clock-type.*` is independent from family and never proves family.
- Existing `family=clock-type` records are compatibility-read only; do not rewrite old stable keys or families.
- `ClockTypeClassificationAudit` may depend only on `AuthorityInventoryReader`, `ClassificationTargetInventoryReader`, `GraphReader` and `ClockTypeAuditEvidenceReader`.
- `EntityProfileRegistry` is the central profile seam; other Classification families do not inherit Clock-Type capabilities.
- Root route work is read/collision organization only; no allocation, reprojection, redirect migration, sitemap mutation or live route change.
- Admin output is read-only projection; no semantic truth or parallel datastore.
- “Thêm loại …” is search/reuse first, then canonical Authority PLAN, owner approval, eligibility, Controlled Apply and canonical read-back.
- `subtype_of` is only same-family active, cycle-free Clock-Type hierarchy.
- Preserve Video `about`, Media `depicts`, Knowledge subject/provenance/evidence semantics and all existing regressions.
- Preserve pre-existing dirty/history changes; stage only files belonging to the closure task in each commit.
- No live semantic mutation, SQL workaround, generic writer fallback, legacy bulk apply/backfill or PR7.

---

### Task 1: Close PR6.1 audit read-only boundaries

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Authority/AuthorityInventoryReader.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Authority/ClassificationTargetInventoryReader.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Graph/GraphReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Authority/CursorAuthorityInventoryReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Authority/WpdbAuthorityRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/GraphService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/ClockTypeClassificationAudit.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/CanonicalKnowledgeEvidenceAuditReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Audit/WpdbClockTypeClassificationAuditFactory.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeClassificationAuditTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CanonicalKnowledgeEvidenceAuditReaderTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeAuditSurfaceContractTest.php`

**Interfaces:**
- `AuthorityInventoryReader::pageByType(string $type, int $limit = 100, ?string $after = null, bool $includeRetired = false): array`.
- `ClassificationTargetInventoryReader::pageClassifications(int $limit = 100, ?string $after = null, bool $includeRetired = false): array`.
- `GraphReader::findOutgoing(NodeReference $source, ?string $predicate = null, int $after = 0, int $limit = 50, bool $includeRetired = false, ?string $targetType = null): array`.
- `CursorAuthorityInventoryReader` remains a compatibility sub-interface of `AuthorityInventoryReader`; WPDB implements both inventory contracts and `GraphService` implements `GraphReader`.
- The audit constructor accepts the four read-only ports and `EntityProfileResolver`; no concrete `GraphService`, Proposal, Governance or writer symbol appears in the audit source.

- [ ] **Step 1: Write failing contract tests for the four read seams.**

  Add reflection/source assertions proving the audit constructor parameter types
  are the read ports and that audit/factory source contains none of
  `GraphService`, `Proposal`, `Governance`, `ControlledApply`, `GraphWriter`,
  `AuthorityWriter`, `KnowledgeWriter` or `CaptureWriter`.

- [ ] **Step 2: Run the focused tests and verify RED.**

  Run:

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ClockTypeClassificationAuditTest|ClockTypeAuditSurfaceContractTest'
  ```

  Expected: failure because the new interfaces and constructor wiring do not
  yet exist.

- [ ] **Step 3: Add the interfaces and compatibility implementations.**

  Keep the method contract read-only. Let `CursorAuthorityInventoryReader`
  extend `AuthorityInventoryReader`, add the classification page method to the
  WPDB repository using canonical UUID ordering, and implement `GraphReader` on
  `GraphService` without exposing writer methods through the audit type.

- [ ] **Step 4: Refactor audit pagination and family buckets.**

  Use source pages in the fixed order `model`, `variant`, `specimen`, `product`;
  audit each page before requesting the next cursor; report `batch_size`,
  `cursor`, `next_cursor`, `records_read`, `completed`, `surface_status` and
  reason. Paginate Classification targets through the target port and emit the
  exact buckets `CANONICAL_CLOCK_TYPE`, `LEGACY_CLOCK_TYPE`,
  `OTHER_CLASSIFICATION_FAMILY`, `FAMILY_MISSING`, `FAMILY_UNRESOLVED` and
  `INACTIVE`. Do not infer family from stable key.

- [ ] **Step 5: Make evidence resolution explicitly Claim → Evidence → Source.**

  Validate the Claim's canonical subject/type/scope and active state, then read
  Evidence by Claim ID and Source by Evidence source ID. Require active
  `supports` Evidence, active Source, matching revisions, acceptable provenance
  and supported status. Emit only UUID/revision/state/visibility summaries;
  never emit claim text, excerpts, raw metadata or private Source/Evidence
  payloads.

- [ ] **Step 6: Run the focused tests and verify GREEN.**

  Run the same focused command and expect all audit, adapter and surface tests
  to pass, including unavailable-vs-empty and bounded-resume cases.

- [ ] **Step 7: Commit only Task 1 files.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Contracts/Authority/AuthorityInventoryReader.php public/wp-content/plugins/nhk-core/src/Contracts/Authority/ClassificationTargetInventoryReader.php public/wp-content/plugins/nhk-core/src/Contracts/Graph/GraphReader.php public/wp-content/plugins/nhk-core/src/Contracts/Authority/CursorAuthorityInventoryReader.php public/wp-content/plugins/nhk-core/src/Infrastructure/Authority/WpdbAuthorityRepository.php public/wp-content/plugins/nhk-core/src/Application/Graph/GraphService.php public/wp-content/plugins/nhk-core/src/Application/Audit/ClockTypeClassificationAudit.php public/wp-content/plugins/nhk-core/src/Application/Audit/CanonicalKnowledgeEvidenceAuditReader.php public/wp-content/plugins/nhk-core/src/Infrastructure/Audit/WpdbClockTypeClassificationAuditFactory.php public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeClassificationAuditTest.php public/wp-content/plugins/nhk-core/tests/Unit/CanonicalKnowledgeEvidenceAuditReaderTest.php public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeAuditSurfaceContractTest.php
  git commit -m "refactor: isolate clock type audit read ports"
  ```

### Task 2: Make EntityProfileRegistry the central profile organization seam

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileDefinition.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileReadFoundation.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/FrontendSemanticBootstrap.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/BrandPublicDossierAcceptanceTest.php`

**Interfaces:**
- Serialized profile definitions expose `profile_key`, `matching_rule`,
  `capabilities`, `dossier_recipe`, `related_query_recipe`, `public_route_intent`,
  `archive_intent` and `presentation` while retaining the existing `key` field
  for compatibility.
- Resolver result remains `RESOLVED` only for Brand or exact
  `classification + family=clock_type`; legacy `clock-type` remains
  `COMPATIBILITY_READ`; other families remain unresolved for Clock-Type.

- [ ] **Step 1: Add failing registry assertions for the final serialized shape.**

  Assert both `brand` and `clock_type` definitions expose the required fields,
  that Clock Type has `entity_type=classification` and `family=clock_type`, and
  that `classified_as` and derived Brand↔Type are not capability shortcuts.

- [ ] **Step 2: Run the registry tests and verify RED.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter EntityProfileRegistryTest
  ```

- [ ] **Step 3: Implement explicit profile metadata and central resolution.**

  Add serialized `profile_key`, dossier/related recipes and the final admin
  badge labels. Keep family matching exact and keep all legacy fields/read
  aliases non-mutating. Route all profile-aware dossier metadata through the
  registry/resolver rather than entity-title or generic Classification checks.

- [ ] **Step 4: Run registry and existing dossier tests and verify GREEN.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'EntityProfileRegistryTest|BrandPublicDossierAcceptanceTest'
  ```

- [ ] **Step 5: Commit Task 2 files.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileDefinition.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileRegistry.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileResolver.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileReadFoundation.php public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/FrontendSemanticBootstrap.php public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php public/wp-content/plugins/nhk-core/tests/Unit/BrandPublicDossierAcceptanceTest.php
  git commit -m "refactor: centralize clock type entity profiles"
  ```

### Task 3: Complete root route ownership and collision read organization

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/RootRouteOwnershipRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/RootPublicEntityRouteResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/RootRouteCollisionPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WordPressRootRouteOwnershipReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/RootPublicEntityRouteResolverTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CanonicalPublicSlugPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/PublicRoutingIntegrationTest.php`

**Interfaces:**
- `RootRouteOwnershipRegistry::register(string $path, array $owner): void` and
  `inspect(string $path): array` are read/registration metadata only; no
  allocation or persistence is performed.
- `WordPressRootRouteOwnershipReader` composes the registry callback, native
  WordPress owners and reserved roots. Missing registry returns `UNAVAILABLE`
  and `ROOT_ROUTE_REGISTRY_UNAVAILABLE` through the collision policy.
- `WpdbPublicIdentityRepository::findCurrentByRootSlug()` reads any persisted
  root-scope Authority route and leaves route type to the persisted identity.

- [ ] **Step 1: Write failing collision and route-owner tests.**

  Cover entity-vs-entity, entity-vs-Page, entity-vs-Post, entity-vs-registered
  route, entity-vs-Video/reserved route, malformed slug, duplicate identity,
  root-scope Classification read-back, and missing registry typed gap.

- [ ] **Step 2: Run focused route tests and verify RED.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'RootPublicEntityRouteResolverTest|CanonicalPublicSlugPolicyTest'
  ```

- [ ] **Step 3: Implement the central read-side registry and persisted root lookup.**

  Register existing reserved/frontend roots as ownership metadata, inspect
  native Page/Post owners, and fail closed when a non-reserved root has no
  sanctioned registered owner. Resolve root identities by persisted owner UUID,
  route type and profile; never derive type from slug and never allocate a
  suffix.

- [ ] **Step 4: Keep existing route consumers compatible and verify no live mutation.**

  Wire only the read/collision owner into the existing route composition. Keep
  namespaced Classification routes, Video routes, WordPress editorial routes,
  sitemap and Public Identity writers unchanged. Add a source test that the
  route foundation contains no allocation/reprojection call.

- [ ] **Step 5: Run focused route tests and verify GREEN.**

  Run the focused unit command plus the route integration test when the guarded
  WordPress runtime is available; otherwise retain the typed integration
  infrastructure gap.

- [ ] **Step 6: Commit Task 3 files.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/RootRouteOwnershipRegistry.php public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/RootPublicEntityRouteResolver.php public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/RootRouteCollisionPolicy.php public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WordPressRootRouteOwnershipReader.php public/wp-content/plugins/nhk-core/src/Infrastructure/PublicIdentity/WpdbPublicIdentityRepository.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicEntityRoutes.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/RootPublicEntityRouteResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/CanonicalPublicSlugPolicyTest.php public/wp-content/plugins/nhk-core/tests/Integration/PublicRoutingIntegrationTest.php
  git commit -m "feat: organize root route ownership read side"
  ```

### Task 4: Add read-only Clock-Type admin projection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileAdminProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/AdminWorkbenchReadApi.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminStatusBadge.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileAdminProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AdminWorkbenchReadApiTest.php`

**Interfaces:**
- `EntityProfileAdminProjection::forEntity(AuthorityEntity $entity): array`
  returns a reader-safe card containing identity/profile metadata, aliases,
  description, owner-backed relation groups, readiness/SEO/public identity and
  diagnostics.
- Each section has one of `AVAILABLE_WITH_ITEMS`, `AVAILABLE_EMPTY`,
  `UNAVAILABLE_IMPLEMENTATION_GAP` or `BLOCKED`; no unavailable owner is
  converted to empty.
- Admin endpoint remains capability/nonce protected and has no writer
  dependency or persistence side effect.

- [ ] **Step 1: Write failing projection tests.**

  Assert the Clock-Type badge is `[LOẠI ĐỒNG HỒ]`, the card exposes
  `entity_type=classification`, `family=clock_type`, canonical UUID, stable key,
  revision/state, aliases/description and all required relation sections. Add
  tests for empty, unavailable and blocked section states and for a Case Form
  entity not receiving Clock-Type capabilities.

- [ ] **Step 2: Run the focused projection tests and verify RED.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'EntityProfileAdminProjectionTest|AdminWorkbenchReadApiTest'
  ```

- [ ] **Step 3: Implement the projection over existing read owners.**

  Use `EntityProfileRegistry`/`EntityProfileResolver` and the existing dossier
  packet. Filter only presentation-safe Authority fields, preserve owner
  availability states, and keep reverse Brand association as unavailable when
  its registered derived reader is absent.

- [ ] **Step 4: Wire the read endpoint/UI foundation.**

  Add an authenticated entity detail read route to the current Admin Workbench
  and a compact Clock-Type detail section to the existing Admin entity lookup.
  The UI must use the registry badge, not title matching, and must not offer a
  direct Authority/WordPress writer.

- [ ] **Step 5: Run focused tests and verify GREEN.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'EntityProfileAdminProjectionTest|AdminWorkbenchReadApiTest|AdminWorkspaceViewModelTest'
  ```

- [ ] **Step 6: Commit Task 4 files.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Entity/EntityProfileAdminProjection.php public/wp-content/plugins/nhk-core/src/Infrastructure/Http/AdminWorkbenchReadApi.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminStatusBadge.php public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileAdminProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/AdminWorkbenchReadApiTest.php
  git commit -m "feat: add clock type admin read projection"
  ```

### Task 5: Normalize individual Clock-Type creation lifecycle

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Authority/ClockTypeCreationLifecycle.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Authority/AuthorityIntentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedAuthorityPlanExecutor.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityIntentPlannerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeCreationLifecycleTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CoreCreationE2ETest.php`

**Interfaces:**
- `ClockTypeCreationLifecycle::plan(string $name, array $input = [], array $captureContext = []): array` performs canonical search/reuse first and returns a plan with required Clock-Type candidate fields.
- `ClockTypeCreationLifecycle::apply(array $plan, string $approvedFingerprint, array $approvedCandidateIds, ConversationalAuthorityPolicy $policy, string $actor = '0'): array` delegates all mutation to `GovernedAuthorityPlanExecutor`, then verifies canonical Classification read-back and duplicate absence.
- Candidate output includes `name`, `entity_type=classification`, `family=clock_type`, aliases, description, proposed stable key, provenance, ambiguities and blockers. Existing stable-key convention remains `nhk:classification:clock-type.*` without family inference.

- [ ] **Step 1: Write failing lifecycle tests.**

  Cover “Thêm loại Đồng hồ công cộng” reuse-first behavior, exact canonical
  reuse, legacy-family compatibility block, canonical plan candidate shape,
  composed facet separation (`Đồng hồ để bàn` plus `Pháp`), approval/eligibility/
  Controlled Apply delegation, canonical read-back, duplicate verification and
  idempotent replay. Assert no direct Authority writer call occurs from the
  lifecycle.

- [ ] **Step 2: Run lifecycle tests and verify RED.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ClockTypeCreationLifecycleTest|AuthorityIntentPlannerTest|CoreCreationE2ETest'
  ```

- [ ] **Step 3: Normalize planner requests and candidate fields.**

  Accept `tạo` and `thêm` Clock-Type language, generate canonical
  `family=clock_type`, preserve server-owned `nhk:classification:clock-type.*`
  stable-key proposal, and filter exact reuse through `EntityProfileResolver`.
  Legacy `clock-type` exact matches stay typed review/compatibility outcomes and
  are never rewritten or applied as canonical new data.

- [ ] **Step 4: Implement the lifecycle wrapper and canonical verification.**

  Plan through `AuthorityIntentPlanner`; apply through
  `GovernedAuthorityPlanExecutor`; read the resulting UUID from the controlled
  apply receipt; re-read Authority and profile; verify exact stable-key/name
  uniqueness and return typed blockers when read-back or duplicate verification
  is unavailable. Do not instantiate or call Authority/WordPress writers in the
  lifecycle.

- [ ] **Step 5: Enforce subtype hierarchy scope in regression coverage.**

  Add tests for active same-family Clock-Type `subtype_of`, cycles, inactive
  endpoints, cross-family endpoints and origin/case-form/material candidates.
  Reuse the existing Graph hierarchy policy; do not add a predicate.

- [ ] **Step 6: Run lifecycle and existing semantic regressions and verify GREEN.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ClockTypeCreationLifecycleTest|AuthorityIntentPlannerTest|CoreCreationE2ETest|ConversationalAuthorityGraphTest|ClockTypeMembershipPr5Test'
  ```

- [ ] **Step 7: Commit Task 5 files.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Authority/ClockTypeCreationLifecycle.php public/wp-content/plugins/nhk-core/src/Application/Authority/AuthorityIntentPlanner.php public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedAuthorityPlanExecutor.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php public/wp-content/plugins/nhk-core/tests/Unit/AuthorityIntentPlannerTest.php public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeCreationLifecycleTest.php public/wp-content/plugins/nhk-core/tests/Unit/CoreCreationE2ETest.php
  git commit -m "feat: govern individual clock type creation"
  ```

### Task 6: Golden regressions, documentation closure and final verification

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypePr1GoldenRegressionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeMembershipPr5Test.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ClockTypeShadowClassifierTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/RootPublicEntityRouteResolverTest.php`
- Modify: `docs/architecture/ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md`
- Modify: `docs/architecture/CLOCK_TYPE_PR1_GAP_REPORT.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Regenerate: `public/wp-content/plugins/nhk-core/resources/canonical-docs/manifest.json` and generated snapshot files

- [ ] **Step 1: Add/retain the ten golden regressions.**

  Assert Brand versus Clock Type, Clock Type versus Case Form, brandless
  Specimen, Brand + Type derived output, unchanged Video `about`, unchanged
  Media `depicts`, unchanged Knowledge subject/reachability, all root collision
  classes, pure audit dependencies, and search/reuse-first creation.

- [ ] **Step 2: Run the complete Unit and Contract suites.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Unit'
  vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Contract'
  ```

  Record counts, failures, warnings and environment-gated skips without turning
  infrastructure failure into a false pass.

- [ ] **Step 3: Run relevant Integration suites.**

  ```bash
  NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Integration' --filter 'PublicRoutingIntegrationTest|P4MigrationAndAuditIntegrationTest|P5CanonicalDomainIntegrationTest|GovernedSemanticIngestIntegrationTest'
  ```

  Use only exact `nhk_v3_test` for destructive integration setup. If the local
  WordPress/MySQL boundary is unavailable, report the exact typed blocker and
  do not touch `nhk_v3` or staging.

- [ ] **Step 4: Run lint and static safety checks.**

  ```bash
  composer lint
  git diff --check
  rg -n "sk-[A-Za-z0-9]|-----BEGIN (RSA|OPENSSH|PRIVATE) KEY-----|password|secret|token" public/wp-content/plugins/nhk-core docs/superpowers --glob '*.php' --glob '*.md'
  ```

  Review matches manually and do not commit credentials, dumps or private keys.

- [ ] **Step 5: Update closure documentation.**

  Add a dated checkpoint to `V3_EXECUTION_STATE.md` describing local code/test
  evidence, exact integration/runtime gaps, no live mutation and no PR7. Update
  the Clock-Type gap report/contract to mark organization status accurately,
  preserving dated historical evidence and compatibility-family wording.

- [ ] **Step 6: Regenerate and verify canonical documentation parity.**

  ```bash
  composer generate:mcp-docs
  php -r '$root=getcwd();$m=json_decode(file_get_contents($root."/public/wp-content/plugins/nhk-core/resources/canonical-docs/manifest.json"),true);$bad=[];foreach(($m["files"]??[]) as $f){$p=$root."/".$f["path"];if(!is_file($p)||hash_file("sha256",$p)!==$f["sha256"])$bad[]=$f["path"];}if($bad!==[]){fwrite(STDERR,"SNAPSHOT_PARITY=FAIL\\n".implode("\\n",$bad)."\\n");exit(1);}echo "SNAPSHOT_PARITY=PASS\\n";' 
  ```

- [ ] **Step 7: Perform fresh read-only runtime verification.**

  Run the sanctioned health/documentation/read-only audit surface only. Record
  `LIVE_AUDIT_SURFACE_NOT_EXPOSED` or `ORGANIZATION_BLOCKED` when the target
  cannot expose the surface. Do not use SQL, generic writers, Governance apply,
  Public Identity allocation or any semantic mutation to obtain evidence.

- [ ] **Step 8: Inspect final diff and commit only closure documentation/tests.**

  Verify `git status`, compare changed paths with this plan, confirm the prior
  dirty bridge is not accidentally staged, and create the final documentation/
  regression commit. Do not create a Clock Type, run PR7 or modify staging.

## Plan self-review

- Coverage: Tasks 1–6 map A–F of the approved spec and include every explicit
  non-goal and verification requirement.
- Placeholder scan: no `TBD`, `TODO`, “implement later”, vague error-handling
  instruction or “write tests for the above” step is present.
- Type consistency: all named interfaces and lifecycle signatures are defined
  before later tasks consume them; the existing compatibility interface remains
  an explicit bridge.
- Scope: all mutations are limited to code/docs/test commits; no live semantic
  mutation, data seed, family normalization, route allocation, backfill or PR7.
- Dirty-state safety: each commit stages explicit closure paths and leaves any
  unrelated pre-existing changes unstaged.
