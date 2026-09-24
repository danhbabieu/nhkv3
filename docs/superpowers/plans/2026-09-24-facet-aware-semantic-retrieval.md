# Facet-Aware Semantic Retrieval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a shared transient semantic-needs and facet-aware retrieval path before the existing `KnowledgeUnit`/coverage/adaptive selector, without changing canonical ownership or persistence.

**Architecture:** Introduce small immutable/read-only input and need packets, a deterministic decomposer, and a multi-need extension of the existing `ClaimRetrievalEngine`/`EditorialClaimRetrievalService`. Relaxation and candidate allocation happen per need before merge; existing `KnowledgeUnitBuilder`, `EditorialKnowledgeSelector`, ReaderJourney, composer and SEO remain downstream consumers, with only the metadata they need to preserve treatment and coverage status.

**Tech Stack:** PHP 8.x, PHPUnit 11, PSR-4 application classes under `public/wp-content/plugins/nhk-core/src`, existing Authority/Graph/Knowledge registries, transient arrays/read models, no new dependency and no schema migration.

**Spec:** `docs/superpowers/specs/2026-09-24-facet-aware-semantic-retrieval-design.md`

## Global Constraints

- The Constitution is the only normative authority; no implementation may weaken a locked owner, identity, provenance, Governance or fail-closed invariant.
- `SemanticInputEnvelope` and `SemanticNeed` are transient application read/planning models and must not be persisted as canonical semantic truth.
- Canonical UUID/stable key and the existing Capture subject packet remain authoritative; decomposition cannot re-resolve or replace the subject from weaker hints.
- Graph reachability discovers candidates only; applicability, scope, evidence, provenance and relevance must still pass independently.
- Retrieval must give every meaningful need a bounded opportunity before global pruning; no global top-N starvation.
- Relaxation runs only for the uncovered/insufficient need and stops at a bounded safe tier/budget.
- Broader/background Knowledge may enrich context but must not silently become a narrower direct assertion.
- User/source/specimen/machine observations are not canonical Knowledge or Evidence automatically; candidate discovery remains planning-only and Governance remains the mutation owner.
- Article, Video, Media/Image and generic text share the semantic kernel but retain surface-specific owners and projections.
- No new entity type, endpoint, predicate, relation, canonical field, migration, staging mutation, production mutation, deployment or push is allowed in this plan.
- No LLM/network call may be added inside the retrieval or selection loop; diagnostics must be deterministic, bounded and secret-safe.

## Review Focus

- A dominant facet with 100 Claims must not starve three sparse facets; covered by Task 3's allocation test.
- An explicit canonical subject plus a conflicting lexical hint must keep the explicit subject; covered by Task 2's authority-preservation test.
- A specimen/media observation must remain scoped and non-canonical; covered by Task 2 and Task 6 observation tests.
- A broader parent Claim must retain relaxed/background treatment and never become an exact fact; covered by Task 4 and Task 5 composer/SEO tests.
- A sparse or unavailable pool must remain uncovered/diagnostic rather than being padded with provenance or fabricated facts; covered by Task 4 and final regression verification.

## File Map

Create the following focused application/test files:

- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticInputEnvelope.php` — immutable transient input packet and origin-preserving normalization.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeed.php` — immutable typed need packet with deterministic identity and policies.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedDecomposer.php` — generic decomposition over envelope/interpreter/registered facet primitives.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedRetrievalPolicy.php` — bounded allocation and relaxation tier values/policy, if the existing registry conventions do not provide an equivalent.
- `public/wp-content/plugins/nhk-core/tests/Unit/SemanticNeedDecompositionTest.php` — envelope/decomposition/authority/provenance behavior.
- `public/wp-content/plugins/nhk-core/tests/Unit/FacetAwareClaimRetrievalTest.php` — multi-need allocation, candidate trace, deduplication and bounded pool behavior.
- `public/wp-content/plugins/nhk-core/tests/Unit/SemanticRelaxationTest.php` — per-need progressive relaxation and specificity treatment.

