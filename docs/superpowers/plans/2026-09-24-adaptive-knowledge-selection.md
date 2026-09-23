# Adaptive Knowledge Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace fixed Claim-count editorial selection with deterministic KnowledgeUnit deduplication, reader coverage and adaptive bounded selection shared by Article/Video/Image/Media.

**Architecture:** Keep canonical Claim/Evidence/Graph storage unchanged. Add transient semantic value objects and policies beside the current `Application/Semantic` classes; preserve selected Claim arrays for compatibility while attaching unit/coverage trace. Retrieval remains bounded and graph reachability remains discovery-only.

**Tech Stack:** PHP 8+, PHPUnit, existing NHK V3 semantic classes, no new runtime/network/LLM dependency.

**Spec:** `docs/superpowers/specs/2026-09-24-adaptive-knowledge-selection-design.md`

## Global Constraints

- No fixed final selection N; `selection_limit` may remain only as a retrieval/context safety ceiling.
- No graph dump, provenance padding, fabricated Knowledge, canonical schema migration or direct semantic mutation.
- Applicability, evidence, scope and public role precede reader selection; popularity is secondary tie-break only.
- Public prose and SEO consume final validated public material only.
- Usage writes occur only after accepted/governed publication boundary and are idempotent.
- Production code must contain no regression UUID, Capture ID, YouTube ID, Odo, exact provenance phrase or language-blacklist special case.

## Review Focus

- A provenance-only pool must retain trace but produce zero reader coverage and no public prose/SEO.
- Near-duplicate claims must collapse while preserving every support ID/revision.
- An inapplicable neighbor must never fill a gap; an applicable neighbor may be discovered only through bounded gap expansion.
- A rich pool must stop on coverage/marginal gain/context budget without O(N²) duplicate comparison or graph dumping.
- Sparse surfaces must report THIN/PARTIAL/SUFFICIENT honestly without making thin an automatic hard block.

### Task 1: Add transient KnowledgeUnit and coverage primitives

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeUnit.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/CoverageAspect.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeUnitBuilder.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialContextPack.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeUnitBuilderTest.php`

**Interfaces:**
- `KnowledgeUnitBuilder::build(array $candidates, array $primarySubject, string $topic, array $profile, array $inputContext = []): KnowledgeUnitBuildResult`
- `KnowledgeUnitBuildResult` exposes `units`, `grounding`, `excluded`, `diagnostics` as arrays.
- `KnowledgeUnit` exposes `toArray()` and representative fields through `claim()`; it carries `supporting_claims`, `evidence_refs`, `provenance_trace`, `coverage_aspects`, `semantic_fingerprint`, `reader_utility`, `publicly_composable`.

- [ ] **Step 1: Write failing tests** for 0 reader facts, one unit with two near-duplicate supporting Claims, 500 duplicates collapsing to one unit, and provenance units being retained only in grounding.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter KnowledgeUnitBuilderTest` and confirm failure because the primitives do not exist.
- [ ] **Step 3: Implement** deterministic Unicode/case/whitespace/punctuation normalization, subject/scope/facet role partitioning, bounded token containment/Jaccard near-duplicate grouping, first deterministic representative selection and complete support trace retention.
- [ ] **Step 4: Extend** `EditorialContextPack` with optional `knowledgeUnits`, `coverageAspects`, preserving constructor compatibility and `toArray()` output.
- [ ] **Step 5: Run** the focused test and existing `EditorialKnowledgeSelectorTest`.
- [ ] **Step 6: Commit** `feat: add transient knowledge units and coverage primitives`.

### Task 2: Replace fixed selector ranking with adaptive coverage selection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialCoveragePolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialSemanticRolePolicy.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialKnowledgeSelectorAdaptiveTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialKnowledgeSelectorTest.php`

**Interfaces:**
- `EditorialCoveragePolicy::for(string $profile, string $topic, array $inputContext): array` returns `aspect_target`, `token_budget`, `minimum_gain`, `max_expansion_rounds`.
- Selector diagnostics include `candidate_count`, `eligible_count`, `knowledge_unit_count`, `selected_unit_count`, `coverage_achieved`, `coverage_status`, `stop_reason`, `context_budget_used`, `marginal_gains`.

- [ ] **Step 1: Write failing tests** for sparse 0/1/3 useful Claims, 1000 bounded candidates, provenance domination, duplicate domination, sufficient-coverage early stop, low marginal gain stop, context budget stop, and shared Article/Video semantics with different budgets.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'EditorialKnowledgeSelector(Adaptive)?Test'` and confirm failures expose fixed-count behavior or missing diagnostics.
- [ ] **Step 3: Implement** role/applicability filtering before unit build; build units; derive generic aspects from existing facet/intent metadata and topic tokens; select greedily by uncovered aspect gain, evidence, utility, diversity and optional usage novelty; never allow grounding/control to cover aspects.
- [ ] **Step 4: Preserve** selected Claim compatibility by projecting each selected unit’s representative claim and attaching `knowledge_unit`; keep all support traces in the pack.
- [ ] **Step 5: Add** `THIN`, `PARTIAL`, `SUFFICIENT` diagnostics and exclusion reasons such as `DUPLICATE_KNOWLEDGE`, `PROVENANCE_ONLY`, `INAPPLICABLE`, `LOW_MARGINAL_INFORMATION_GAIN`, `CONTEXT_BUDGET_EXCEEDED`.
- [ ] **Step 6: Run** focused selector tests plus `TopicFulfillmentTest` and `EditorialCaptureSemanticCoreTest`.
- [ ] **Step 7: Commit** `feat: select knowledge by adaptive reader coverage`.

### Task 3: Add bounded gap-driven retrieval expansion diagnostics

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialClaimRetrievalExpansionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ClaimRetrievalScopeTest.php`

