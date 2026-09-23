# Editorial Enrichment and Semantic SEO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make shared Article/Video editorial enrichment distinguish reader-useful Knowledge from grounding/provenance, preserve selected Knowledge in Video packages, and regenerate SEO from the final repaired reader-facing package.

**Architecture:** Add a transient deterministic semantic-role policy and explicit applicability/public-composability metadata to the existing editorial read models. Refactor selection, journey, composition, owner mapping and SEO around those read models without changing canonical Knowledge, Graph, Governance or persistence schemas.

**Tech Stack:** PHP 8+, existing NHK Core application services, PHPUnit, Composer autoload, WordPress plugin runtime.

**Spec:** `docs/superpowers/specs/2026-09-24-editorial-enrichment-semantic-seo-design.md`

## Global Constraints

- No canonical Knowledge schema or database migration.
- No new persistent semantic role, Claim type or Graph predicate.
- No frontend projection work.
- No LLM, network classifier or new external dependency.
- No change to Governance, Capture identity, idempotency or canonical ownership.
- No Video/Odo/YouTube-specific behavior.
- No staging, production or database mutation.
- Graph discovery never authorizes truth or public prose.
- Generated prose never becomes Knowledge or Evidence.
- Deliver one focused local commit; do not push or deploy.

## Review Focus

- A direct supported provenance Claim must remain grounding/provenance and lose CORE to a useful domain Claim; test in `EditorialKnowledgeSelectorTest`.
- A valid neighboring Claim must not broaden specimen/variant scope through reachability; test in `EditorialClaimRetrievalServiceTest` or a focused selector fixture.
- Video initial and resume paths must serialize the same reader-safe Claims; test in `VideoEditorialEnrichmentTest` and `VideoSemanticCoreTest`.
- SEO must be regenerated after repair rather than retaining the pre-repair semantic description; test in `SemanticSeoPlannerTest` and `VideoEditorialAdapter` coverage.
- Non-English provenance wording must remain non-public without phrase blacklists; test with synthetic wording in shared composer/quality tests.

## File Map

- Create `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialSemanticRolePolicy.php` for deterministic role, applicability and public-composability decisions.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php` to classify before ranking and emit explainable role/state metadata.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialContextPack.php` to expose explicit transient buckets while retaining the selected-claim compatibility view.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/ReaderJourneyPlanner.php` and `SharedEditorialComposer.php` to consume only reader-facing material for prose sections.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticSeoPlanner.php` to derive metadata only from public reader-facing material.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialQualityGate.php` to diagnose semantic thinness and discarded usable knowledge.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`, `VideoIntakeService.php` and `VideoEditorialResumePlanner.php` to share safe package mapping and post-repair SEO regeneration.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php` only where needed to consume the shared pack contract; no Article-only role logic.
- Extend focused tests: `EditorialKnowledgeSelectorTest.php`, `SharedEditorialComposerTest.php`, `ReaderJourneyPlannerTest.php`, `SemanticSeoPlannerTest.php`, `EditorialQualityGateTest.php`, `VideoEditorialEnrichmentTest.php`, `VideoSemanticCoreTest.php`, `ArticleEditorialAdapterTest.php`.
- Update `docs/architecture/V3_EXECUTION_STATE.md` only after verification with a local no-server checkpoint.

### Task 1: Add deterministic transient role and applicability policy

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialSemanticRolePolicy.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialSemanticRolePolicyTest.php`

**Interfaces:**
- Consumes: one retrieval candidate array, resolved primary subject array, and editorial profile/topic context.
- Produces: a candidate decision array containing `semantic_role`, `applicability`, `state`, `publicly_composable`, `editorial_utility`, and `reason`, while preserving the original candidate fields.

- [ ] **Step 1: Write failing role-policy tests**

Add tests for a direct supported provenance Claim, a direct useful domain Claim, a specimen-only Claim on a Variant subject, a valid direct technical Claim, a registered neighbor with incompatible scope, and a non-English provenance-style Claim. Assert role/state decisions, not exact wording.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialSemanticRolePolicyTest.php
```

Expected: failure because the policy class/API does not exist.

- [ ] **Step 3: Implement the minimal policy**

Define internal constants for the six transient roles and five states: `DISCOVERED`, `ELIGIBLE`, `APPLICABLE`, `SELECTED` and `PUBLICLY_COMPOSABLE`. Use candidate metadata (`scope`, `subject_id`, `subject_type`, `original_subject`, `relation_path`, `evidence`, `provenance`, Claim type and applicability warnings) to decide applicability and public composability. Never inspect a fixed phrase, language, UUID or product name.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run the same PHPUnit command. Expected: all role-policy tests pass.

### Task 2: Refactor selector and context pack

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialContextPack.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialKnowledgeSelectorTest.php`

**Interfaces:**
- Consumes: retrieval packet and `EditorialSemanticRolePolicy` decisions.
- Produces: `EditorialContextPack` with `grounding`, `readerFacts`, `supportingContext`, `specimenContext`, `controlProvenance`, plus compatibility `selectedClaims` containing only publicly composable selected Claims.

- [ ] **Step 1: Add failing selector/context tests**

Cover: provenance cannot become CORE; useful direct Claim becomes CORE; supporting Claim becomes context; control material is excluded from public selection; selected metadata includes role/state/applicability/utility/reason; all buckets preserve Claim IDs, revisions, paths, evidence and provenance.

- [ ] **Step 2: Run the tests and verify the expected RED failures**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialKnowledgeSelectorTest.php
```

Expected: current selector assigns the first eligible candidate to CORE and the context pack lacks the role buckets.

- [ ] **Step 3: Implement classification-before-ranking**

Inject or construct the role policy, classify all candidates, rank only applicable candidates, and ensure role/public-composability constraints are applied before CORE selection. Keep excluded candidates and diagnostic reasons. Compute editorial utility separately from evidence confidence and do not let direct/hop/evidence bonuses override a non-public role.

- [ ] **Step 4: Add explicit pack buckets without breaking callers**

Extend the value object with explicit arrays and retain a compatibility `selectedClaims` projection for existing consumers. Make the compatibility projection contain only selected publicly composable Claims.

- [ ] **Step 5: Run selector and existing semantic tests**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialKnowledgeSelectorTest.php public/wp-content/plugins/nhk-core/tests/Unit/ClaimRetrievalScopeTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialClaimRetrievalServiceTest.php
```

Expected: focused selector, scope and retrieval tests pass.

### Task 3: Make journey and composer public-role aware

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ReaderJourneyPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEditorialComposer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SharedEditorialComposerTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/ReaderJourneyPlannerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialEnrichmentTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleEditorialAdapterTest.php`

**Interfaces:**
- Consumes: role-aware `EditorialContextPack` and the existing `EditorialPlan`/`EditorialDraft` value objects.
- Produces: journey sections and prose only from `PUBLICLY_COMPOSABLE` reader facts/supporting context, with traceability retained in `claimTrace`.

- [ ] **Step 1: Add failing composer/journey tests**

Use synthetic English and non-English provenance Claims plus useful domain Claims. Assert provenance is absent from sections/body/summary, useful Claims are present, and a sparse pack produces bounded framing without fabricated facts.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SharedEditorialComposerTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialEnrichmentTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleEditorialAdapterTest.php
```

Expected: current composer realizes any selected Claim text and current journey creates one section per selected Claim.

- [ ] **Step 3: Refactor journey coverage**

Build the opening plus CORE reader fact section, then group supporting/specimen context into bounded sections. Omit grounding/provenance/control sections and expose a sparse/gap diagnostic when no public reader material exists.

- [ ] **Step 4: Restrict composer realization**

Guard each realizable Claim by `publicly_composable === true` and its public role. Keep Claim ID/revision/section trace for every realized Claim. Use internal context only for validation and scope, never as a paragraph source.

- [ ] **Step 5: Run the focused tests and existing shared pipeline tests**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SharedEditorialComposerTest.php public/wp-content/plugins/nhk-core/tests/Unit/ReaderJourneyPlannerTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialEnrichmentTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleEditorialAdapterTest.php
```

Expected: all shared composition and journey tests pass.

### Task 4: Preserve reader-safe Knowledge in Video initial and resume packages

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoIntakeService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialResumePlanner.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialEnrichmentTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php`

**Interfaces:**
- Consumes: shared pack buckets and `draft->claimTrace`.
- Produces: identical initial/resume `editorial.facts`, `editorial.related_knowledge`, dependency trace and reader-safe enrichment metadata without provenance/control leakage.

- [ ] **Step 1: Add failing package-mapping tests**

Assert that a shared pack with one reader fact, one supporting Claim and one provenance Claim produces reader-safe `facts`/`related_knowledge`, preserves Claim IDs/revisions, excludes provenance/control, and produces the same mapping for initial and resume paths.

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialEnrichmentTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php
```

Expected: current assertions observe empty `facts` and `related_knowledge` on the shared path.

- [ ] **Step 3: Implement one shared reader-safe mapping helper**

Map only public reader roles, deduplicate by Claim ID/revision, retain provenance/evidence in trace/dependency fields, and use the same helper from intake and resume. Do not map raw retrieval candidates or generate new Knowledge.

- [ ] **Step 4: Run the focused Video suite**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'VideoEditorialEnrichmentTest|VideoSemanticCoreTest|VideoEditorialAdapterTest|VideoEditorialResumePlannerTest'
```

Expected: initial/resume mapping tests and existing Video tests pass.