Modify the existing shared seam and its tests:

- `public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php` — add the multi-need path while preserving legacy single-subject behavior.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php` — expose needs/envelope to the engine and normalize the richer read model.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeUnitBuilder.php` — map need/facet/tier treatment into existing transient units without changing canonical Claim identity.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php` — preserve exact/relaxed/contextual coverage semantics while retaining current adaptive selection policy.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php` — assemble the shared envelope and decomposition result at the existing boundary only.
- `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php` — map Article context to the shared envelope.
- `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php` — map Video source/observation context without changing Video identity.
- No new `Application/Media` adapter file is planned: the current Media path has no equivalent shared editorial adapter. Media/Image reuse is verified through the existing `SharedEnrichmentBoundary` `media` profile and Capture/Media observation inputs; a concrete adapter is out of scope unless a read-only call-site check during execution proves an existing adapter already owns that seam.
- `public/wp-content/plugins/nhk-core/tests/Unit/SharedEnrichmentBoundaryTest.php`, `ArticleEditorialAdapterTest.php`, `VideoEditorialAdapterTest.php` and relevant Media/shared tests — cross-surface reuse.
- `public/wp-content/plugins/nhk-core/tests/Unit/AdaptiveKnowledgeSelectionPerformanceTest.php` — rich/sparse 1000+ Claim benchmark assertions.
- `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md` — approved transient retrieval/coverage invariants.
- `docs/architecture/V3_EXECUTION_STATE.md` — checkpoint, verification counts and truthful integration status.

### Task 1: Add transient input and need packets

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticInputEnvelope.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeed.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedRetrievalPolicy.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticNeedDecompositionTest.php`

**Interfaces:**
- `SemanticInputEnvelope::fromArray(array $input): self` and `toArray(): array` preserve raw text/title, subject packet, observations, metadata, target surface and per-component origin.
- `SemanticNeed` exposes `needId(): string`, `canonicalSubject(): array`, `conceptKey(): string`, `facetKey(): string`, `scope(): string`, `intent(): string`, `origin(): string`, `confidence(): float`, `evidenceRequirement(): string`, `retrievalPolicy(): array`, `relaxationPolicy(): array` and `toArray(): array`.
- `SemanticNeed::fromArray(array $data): self` rejects an empty concept/facet pair, invalid confidence outside 0–1, missing subject identity and unknown unsafe policy values.
- `SemanticNeedRetrievalPolicy` returns bounded default allocation/tiers and does not create new registry vocabulary when a tier is not registered.

- [ ] **Step 1: Write the failing tests.** Assert round-trip origin preservation, deterministic `need_id`, canonical subject requirement, confidence bounds, and that specimen/machine origin remains in the packet.
- [ ] **Step 2: Run the focused test to verify RED.**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'SemanticNeedDecompositionTest' --no-progress`

  Expected: failure because the new classes and factory methods do not exist.
- [ ] **Step 3: Implement the minimal immutable packets.** Use readonly properties, normalized strings, bounded arrays and stable SHA-256 identity over canonical subject ID/revision/facet/concept/scope/origin. Do not inspect or special-case domain names.
- [ ] **Step 4: Run the focused test to verify GREEN.** Expect all new packet assertions to pass with no production writes.
- [ ] **Step 5: Run lint and commit.**

  Run: `php -l public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticInputEnvelope.php && php -l public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeed.php && php -l public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedRetrievalPolicy.php && git diff --check`

  Commit: `git add public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticInputEnvelope.php public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeed.php public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedRetrievalPolicy.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticNeedDecompositionTest.php && git commit -m "feat: add transient semantic input and need packets"`

### Task 2: Implement generic semantic decomposition

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedDecomposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/TextInputInterpreter.php` only where a generic structured result is required; preserve its existing non-semantic instruction separation.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticNeedDecompositionTest.php`

