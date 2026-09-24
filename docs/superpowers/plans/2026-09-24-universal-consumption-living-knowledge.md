# Universal Consumption and Living Knowledge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Close the generic `EnrichmentPack` consumption and living-knowledge lifecycle without changing Universal Core, semantic eligibility, Graph direction, or canonical owner writers.

**Architecture:** Add a capability-driven transient owner consumption contract. Media will produce bounded caption/alt/description plans from the shared pack, run a Media-specific quality gate, and expose an apply packet that must be executed through the existing `MediaBindingService`, whose canonical read-back remains the completion gate. Add a generic dependency lifecycle service over the existing projection dependency index/invalidation store for stale state, deterministic regeneration preview/diff, governed-apply requirement, and post-apply dependency revision reconciliation.

**Tech Stack:** PHP 8.2+, PHPUnit 11, existing NHK Core application services and in-memory projection stores.

**Spec:** User-provided task in `Văn bản đã dán.txt` (system-wide consumption/lifecycle closure).

## Global Constraints

- Do not modify Universal Core, SemanticNeed, applicability, Coverage, KnowledgeUnit, selector, or Graph data unless a generic regression proves it.
- No schema/migration, runtime mutation, staging mutation, production mutation, deployment, or push.
- No silent rewrite: Knowledge change only creates stale/regeneration state and a preview candidate.
- Governance remains the only durable semantic mutation owner; MediaBindingService remains the sole MediaUsage writer.
- Register only dependencies actually consumed by a validated owner surface.
- Every controlled owner apply requires canonical read-back before current/completed state.

## Review Focus

- Contextual or ineligible claims must never become Media exact facts: covered by Media policy tests.
- Visual alt text must not inherit invisible background knowledge: covered by Media quality tests.
- A dependency revision change must discover multiple owner types without rewriting them: covered by lifecycle tests.
- Failed canonical read-back must leave the dependency stale: covered by lifecycle reconciliation tests.
- A future owner with one text surface and no SEO must work through registration only: covered by capability matrix tests.

### Task 1: Shared owner consumption contract and capability matrix

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerCapability.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerConsumptionPlan.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerCapabilityRegistry.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/OwnerConsumptionContractTest.php`

**Interfaces:**
- `OwnerCapability::forOwner(string $ownerType, array $surfaces, bool $canonicalReadback, bool $dependencies): self`
- `OwnerConsumptionPlan::toArray(): array`
- `OwnerCapabilityRegistry::register(OwnerCapability $capability): void` and `get(string $ownerType): ?OwnerCapability`

- [ ] Write failing tests for Media surfaces, Generic future-owner surfaces, no-SEO capability, canonical read-back capability, dependency capability, applicability/specificity/treatment/public-safety fields, and trace/coverage/gap preservation.
- [ ] Run the focused test and observe missing-class failure.
- [ ] Implement immutable capability/plan value objects and registry with no owner-name branching in the generic contract.
- [ ] Run focused tests and the existing Universal owner adapter test.
- [ ] Commit `feat: add owner-neutral consumption contract`.

### Task 2: Media consumption policy, quality, and adapter connection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaConsumptionPlanner.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaConsumptionQualityGate.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaUniversalEnrichmentAdapter.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaConsumptionPlannerTest.php`

**Interfaces:**
- `MediaConsumptionPlanner::plan(EnrichmentPack $pack, array $mediaContext = []): OwnerConsumptionPlan`
- `MediaConsumptionQualityGate::evaluate(OwnerConsumptionPlan $plan): array`
- `MediaUniversalEnrichmentAdapter::consume(UniversalInputEnvelope $input, array $mediaContext = []): OwnerConsumptionPlan`