### Task 5: Make SEO and repair lifecycle consume final reader-facing content

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticSeoPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialQualityGate.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticSeoPlannerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialQualityGateTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialDecisionPipelineTest.php`

**Interfaces:**
- Consumes: final role-filtered pack, final draft and final SEO plan context.
- Produces: SEO title/meta/OG/structured descriptions derived from public reader material and quality diagnostics for semantic thinness/discarded knowledge.

- [ ] **Step 1: Add failing SEO/quality tests**

Cover provenance exclusion from meta/OG/VideoObject description, semantic thinness when usable Knowledge is discarded, and repair that changes body then regenerates meta from the repaired body rather than retaining the pre-repair description.

- [ ] **Step 2: Run tests and verify RED**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/SemanticSeoPlannerTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialQualityGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialDecisionPipelineTest.php
```

Expected: current SEO can select the first eligible Claim and the adapter reconstructs the old SEO plan after repair.

- [ ] **Step 3: Implement public-role SEO projection**

Build topic cluster/meta support only from reader-safe selected material and final draft content. Preserve trace IDs but never serialize grounding/provenance/control text into public descriptions.

- [ ] **Step 4: Regenerate SEO after bounded repair**

Change the repair callback contract so a changed editorial package is recomposed/replanned through `SemanticSeoPlanner` with the repaired draft and role-filtered pack before quality re-evaluation. Keep the bounded repair limit and do not mutate canonical Knowledge.

- [ ] **Step 5: Add semantic quality findings**

Emit diagnostics for provenance-dominated output, usable knowledge discarded at owner mapping, identity/source-title repetition and low reader value despite high evidence. Keep internal-language findings as hard blocks and preserve repairable-vs-blocking severity.

- [ ] **Step 6: Run focused SEO/quality/Video tests**

Run the Task 5 command again and require all tests to pass.

### Task 6: Shared Article/Video regression matrix and documentation checkpoint

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleEditorialAdapterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialEnrichmentTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialKnowledgeSelectorTest.php`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] **Step 1: Add shared semantic parity tests**

Run the same synthetic candidate set through Article and Video adapters. Assert identical role, applicability and public-composability decisions while allowing different profile prose/schema behavior. Assert generated prose is not fed into Knowledge enrichment.

- [ ] **Step 2: Run the full focused matrix**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'EditorialKnowledgeSelectorTest|EditorialSemanticRolePolicyTest|SharedEditorialComposerTest|SemanticSeoPlannerTest|EditorialQualityGateTest|ArticleEditorialAdapterTest|VideoEditorialAdapterTest|VideoEditorialEnrichmentTest|VideoEditorialResumePlannerTest|VideoSemanticCoreTest|VideoEditorialDecisionPipelineTest'
```

- [ ] **Step 3: Run Unit, Contract and guarded Integration suites**

Run:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Unit'
vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Contract'
NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite 'NHK Integration'
```

Expected: Unit and Contract pass; Integration either passes against the exact guarded `nhk_v3_test` environment or remains explicitly environment-gated without mutating `nhk_v3`.

- [ ] **Step 4: Run lint, diff and special-case scans**

Run:

```bash
composer lint
git diff --check
git diff -- ':!docs/superpowers/plans/**' | rg -n '852da54d|01a0cf34|PTdpHPmAgqU|Odo 36/8|The source identifies|blacklist' || true
```

The scan must produce no production-code matches for Video 33 identifiers, exact provenance phrases or phrase-blacklist logic.

- [ ] **Step 5: Record the checkpoint**

Append a concise local checkpoint to `docs/architecture/V3_EXECUTION_STATE.md` recording changed boundaries, test counts, any environment-gated suites, `NO_SERVER_ACTION`, and confirmation that canonical Knowledge was not mutated.

- [ ] **Step 6: Create the single focused commit**

Review `git status`, stage only the implementation, tests, spec/plan and required execution-state checkpoint, then create one local commit:

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Semantic public/wp-content/plugins/nhk-core/src/Application/Video public/wp-content/plugins/nhk-core/src/Application/Article public/wp-content/plugins/nhk-core/tests/Unit docs/superpowers/specs/2026-09-24-editorial-enrichment-semantic-seo-design.md docs/superpowers/plans/2026-09-24-editorial-enrichment-semantic-seo.md docs/architecture/V3_EXECUTION_STATE.md
git commit -m "fix: preserve useful knowledge in editorial enrichment"
```

Do not push, deploy, mutate staging or mutate production.

## Final verification contract

Before claiming `LOCAL_EDITORIAL_ENRICHMENT_FIX_READY`, verify:

- provenance/grounding cannot become CORE or public prose;
- applicable reader Knowledge survives Article/Video selection and Video package mapping;
- initial and resume Video paths converge;
- SEO comes from the final repaired reader-facing package;
- quality rejects semantic thinness caused by discarded usable Knowledge;
- Article and Video share the same semantic role decisions;
- no canonical Knowledge, Source, Evidence or Graph record was mutated;
- focused, Unit, Contract, lint and diff checks have recorded outcomes;
- no special-case strings entered production code.