**Interfaces:**
- `SemanticNeedDecomposer::__construct(TextInputInterpreter $interpreter, ?object $facetVocabulary = null)` accepts the existing interpreter and a registered vocabulary/read port.
- `decompose(SemanticInputEnvelope $envelope): SemanticNeedDecompositionResult` returns `needs`, `unresolved`, `diagnostics` and the normalized interpretation; it never writes or calls a canonical writer.
- `SemanticNeedDecompositionResult::toArray(): array` serializes only bounded trace fields and `SemanticNeed::toArray()` packets.

- [ ] **Step 1: Add failing tests** for five independent structured concepts, equivalent wording deduplication, authoritative-subject preservation against a conflicting hint, specimen observation scope, and unknown lexical/facet input remaining unresolved rather than becoming semantic truth.
- [ ] **Step 2: Run the decomposition test and verify the expected RED** on the missing decomposer/result.
- [ ] **Step 3: Implement decomposition.** Convert interpreter candidates, structured observations and explicit metadata into facet/concept candidates through registered primitives; normalize/deduplicate by subject/facet/concept/scope/origin; keep unresolved diagnostics. Treat dictionary hits as lexical support only. Never hardcode Odo, clock, YouTube, regression phrases or Vietnamese semantic blacklists.
- [ ] **Step 4: Run the focused decomposition tests and verify GREEN.** Confirm the explicit subject remains byte-for-byte authoritative in every emitted need.
- [ ] **Step 5: Commit the isolated decomposition slice.**

  Run: `git diff --check && git add public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedDecomposer.php public/wp-content/plugins/nhk-core/src/Application/Semantic/TextInputInterpreter.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticNeedDecompositionTest.php && git commit -m "feat: decompose input into semantic needs"`

### Task 3: Add facet-aware multi-need retrieval and starvation prevention

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FacetAwareClaimRetrievalTest.php`
- Regression tests: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialClaimRetrievalServiceTest.php`, `ClaimRetrievalScopeTest.php`, `EditorialClaimRetrievalExpansionTest.php`

**Interfaces:**
- Preserve `ClaimRetrievalEngine::retrieve(array $context): array` for existing callers.
- Add `ClaimRetrievalEngine::retrieveForNeeds(array $context, array $needs): array` where each item is a `SemanticNeed` or its validated array representation.
- Add `EditorialClaimRetrievalService::retrieveForNeeds(SemanticInputEnvelope $envelope, array $needs, array $profile = []): array`.
- The returned read model has `items`, `eligible_claims`, `selected_claims`, `need_diagnostics`, `blockers` and `retrieval_diagnostics`; each item has `need_ids`, `need_id`, original/target subject, facet, concept, scope, applicability, relation path, claim ID/revision, provenance/evidence and `retrieval_tier`.

- [ ] **Step 1: Write failing tests** for facet A=100 versus B/C/D=1 opportunity allocation, hard candidate budget, multi-need support without duplicate canonical identity, preserved revision/provenance/path, and compatibility of the legacy single-subject method.
- [ ] **Step 2: Run focused tests and verify RED.**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'FacetAwareClaimRetrievalTest|EditorialClaimRetrievalServiceTest|ClaimRetrievalScopeTest|EditorialClaimRetrievalExpansionTest' --no-progress`

  Expected: new facet assertions fail while existing legacy tests identify any accidental contract break.
- [ ] **Step 3: Implement per-need bounded allocation.** Invoke the existing subject/neighborhood read ports with a need context when supported; retain the old callback shape through a compatibility path. Score/filter each row against the active need, attach trace metadata, allocate at least one opportunity to every valid meaningful need, then merge by `claim_id:claim_revision` while unioning `need_ids`.
- [ ] **Step 4: Apply the final result limit after mandatory per-need representation.** Sort only with deterministic score/order/ID tie-breakers and record starvation prevention diagnostics; never dump the Graph or use unbounded nested scans.
- [ ] **Step 5: Run focused tests and verify GREEN.** Existing retrieval scope/expansion behavior must remain green, including rejection of reachable but inapplicable claims.
- [ ] **Step 6: Commit retrieval.**

  Run: `git diff --check && git add public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php public/wp-content/plugins/nhk-core/tests/Unit/FacetAwareClaimRetrievalTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialClaimRetrievalServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/ClaimRetrievalScopeTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialClaimRetrievalExpansionTest.php && git commit -m "feat: retrieve claims by semantic need and facet"`

### Task 4: Add per-need progressive relaxation and specificity treatment

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedRetrievalPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticRelaxationTest.php`

