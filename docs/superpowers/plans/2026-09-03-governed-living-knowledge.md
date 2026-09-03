# Governed Living Knowledge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete and verify the governed Living Knowledge E2E slice from candidate creation through canonical repository read-back, audit and idempotent replay, while preserving the existing read-only projection boundaries.

**Architecture:** Reuse the existing candidate, proposal factory, Governance lifecycle, ControlledApplyService, AuthorityProposalExecutor and KnowledgeService seams. Add only the missing approval binding enforcement and a focused in-memory acceptance harness; no new semantic operation, owner, predicate, adapter or direct repository orchestration is introduced.

**Tech Stack:** PHP 8+, WordPress plugin, PHPUnit 11, existing PSR-4 runtime, existing WPDB repositories and migration framework.

**Spec:** `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md` and `docs/seo/LIVING_KNOWLEDGE_SEO_STABILITY_CONTRACT.md`

## Global Constraints

- Authority types remain exactly brand, model, variant, movement, music, component, classification, specimen and product.
- Graph predicates remain the current registry; no new predicate is introduced.
- Semantic writes remain Proposal → Human Approval → Eligibility → Controlled Apply → repository → audit → read-back.
- No legacy Article body import, production/V2 mutation, semantic postmeta/taxonomy fallback, seed, backfill or slug migration.
- Unknown profile/scope/risk values fail closed; unavailable dependencies remain unavailable, not empty.
- Public output is Vietnamese-first and contains no internal UUIDs, stable keys, proposal state or Evidence IDs.

### Task 1: Define validated Knowledge profile and enrichment candidate value objects

**Status:** complete in `f631209`, with corrective semantic hardening.

**Files:** Create `public/wp-content/plugins/nhk-core/src/Domain/Knowledge/KnowledgeFacetProfile.php`, `KnowledgeEnrichmentCandidate.php`; Test `public/wp-content/plugins/nhk-core/tests/Unit/GovernedLivingKnowledgeDomainTest.php`.

- [ ] Write failing tests for supported facets/scopes, unknown-value rejection, candidate classifications, and no generated-copy-as-evidence flag.
- [ ] Run `vendor/bin/phpunit --filter GovernedLivingKnowledgeDomainTest` and confirm RED because classes do not exist.
- [ ] Implement immutable validated value objects using existing UUID codec and closed facet/scope lists.
- [ ] Run the focused test and confirm GREEN.
- [ ] Commit `feat: define governed knowledge profile values`.

### Task 2: Implement read-only enrichment planner

**Status:** corrective hardening complete; broad semantic matcher remains intentionally absent.

**Files:** Create `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentPlanner.php`; Modify `public/wp-content/plugins/nhk-core/src/Contracts/Knowledge/KnowledgeRepository.php`, `EvidenceRepository.php`, `SourceRepository.php`; Test `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeEnrichmentPlannerTest.php`.

- [ ] Write failing tests for same-meaning match, new scoped claim, contradiction, qualification, specimen non-promotion, ambiguous/unsupported input, and exact repeat idempotency.
- [ ] Run the focused test and confirm RED.
- [ ] Add only read methods needed to list claims/evidence and resolve source visibility; implement conservative normalized matching and candidate-only output.
- [ ] Run focused tests and confirm GREEN.
- [ ] Commit `feat: add read-only knowledge enrichment planner`.

### Task 3: Implement current-truth resolver

**Status:** complete in `f631209`.

**Files:** Create `public/wp-content/plugins/nhk-core/src/Application/Knowledge/CurrentTruthResolver.php`, `CurrentTruthPacket.php`; Test `public/wp-content/plugins/nhk-core/tests/Unit/CurrentTruthResolverTest.php`.

- [ ] Write failing tests for compatible claims, qualifiers, contradictions, unresolved conflicts, evidence coverage and scope preservation.
- [ ] Run focused test and confirm RED.
- [ ] Implement deterministic resolver over active claims and public/eligible Evidence; never persist aggregate truth or select a winner on conflict.
- [ ] Run focused tests and confirm GREEN.
- [ ] Commit `feat: resolve current governed knowledge truth`.

### Task 4: Add governed apply candidate contract

**Status:** corrective boundary complete; end-to-end apply/read-back remains an explicit `CODE_GAP`.

**Files:** Create `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentProposalFactory.php`; Modify `public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyService.php` only if an existing hook is required; Test `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeEnrichmentProposalFactoryTest.php`.

- [x] Write failing tests proving candidate payloads target existing Knowledge/Evidence operations, preserve scope/provenance, carry idempotency fingerprint and never write directly.
- [x] Run focused test and confirm RED.
- [x] Implement proposal payload factory using the runtime catalog vocabulary; unresolved evidence, registry gaps and unsupported classifications fail closed with typed diagnostics; create candidates do not fabricate an existing revision.
- [x] Run focused tests and confirm GREEN.
- [x] Commit `fix: align living knowledge with governance operations`.

### Task 5: Implement facet fragment projection and deterministic synthesis boundary

**Status:** corrective fingerprint hardening complete; persisted last-known-good storage and vendor adapter remain open.