**Interfaces:**
- Retrieval result preserves `items`, `eligible_claims`, `status`, `blockers` and adds `retrieval_diagnostics`.
- Expansion diagnostics record `initial_candidates`, `rounds[]`, `reason`, `depth`, `budget`, `candidates_considered`, `units_formed`, `selected_units`, `stop_reason`.

- [ ] **Step 1: Write failing tests** for no expansion when coverage is sufficient, bounded expansion for an uncovered aspect, rejection of an inapplicable neighbor, and expansion budget exhaustion without graph dump.
- [ ] **Step 2: Run** the focused tests and confirm expected failures.
- [ ] **Step 3: Implement** direct canonical-subject retrieval first; expose registered bounded expansion callback only when selector reports uncovered gaps; cap expansion rounds/candidates as safety bounds; re-run applicability and preserve origin/path.
- [ ] **Step 4: Ensure** no recursive/unbounded traversal and no Claim is selected merely because reachable.
- [ ] **Step 5: Run** focused retrieval, `EditorialClaimRetrievalServiceTest`, and existing scope tests.
- [ ] **Step 6: Commit** `feat: expand retrieval only for bounded coverage gaps`.

### Task 4: Refactor journey, composer and SEO to consume final public units

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ReaderJourneyPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEditorialComposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticSeoPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialPlan.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ReaderJourneyCoverageTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SharedEditorialComposerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticSeoPlannerTest.php`

**Interfaces:**
- Journey diagnostics expose `coverage_before`, `coverage_after`, `uncovered_aspects`, `journey_depth`.
- Sections may contain `unit_id`, `aspect_keys`, multiple `claims`, and trace support; opening section remains compatibility behavior.

- [ ] **Step 1: Write failing tests** proving one section can contain several supporting Claims from one unit, sparse content creates no filler sections, grounding never reaches body, and SEO excludes grounding/non-public candidates.
- [ ] **Step 2: Run** focused journey/composer/SEO tests and confirm failure.
- [ ] **Step 3: Implement** unit/aspect grouping and surface-aware section depth; compose only representative public propositions/allowed observations while preserving claim trace and evidence/provenance metadata outside body.
- [ ] **Step 4: Make** SEO derive title/meta/structured visible claims from final validated plan/draft package rather than raw retrieval arrays; revalidate after repair.
- [ ] **Step 5: Run** existing `ReaderJourneyPlannerTest`, `SharedEditorialComposerTest`, `SemanticSeoPlannerTest`, `VideoEditorialAdapterTest` and Article adapter tests.
- [ ] **Step 6: Commit** `feat: compose journey and seo from public knowledge units`.

### Task 5: Audit/reuse usage memory and enforce quality diagnostics

**Files:**
- Create or modify only after repository audit: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialUsageMemory*.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialQualityGate.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialUsageMemoryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialQualityGateTest.php`

**Interfaces:**
- Read side returns deterministic contextual counts without changing canonical Claim fields.
- Write side, if an existing compatible store exists, accepts an idempotency key and publication state; retries return the same receipt and do not increment twice.

- [ ] **Step 1: Audit** existing dependency/usage/owner/telemetry repositories with `rg`; record whether a compatible store exists. If none exists, add a no-op read adapter and explicitly do not add migration/persistence.
- [ ] **Step 2: Write failing tests** for popular foundational fact retained when critical, fresh equivalent fact winning secondary tie-break, irrelevant never-used fact rejected, truth fields unchanged, retry idempotency and failed/unpublished usage not counted.
- [ ] **Step 3: Implement** secondary usage scoring only after primary applicability/coverage/evidence/utility; keep writes outside candidate retrieval and after governed final boundary.
- [ ] **Step 4: Add** quality findings for provenance domination, duplicate domination, insufficient reader coverage, inapplicable neighbor, discarded useful knowledge, public-role violation, SEO non-public knowledge, low marginal gain and context budget.
- [ ] **Step 5: Run** focused usage/quality tests plus all existing quality tests.
- [ ] **Step 6: Commit** `feat: add usage-aware diversity and knowledge quality diagnostics`.

### Task 6: Documentation, performance evidence and full verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md` only if the current contract has a suitable section
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AdaptiveKnowledgeSelectionPerformanceTest.php`
- Modify: relevant Contract tests only when an executable contract assertion changes

- [ ] **Step 1: Write failing performance/regression tests** for 1000 synthetic Claims and 500 duplicates, asserting bounded candidate/unit counts, deterministic output, context budget and no quadratic blow-up signal.
- [ ] **Step 2: Run** the performance test before implementation of any missing diagnostic and confirm the intended failure.
- [ ] **Step 3: Implement** bounded instrumentation and update architecture docs with retrieval-vs-selection, coverage-vs-count, reachability-vs-applicability, truth-vs-utility and usage law.
- [ ] **Step 4: Run** PHP lint, focused selector/coverage/unit/usage tests, existing Editorial Enrichment tests, Video tests, Article shared-core tests, Unit, Contract and Integration if available.
- [ ] **Step 5: Run** `git diff --check` and a production special-case scan for the named regression identifiers/phrases and secret review.
- [ ] **Step 6: Read** `docs/architecture/V3_EXECUTION_STATE.md` before checkpoint, update it with evidence and honest environment gates.
- [ ] **Step 7: Commit** `feat: complete adaptive knowledge selection boundary`.

## Final verification and delivery

Use `superpowers:verification-before-completion` before claiming readiness. Run the full project test command and inspect the final diff. Use `superpowers:finishing-a-development-branch` only after tests are green; keep the branch/commits as-is because the user requested no push/deploy and did not request merge.