**Interfaces:**
- `ClaimRetrievalEngine` calls an optional expansion port with `(array $subject, SemanticNeed $need, string $tier, array $budget): array` and records no expansion when the port is absent or unsafe.
- Each candidate carries `retrieval_tier`, `semantic_distance`, `editorial_treatment`, `coverage_kind` and `relaxation_reason`.
- `SemanticNeedRetrievalPolicy::tiersFor(SemanticNeed $need): array` returns only registered/safe tiers in deterministic order.

- [ ] **Step 1: Write failing tests** for exact coverage stopping without relaxation, relaxation of only one uncovered need, applicable parent/family enrichment, rejection of an inapplicable reachable neighbor, background context not satisfying exact coverage, and no-candidate uncovered diagnostics.
- [ ] **Step 2: Run `SemanticRelaxationTest` and verify RED** with the expected missing tier/treatment fields.
- [ ] **Step 3: Implement the bounded tier loop.** Evaluate exact candidates; only if the specific need remains uncovered call the next safe tier. Stop on sufficient coverage, no gain, exhausted budget or unsafe relation. Preserve original subject/scope and never rewrite broader Claim text as a narrower assertion.
- [ ] **Step 4: Run the relaxation tests and verify GREEN.** Confirm no global expansion occurs for a need already satisfied.
- [ ] **Step 5: Run lint/diff checks and commit.**

  Run: `php -l public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php && php -l public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedRetrievalPolicy.php && git diff --check`

  Commit: `git add public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedRetrievalPolicy.php public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticRelaxationTest.php && git commit -m "feat: relax uncovered semantic needs safely"`

### Task 5: Preserve treatment through KnowledgeUnit and adaptive coverage

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeUnitBuilder.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialContextPack.php` only if the existing transient pack needs a typed coverage summary.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeUnitBuilderTest.php`, `EditorialKnowledgeSelectorAdaptiveTest.php`, `AdaptiveKnowledgeQualityDiagnosticsTest.php`, `ReaderJourneyCoverageTest.php`, plus new specificity assertions in `SemanticRelaxationTest.php`.

**Interfaces:**
- `KnowledgeUnitBuilder` retains all canonical Claim IDs/revisions/supporting claims and adds need/facet/tier/treatment to transient unit trace.
- `EditorialKnowledgeSelector` consumes the existing retrieval shape and reports exact/relaxed/contextual/uncovered coverage without changing `EditorialUsageMemory` semantics.
- `EditorialContextPack::toArray()` exposes bounded `semantic_needs`, `coverage_kinds` or equivalent diagnostics only when present; no canonical persistence.