**Files:** Create `public/wp-content/plugins/nhk-core/src/Application/Projection/KnowledgeFragmentProjector.php`, `KnowledgeSynthesisPort.php`, `DeterministicKnowledgeSynthesizer.php`, `KnowledgeFragmentProjection.php`; Test `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeFragmentProjectionTest.php`.

- [ ] Write failing tests proving recognition-only changes do not rebuild music/history, dependency fingerprints enable reuse, public fragments omit internal IDs, and unavailable synthesis keeps safe fallback.
- [ ] Run focused test and confirm RED.
- [ ] Implement fragment dependency calculation, evidence-bound Vietnamese deterministic rendering and synthesis port without vendor persistence.
- [ ] Run focused tests and confirm GREEN.
- [ ] Commit `feat: add living knowledge fragment projection`.

### Task 6: Implement SEO stability guard

**Status:** corrective stable-core/risk-result hardening complete; public render verification integration remains open.

**Files:** Create `public/wp-content/plugins/nhk-core/src/Application/Seo/LivingKnowledgeSeoStabilityGuard.php`, `SeoChangeRisk.php`; Test `public/wp-content/plugins/nhk-core/tests/Unit/LivingKnowledgeSeoStabilityGuardTest.php`.

- [ ] Write failing tests for LOW enrichment preserving stable core, MEDIUM diff diagnostics and HIGH human-gate requirement.
- [ ] Run focused test and confirm RED.
- [ ] Implement stable-core comparison and explicit risk classification without guessing indexed state.
- [ ] Run focused tests and confirm GREEN.
- [ ] Commit `feat: protect living knowledge SEO identity`.

### Task 7: Integrate candidate packets with Video, Media and Article read workflows

**Status:** not started; existing contracts do not expose an approved shared adapter seam without extending operation contracts.

**Files:** Modify `VideoIntakeService.php`, `VideoInternalSemanticResearcher.php` or existing adapter boundary, `ArticleResearchPreflight.php`, `ArticleIngestPreflight.php`; Create `MediaKnowledgeEnrichmentPlanner.php` only if an existing Media annotation boundary requires an adapter; Tests in `VideoSemanticCoreTest.php`, `ArticleResearchPreflightTest.php`, and new `KnowledgeEnrichmentIntegrationTest.php`.

- [ ] Write failing tests proving Video user_hint produces scoped candidates only, MediaUsage/depicts does not create Evidence, and Article returns a suggestion packet without changing WordPress body.
- [ ] Run focused tests and confirm RED.
- [ ] Wire the shared planner at preview/research boundaries and retain existing proposal/apply paths.
- [ ] Run focused tests and confirm GREEN.
- [ ] Commit `feat: integrate governed enrichment with content intake`.

### Task 8: Complete governed Living Knowledge E2E acceptance

**Status:** implementation complete; in-memory E2E verified. Runtime integration remains `ENVIRONMENT_BLOCKED` because `NHK_WP_TEST_PATH` is unset.

**Files:** Create `public/wp-content/plugins/nhk-core/tests/Unit/GovernedLivingKnowledgeE2ETest.php`; Modify `public/wp-content/plugins/nhk-core/src/Application/Governance/ProposalEligibilityService.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/WpdbProposalRepository.php`; Test same file and existing Governance regression tests.

- [x] Write the failing acceptance harness for `new_claim`, `supports`, `qualifies` and `contradicts`, with in-memory repositories, transaction rollback, audit and apply-attempt read-back.
- [x] Run the focused test and observe RED caused by missing in-memory approval binding and dependency verification.
- [x] Implement the minimum binding correction: persist/check the complete approval binding fingerprint while retaining existing proposal content/dependency fields and operation vocabulary.
- [x] Run the focused test and observe GREEN, including idempotent replay, stale dependency rejection and failure atomicity.
- [x] Run guarded integration on exact `nhk_v3_test`; record `ENVIRONMENT_BLOCKED` when the WordPress/runtime/database prerequisite is unavailable.
- [x] Keep the existing Odo 62 acceptance in-memory-only and report the two unrelated `OdoMediaIntegrityAuditor` failures separately.

### Task 9: Odo acceptance, public routes, docs and full verification

**Status:** partial; generic unit coverage and full Unit verification are complete, but runtime Odo acceptance requires unavailable live read infrastructure and no data was fabricated.

**Files:** Create `public/wp-content/plugins/nhk-core/tests/Unit/OdoLivingKnowledgeAcceptanceTest.php`; Modify `docs/architecture/V3_EXECUTION_STATE.md` and relevant contracts; no data files.

- [ ] Write failing Odo acceptance tests for 62 white pegs, Sonodo/24, 54/57/62 parity, no 30 cloning, 39 evidence-only, `/odo/` and `/o-do/` behavior.
- [ ] Run focused tests and confirm RED.
- [ ] Implement only generic fixture builders and route assertions; do not seed or mutate runtime data.
- [ ] Run focused tests and confirm GREEN.
- [x] Run complete Unit suite, guarded integration suite with exact DB guard, PHP lint, Composer validation, `git diff --check`, and secret review.
- [x] Update execution state with implemented slices and remaining `ENVIRONMENT_BLOCKED`/`REGISTRY_GAP`/`HUMAN_GATE` entries.
- [ ] Commit `docs: record governed living knowledge checkpoint`.