- [ ] Write failing tests for caption/alt/description generation, excluded/contextual claims, invisible detail exclusion, sparse output, provenance/internal leakage, bounded repair without new trace, and adapter non-dead-end behavior.
- [ ] Run focused tests and observe missing consumer behavior.
- [ ] Implement deterministic surface policies: caption may use public selected facts/context; alt only observations/visual-support facts; description may use supported contextual material; all surfaces retain claim trace and dependency inputs.
- [ ] Implement quality checks for eligibility, specificity upgrades, internal leakage, visual support, boilerplate/identical surfaces, and trace-preserving repair diagnostics.
- [ ] Connect adapter consumption to the existing shared pack without changing enrichment selection.
- [ ] Run focused tests plus Media adapter regressions.
- [ ] Commit `feat: connect media to shared consumption policy`.

### Task 3: Controlled Media plan/read-back boundary

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaConsumptionService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaConsumptionServiceTest.php`

**Interfaces:**
- `MediaConsumptionService::prepare(OwnerConsumptionPlan $plan, array $target): array`
- `MediaConsumptionService::apply(array $approvedPacket): array`
- `MediaConsumptionService::readback(array $result): array`

- [ ] Write failing tests proving planned is not written, Governance approval is required, the packet delegates only to existing `MediaBindingService`, canonical read-back is required, and uncertain identity reconciles without duplicate Media.
- [ ] Run focused tests and observe the missing service.
- [ ] Implement plan-only preparation plus an explicit approved packet adapter around `MediaBindingService`; never write directly to repositories and never mark completion before read-back.
- [ ] Run focused tests and existing Media binding/completion tests.
- [ ] Commit `feat: close controlled media consumption boundary`.

### Task 4: Generic living-knowledge lifecycle and regeneration preview

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/LivingKnowledgeLifecycleService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/RegenerationPreview.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Projection/InMemoryProjectionDependencyIndex.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/LivingKnowledgeLifecycleTest.php`

**Interfaces:**
- `LivingKnowledgeLifecycleService::registerConsumed(array $owner, array $dependencies): void`
- `LivingKnowledgeLifecycleService::knowledgeChanged(string $kind, string $id, int $revision): array`
- `LivingKnowledgeLifecycleService::preview(array $owner, array $current, array $proposed): RegenerationPreview`
- `LivingKnowledgeLifecycleService::reconcileReadback(array $owner, array $readback): array`

- [ ] Write failing tests for selected-only dependency registration, multi-owner stale discovery, no automatic rewrite, regeneration availability, latest-pack preview/diff, fact removal, governed-apply requirement, failed read-back remaining stale, successful read-back updating dependency revision, and future-owner participation.
- [ ] Run focused tests and observe missing lifecycle behavior.
- [ ] Implement generic dependency registration/revision refresh over existing index and invalidation service; make in-memory duplicate keys update the consumed revision and surface metadata.
- [ ] Implement deterministic bounded preview/diff and state transitions using existing projection statuses plus explicit lifecycle diagnostics, without a new persisted enum or schema.
- [ ] Run focused lifecycle and projection regression tests.
- [ ] Commit `feat: add living knowledge regeneration lifecycle`.

### Task 5: Checkpoint documentation and verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: existing focused/regression suites

- [ ] Run focused Media/consumption/lifecycle tests, Universal Core, graph direction, applicability, Coverage, KnowledgeUnit, selector, Article, Video, V2.3, V2.4, and information-gain suites.
- [ ] Run full Unit with 512M, Contract, guarded Integration, PHP lint, Composer validate, diff check, and secret/special-case scans.
- [ ] Record exact results, environment gate status, migration/runtime mutation status, files, commits, and next blocker in execution state.
- [ ] Commit `docs: record consumption and living knowledge checkpoint`.

## Self-review

The plan covers the requested consumer contract, Media surfaces/quality/read-back, dependency registration, stale and regeneration lifecycle, multi-owner invalidation, removal diff, Governance boundary, future-owner capability, and all requested regression/verification gates. It intentionally reuses `MediaBindingService`, `ProjectionInvalidationService`, `ProjectionDependencyIndex`, and existing projection statuses, so no schema or parallel Governance engine is introduced.