- [ ] **Step 1: Write failing tests** proving exact and relaxed coverage are distinct, background context does not count as exact, broader candidates remain non-direct, duplicate Claims still collapse into one `KnowledgeUnit`, and existing THIN/PARTIAL/SUFFICIENT behavior remains deterministic.
- [ ] **Step 2: Run the selector/unit tests and verify RED** on new coverage/treatment assertions.
- [ ] **Step 3: Implement the smallest mapping change.** Derive coverage aspects from `need_id`/facet where available, retain existing fallback for legacy candidates, and set public composition only for candidates already eligible and authorized by treatment.
- [ ] **Step 4: Run focused selector/journey/composer/SEO tests and verify GREEN.** Do not alter the adaptive ranking algorithm except to prevent relaxed/background material from masquerading as exact.
- [ ] **Step 5: Commit the downstream integration.**

  Run: `git diff --check && git add public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeUnitBuilder.php public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialContextPack.php public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeUnitBuilderTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialKnowledgeSelectorAdaptiveTest.php public/wp-content/plugins/nhk-core/tests/Unit/AdaptiveKnowledgeQualityDiagnosticsTest.php public/wp-content/plugins/nhk-core/tests/Unit/ReaderJourneyCoverageTest.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticRelaxationTest.php && git commit -m "feat: preserve semantic treatment through adaptive coverage"`

### Task 6: Wire the shared boundary and surface adapters

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- No new Media/Image production adapter file; use the existing `SharedEnrichmentBoundary` `media` profile and current Capture/Media observation inputs.
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` only to inject one shared decomposer/read-only dependency where construction currently occurs.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SharedEnrichmentBoundaryTest.php`, `ArticleEditorialAdapterTest.php`, `VideoEditorialAdapterTest.php`, relevant Media tests and a new generic input adapter test if no existing generic seam covers it.

**Interfaces:**
- `SharedEnrichmentBoundary::enrich(array $request): array` accepts the existing request plus optional envelope/decomposition context and returns `content.semantic_needs`, richer retrieval diagnostics and the existing `pack` shape.
- Article/Video/Media adapters remain owner-specific `prepare(array $context): array` methods and only map input; they do not construct competing retrieval or writer services.

- [ ] **Step 1: Write failing cross-surface tests** showing the same semantic input pattern creates shared needs/retrieval trace for Article, Video, Media/Image and generic text while profiles, public topics, source identity and owner boundaries remain distinct.
- [ ] **Step 2: Run the focused adapter matrix and verify RED** on absent semantic-needs output.
- [ ] **Step 3: Wire envelope → decomposer → `retrieveForNeeds` at `SharedEnrichmentBoundary`.** Keep prepared Capture context binding and the existing Knowledge/relations branches unchanged. Preserve the Video public-topic separation and Media fast-path semantics.
- [ ] **Step 4: Run focused Article/Video/Media/shared suites and verify GREEN.** Assert observations remain planning data and generated prose remains rejected from Knowledge enrichment.
- [ ] **Step 5: Run PHP lint on all changed adapter/plugin files and commit.**

  Run: `find public/wp-content/plugins/nhk-core/src/Application/Semantic public/wp-content/plugins/nhk-core/src/Application/Article public/wp-content/plugins/nhk-core/src/Application/Video public/wp-content/plugins/nhk-core/src/Application/Media -name '*.php' -print0 | xargs -0 -n1 php -l && git diff --check`

  Commit: `git add public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/SharedEnrichmentBoundaryTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleEditorialAdapterTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialAdapterTest.php && git commit -m "feat: wire shared semantic needs across surfaces"`

### Task 7: Add performance/observability coverage and production special-case scan

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/AdaptiveKnowledgeSelectionPerformanceTest.php`
- Create or modify: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticRetrievalDiagnosticsTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/*` only for bounded diagnostic fields proven by tests.

**Interfaces:**
- Retrieval diagnostics expose stable counts for need count, initial candidates, per-need candidates, allocation, expansion rounds, KnowledgeUnit count, selected count, exact/relaxed/contextual coverage and stop reasons.
- Diagnostics omit raw payloads, credentials, private source/evidence bodies and unbounded arrays.

- [ ] **Step 1: Write failing benchmark/diagnostic tests** for 1000+ Claims, 500 semantic duplicates, dominant/sparse facets, deterministic repeated runs and secret-safe trace output.
- [ ] **Step 2: Run the performance/diagnostic tests and verify RED** on missing per-need metrics or bounds.
- [ ] **Step 3: Implement bounded diagnostics and benchmark fixtures.** Keep complexity bounded by the configured candidate/need/expansion budgets; do not add network/LLM calls.
- [ ] **Step 4: Run the benchmark twice and verify identical output ordering/diagnostics.** Record execution characteristics without asserting machine-dependent wall-clock thresholds.
- [ ] **Step 5: Run the required production special-case scan.**

  Run: `rg -n -i "odo|36/8|36/10|youtube|capture.?uuid|song tien|xoay|may son|mat tron|mặt tròn|regression" public/wp-content/plugins/nhk-core/src/Application/Semantic public/wp-content/plugins/nhk-core/src/Domain/Knowledge public/wp-content/plugins/nhk-core/src/Infrastructure/Knowledge --glob '*.php'`

  Expected: no new decomposition/retrieval special case; existing domain validators, migration canaries and provider-specific adapters are reviewed and documented as out of scope rather than copied into the shared kernel.
- [ ] **Step 6: Commit performance and observability tests.**

  Run: `git diff --check && git add public/wp-content/plugins/nhk-core/tests/Unit/AdaptiveKnowledgeSelectionPerformanceTest.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticRetrievalDiagnosticsTest.php public/wp-content/plugins/nhk-core/src/Application/Semantic && git commit -m "test: verify bounded semantic retrieval diagnostics"`

### Task 8: Update contracts/state and run complete verification

**Files:**
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- No schema/migration files.

- [ ] **Step 1: Add the approved invariant block** to the Knowledge contract, explicitly marking the new read/planning path transient and subordinate to the Constitution.
- [ ] **Step 2: Run the full focused semantic/adapters matrix.**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'SemanticNeed|FacetAware|SemanticRelaxation|SharedEnrichmentBoundaryTest|ArticleEditorialAdapterTest|VideoEditorialAdapterTest|Media|KnowledgeUnit|EditorialKnowledgeSelector|ReaderJourney|SharedEditorialComposer|SemanticSeo|EditorialClaimRetrieval|AdaptiveKnowledge' --no-progress`

  Expected: all affected tests pass, with only already-documented warnings/deprecations.
- [ ] **Step 3: Run the complete Unit and Contract suites.**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --no-progress` and `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Contract' --no-progress`.

  Expected: pass or record exact unrelated baseline failures; do not hide or downgrade any failure.
- [ ] **Step 4: Run guarded Integration.**

  Run: `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Integration' --no-progress`.

  Expected: if the exact environment is absent, report the documented environment-gated failures; never claim Integration PASS.
- [ ] **Step 5: Run lint, diff and secret review.**

  Run: `composer lint`, `git diff --check`, and `rg -n -i "api[_-]?key|secret|password|private[_-]?key|token" public/wp-content/plugins/nhk-core/src/Application/Semantic public/wp-content/plugins/nhk-core/tests/Unit docs/superpowers --glob '*.php' --glob '*.md'`.

  Expected: changed PHP files lint clean, diff clean and no new credentials/secrets.
- [ ] **Step 6: Update `V3_EXECUTION_STATE.md` with exact verification counts**, changed boundary, migration status (`none`), special-case scan result, Integration status and no-mutation statement.
- [ ] **Step 7: Commit documentation and final checkpoint.**

  Run: `git add docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md docs/architecture/V3_EXECUTION_STATE.md && git commit -m "docs: record facet-aware semantic retrieval checkpoint"`

## Final handoff

Before claiming completion, run `git status --short --branch`, inspect every
commit and verify that no migration, database mutation, deployment, push or
special-case production logic was introduced. The completion status is
`LOCAL_UNIVERSAL_SEMANTIC_ENRICHMENT_READY_FOR_USER_DEPLOY` only if all required
local executable gates pass and Integration is not environment-gated. Otherwise
report the exact truthful state, including any baseline failure or missing
WordPress test environment.
