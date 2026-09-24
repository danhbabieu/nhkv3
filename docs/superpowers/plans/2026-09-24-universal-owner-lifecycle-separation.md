# Universal Owner Lifecycle Separation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make canonical owner existence, enrichment readiness, and publication readiness independent across Article, Video, Media, generic owners, and future registered owners.

**Architecture:** Extend the existing `CompletionCoordinator`, `OwnerCapability`, `EnrichmentPack`, `SemanticNeed`, and quality result boundaries. Canonical completion is governed by owner identity plus controlled write/read-back; enrichment and publication remain separate derived dimensions. Multi-subject behavior is represented by target-scoped needs and preserved qualification metadata, not by owner-specific engines or weaker Graph applicability.

**Tech Stack:** PHP 8+, WordPress plugin domain/application classes, PHPUnit 11, Composer PSR-4 autoloading.

**Spec:** `docs/superpowers/specs/2026-09-24-universal-owner-lifecycle-design.md`

## Global Constraints

- WordPress native `wp_posts` remains editorial truth; existing domain repositories and Governance remain mutation owners.
- No migration, schema change, staging/production mutation, deployment, push, publication, or direct database writer.
- `VALID OWNER IDENTITY + REQUIRED GOVERNANCE + SUCCESSFUL CONTROLLED WRITE + CANONICAL READ-BACK = CANONICAL OWNER EXISTS`.
- `OWNER IDENTITY != SUBJECT IDENTITY`; reconciliation and idempotency use owner identity.
- `GRAPH_REACHABLE != FACTUALLY_APPLICABLE` and `PUBLIC ASSERTION SPECIFICITY <= SUPPORT SPECIFICITY` remain enforced.
- Missing Knowledge is never padded with invented facts; sparse/unavailable enrichment may remain a valid canonical owner.
- Production code must not branch on Odo, fixture names/IDs, brand/model names, dates, YouTube IDs, Capture UUIDs, Video UUIDs, or WordPress IDs.

## Review Focus

- A valid owner with zero reusable Knowledge must be canonically complete; test in Task 1.
- A failed canonical read-back must block owner existence while editorial warnings remain secondary; test in Task 1.
- Two owners referencing one subject must remain distinct across retries; test in Task 2.
- One owner with two target-scoped needs must retain target identity and applicability; test in Task 3.
- Unsafe public prose may block publication without deleting canonical existence; test in Task 4.

---

### Task 1: Separate canonical completion from enrichment and publication

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoPublicationVerifierTest.php` only if compatibility expectations require updating the existing Video projection assertion

**Interfaces:**
- Consumes: existing `finalize(string $ownerType, string $ownerId, array $evidence = []): array` and `aggregateCapture(...)` inputs.
- Produces: `canonical_existence`, `enrichment_readiness`, and `publication_readiness` packets; compatibility `canonical_state`, `content_state`, `public_state`, and `frontend_state`; `complete` is true only for canonical owner completion.

- [ ] **Step 1: Write failing canonical-separation tests**

Add tests to `CompletionConvergenceTest` for a generic/future owner and Video:

```php
public function test_sparse_or_zero_knowledge_does_not_block_canonical_owner_existence(): void
{
    $packet = (new CompletionCoordinator())->finalize('future_owner', 'owner-1', [
        'canonical_readback' => ['canonical_id' => 'owner-1'],
        'enrichment_readiness' => ['status' => 'SPARSE', 'warnings' => ['KNOWLEDGE_UNAVAILABLE']],
        'publication_readiness' => ['status' => 'BLOCKED', 'blockers' => ['PUBLIC_PROJECTION_UNSAFE']],
    ]);

    self::assertSame('COMPLETE', $packet['canonical_existence']['status']);
    self::assertSame('SPARSE', $packet['enrichment_readiness']['status']);
    self::assertSame('BLOCKED', $packet['publication_readiness']['status']);
    self::assertTrue($packet['complete']);
    self::assertNotContains('KNOWLEDGE_UNAVAILABLE', $packet['blockers']);
}

public function test_missing_canonical_readback_blocks_owner_without_promoting_editorial_warning(): void
{
    $packet = (new CompletionCoordinator())->finalize('video', 'video-1', [
        'content_quality' => 'CONTENT_NEEDS_REVIEW',
        'enrichment_readiness' => ['status' => 'SPARSE', 'warnings' => ['LOW_INFORMATION_GAIN']],
    ]);

    self::assertSame('BLOCKED', $packet['canonical_existence']['status']);
    self::assertFalse($packet['complete']);
    self::assertContains('CANONICAL_READBACK_UNVERIFIED', $packet['blockers']);
    self::assertNotSame('CONTENT_NEEDS_REVIEW', $packet['canonical_existence']['status']);
}
```

- [ ] **Step 2: Run the focused tests and verify the expected failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'CompletionConvergenceTest'`

Expected: FAIL because the current coordinator has no independent lifecycle packets and Video editorial state is still coupled to completion.

- [ ] **Step 3: Implement the minimal capability-driven completion packet**

In `CompletionCoordinator::finalize()`:

1. Keep `readBack()` as the canonical existence proof.
2. Build `canonical_existence` from read-back, canonical state, and owner blockers only.
3. Read `enrichment_readiness` and `publication_readiness` from evidence with fail-closed defaults, without adding their warnings to owner blockers.
4. Remove the `ownerType === 'video'` content branch and use the generic enrichment packet for all owner types.
5. Set `complete` from canonical existence and preserve existing aggregate identity/reconciliation behavior.

Use this shape for every owner:

```php
'canonical_existence' => [
    'status' => $canonical,
    'blockers' => $ownerBlockers,
    'readback_verified' => $canonicalReadbackVerified,
],
'enrichment_readiness' => $enrichment,
'publication_readiness' => $publication,
```

Do not remove compatibility keys until all current tests and consumers are migrated.

- [ ] **Step 4: Run focused and aggregate completion tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'CompletionConvergenceTest|CaptureVideoPublicationVerifierTest'`

Expected: PASS, with existing aggregate tests updated only where they asserted the old conflated `complete` meaning.

- [ ] **Step 5: Run PHP lint for changed files**

Run: `php -l public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php && php -l public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php`

Expected: No syntax errors.

### Task 2: Extend owner capabilities and owner-identity reconciliation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerCapability.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerConsumptionPlan.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/OwnerConsumptionContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php`
- Modify: existing owner registration/composition-root file identified by `rg -n 'OwnerCapabilityRegistry|OwnerCapability::forOwner' public/wp-content/plugins/nhk-core/src` if runtime registration is required

**Interfaces:**
- Consumes: existing four-argument `OwnerCapability::forOwner()` calls.
- Produces: backward-compatible capabilities with policy metadata and `OwnerConsumptionPlan::toArray()['capabilities']` containing identity, canonical, minimum-safe, publication, read-back, and dependency policies.

- [ ] **Step 1: Write failing capability contract tests**

Extend `OwnerConsumptionContractTest`:

```php
public function test_capability_declares_minimum_safe_representation_and_lifecycle_policy(): void
{
    $capability = OwnerCapability::forOwner('future_owner', ['summary'], true, true, [
        'identity_requirements' => ['owner_id', 'source_identity'],
        'canonical_completion' => ['requires_readback' => true],
        'minimum_safe_representation' => ['summary' => 'source_title'],
        'publication_requirements' => ['summary' => 'safe_only'],
        'readback_strategy' => 'canonical_id',
        'dependency_policy' => 'consumed_only',
    ]);

    self::assertSame(['summary' => 'source_title'], $capability->minimumSafeRepresentation);
    self::assertSame('canonical_id', $capability->readbackStrategy);
    self::assertSame('consumed_only', $capability->dependencyPolicy);
}

public function test_two_owners_with_one_subject_remain_distinct_in_completion_identity(): void
{
    $coordinator = new CompletionCoordinator();
    $first = $coordinator->finalize('future_owner', 'owner-a', ['canonical_readback' => ['canonical_id' => 'owner-a'], 'subject_id' => 'subject-1']);
    $second = $coordinator->finalize('future_owner', 'owner-b', ['canonical_readback' => ['canonical_id' => 'owner-b'], 'subject_id' => 'subject-1']);

    self::assertNotSame($first['owner_id'], $second['owner_id']);
    self::assertSame('owner-a', $first['canonical_readback']['canonical_id']);
    self::assertSame('owner-b', $second['canonical_readback']['canonical_id']);
}
```

- [ ] **Step 2: Run the capability tests and verify RED**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'OwnerConsumptionContractTest'`

Expected: FAIL because the capability constructor has no policy argument/properties.

- [ ] **Step 3: Implement backward-compatible capability policy metadata**

Add one optional policy array to `OwnerCapability::forOwner(...)`, normalize only the registered keys, expose readonly properties, and preserve the existing four-argument behavior with safe defaults. Update `OwnerConsumptionPlan::toArray()` to serialize the policy without exposing new semantic vocabulary. Do not derive owner identity from subject identity.

- [ ] **Step 4: Run capability and completion identity tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'OwnerConsumptionContractTest|CompletionConvergenceTest'`

Expected: PASS.

### Task 3: Add target-scoped SemanticNeeds and preserve qualification metadata

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeed.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/UniversalInputEnvelope.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedDecomposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/UniversalEnrichmentCore.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalEnrichmentContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalSemanticNeedDecomposerTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalFacetRetrievalTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalEnrichmentCoreTest.php`

**Interfaces:**
- Consumes: existing `SemanticNeed::fromArray()` and `ClaimRetrievalEngine::retrieveForNeeds()` inputs.
- Produces: `SemanticNeed::targetSubject()`, `SemanticNeed::ownerContext()`, serialized `target_subject`/`owner_context`, and retrieval candidates carrying `target_subject`, `need_id`, applicability, specificity, evidence, graph path, and treatment.

- [ ] **Step 1: Write failing target-scope tests**

Add tests proving two needs can target different subjects and an uncovered target becomes a gap:

```php
public function test_semantic_need_preserves_distinct_target_subject_and_owner_context(): void
{
    $need = SemanticNeed::fromArray([
        'canonical_subject' => ['id' => 'subject-a', 'type' => 'model'],
        'target_subject' => ['id' => 'subject-b', 'type' => 'model'],
        'owner_context' => ['owner_id' => 'article-1', 'owner_type' => 'article'],
        'facet_key' => 'dimensions',
    ]);

    self::assertSame('subject-a', $need->canonicalSubject()['id']);
    self::assertSame('subject-b', $need->targetSubject()['id']);
    self::assertSame('article-1', $need->ownerContext()['owner_id']);
}
```

Add a retrieval test with target A and target B fixtures asserting each candidate retains its originating target and no sibling claim is promoted to exact applicability for the other target.

- [ ] **Step 2: Run the semantic tests and verify RED**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'UniversalEnrichmentContractTest|UniversalSemanticNeedDecomposerTest|UniversalFacetRetrievalTest'`

Expected: FAIL because `SemanticNeed` rejects/omits target and owner context and retrieval always uses the canonical subject as target.

- [ ] **Step 3: Implement target-scoped transient semantics**

Add optional normalized `target_subject` and `owner_context` to `SemanticNeed`, defaulting target to the canonical subject. Update decomposer input mapping and `UniversalInputEnvelope` to preserve `semantic_context`/target records without inferring identities from prose. In `ClaimRetrievalEngine::retrieveForNeeds()`, use the need target for target-scoped retrieval while retaining the original subject in every candidate; serialize target and qualification metadata into retrieval diagnostics and selected claims. Keep graph traversal and applicability checks unchanged.

- [ ] **Step 4: Make Universal Core propagate gaps and target metadata**

Ensure `UniversalEnrichmentCore::content()` passes target-scoped needs unchanged, returns explicit sparse/partial diagnostics for uncovered needs, and does not synthesize fallback claims. Keep `EnrichmentPack` branch names unchanged.

- [ ] **Step 5: Run focused semantic matrix**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'UniversalEnrichmentContractTest|UniversalSemanticNeedDecomposerTest|UniversalFacetRetrievalTest|UniversalEnrichmentCoreTest'`

Expected: PASS; target A/B applicability remains distinct and existing single-subject tests remain green.

### Task 4: Classify quality findings without invalidating canonical owners

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialQualityFinding.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialQualityReport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialQualityGate.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EnrichmentPack.php` if readiness normalization is required by existing branch consumers
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialQualityGateTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialEnrichmentTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/LivingKnowledgeLifecycleTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedLivingKnowledgeCorrectiveTest.php`

**Interfaces:**
- Consumes: existing quality findings and `EditorialQualityReport` dimensions.
- Produces: dimension-tagged findings/reports where canonical owner validity is separate from factual safety, editorial quality, and publication quality; sparse owners expose regeneration eligibility without public rewrite.

- [ ] **Step 1: Write failing quality-boundary tests**

Add tests asserting:

```php
public function test_sparse_editorial_quality_is_not_an_owner_validity_blocker(): void
{
    $report = $this->qualityGate()->evaluate($this->sparsePlan(), $this->sparseDraft(), $this->sparseSeo());

    self::assertSame('READY', $report->dimensions['owner_validity']['status']);
    self::assertContains('LOW_INFORMATION_GAIN', $report->dimensions['editorial_quality']['reasons']);
    self::assertNotContains('LOW_INFORMATION_GAIN', $report->blockers);
}

public function test_unsafe_public_assertion_blocks_publication_but_not_canonical_existence(): void
{
    $report = $this->qualityGate()->evaluate($this->unsafePlan(), $this->unsafeDraft(), $this->unsafeSeo());

    self::assertSame('READY', $report->dimensions['owner_validity']['status']);
    self::assertSame('BLOCKED', $report->dimensions['publication_quality']['status']);
}
```

Add a Living Knowledge regression that a sparse canonical owner becomes `REGENERATION_AVAILABLE`, while failed regeneration leaves the current/stale state unchanged and does not rewrite public content.

- [ ] **Step 2: Run quality/lifecycle tests and verify RED**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'EditorialQualityGateTest|VideoEditorialEnrichmentTest|LivingKnowledgeLifecycleTest|GovernedLivingKnowledgeCorrectiveTest'`

Expected: FAIL because the current report has no owner-validity/publication-quality separation and some sparse/editorial findings still feed shared blockers.

- [ ] **Step 3: Implement dimension classification in existing report objects**

Normalize finding dimensions from rule/source context. Keep factual safety and publication blockers hard where required, retain editorial warnings/repairability as non-owner blockers, and add explicit `owner_validity`, `factual_safety`, `editorial_quality`, and `publication_quality` entries to the report. Preserve existing public-copy guards and Brain 1/Brain 2 authority rules.

- [ ] **Step 4: Connect regeneration readiness without public mutation**

Use existing Living Knowledge lifecycle diagnostics to expose a regeneration-available state for sparse owners, register only consumed claims as dependencies, and keep controlled Governance/read-back as the only update path. Do not add a new persistence table or auto-republish behavior.

- [ ] **Step 5: Run focused quality/lifecycle tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'EditorialQualityGateTest|VideoEditorialEnrichmentTest|LivingKnowledgeLifecycleTest|GovernedLivingKnowledgeCorrectiveTest'`

Expected: PASS with canonical validity surviving editorial review and publication-only blockers.

### Task 5: Add universal acceptance coverage, update execution evidence, and verify

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalOwnerLifecycleAcceptanceTest.php`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md` only if the completed local evidence changes a current parity statement

**Interfaces:**
- Consumes: the lifecycle/capability/semantic/quality interfaces produced by Tasks 1–4.
- Produces: generic Article, Video, Media, and synthetic future-owner acceptance evidence and the exact final report fields requested by the user.

- [ ] **Step 1: Write the generic acceptance matrix tests**

Create production-shaped fixtures with neutral IDs (`owner-a`, `owner-b`, `subject-a`, `subject-b`) and cover:

```php
public function test_article_video_media_and_future_owner_share_canonical_existence_rule(): void
{
    foreach (['article', 'video', 'media', 'future_owner'] as $type) {
        $packet = $this->coordinator->finalize($type, $type . '-owner', [
            'canonical_readback' => ['canonical_id' => $type . '-owner'],
            'enrichment_readiness' => ['status' => 'UNAVAILABLE'],
            'publication_readiness' => ['status' => 'BLOCKED'],
        ]);

        self::assertSame('COMPLETE', $packet['canonical_existence']['status']);
        self::assertTrue($packet['complete']);
    }
}
```

Add tests for owner identity distinctness, many-to-one references, target-scoped needs, applicability/specificity, duplicate Knowledge, information gain, outcome-unknown same-identity reconciliation, and special-case scan output.

- [ ] **Step 2: Run the acceptance test and verify RED where coverage is missing**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'UniversalOwnerLifecycleAcceptanceTest'`

Expected: Any failure identifies an uncovered invariant from the approved spec; do not weaken the assertion to make the test pass.

- [ ] **Step 3: Implement only remaining cross-boundary wiring**

Use the failing acceptance output to wire existing adapters/registries and compatibility serializers. Do not add owner-type conditionals to Universal Core or a new owner pipeline. If a requirement cannot be satisfied without a migration, stop and report the evidence instead of creating one.

- [ ] **Step 4: Run focused architecture matrix**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'UniversalOwnerLifecycleAcceptanceTest|CompletionConvergenceTest|OwnerConsumptionContractTest|UniversalEnrichmentContractTest|UniversalSemanticNeedDecomposerTest|UniversalFacetRetrievalTest|EditorialQualityGateTest|LivingKnowledgeLifecycleTest|VideoEditorialEnrichmentTest|MediaConsumptionServiceTest|ArticleEditorialAdapterTest'`

Expected: PASS with no production special-case references introduced.

- [ ] **Step 5: Run full verification**

Run: `composer test`

Expected: Unit and Contract suites pass; Integration results are reported separately if the documented `nhk_v3_test` environment is unavailable.

Run: `composer lint`

Expected: Every PHP file reports no syntax errors.

Run: `composer validate --no-check-publish`

Expected: Valid Composer metadata, allowing only the repository’s existing license warning if present.

Run: `git diff --check`

Expected: No whitespace errors.

Run: `rg -n 'Odo|Jacquemart|1954|1957|youtube|YouTube|Capture UUID|Video UUID|fixture' public/wp-content/plugins/nhk-core/src`

Expected: No new production special-case branch; pre-existing legitimate validation references must be listed in the final report.

- [ ] **Step 6: Update execution evidence and inspect final diff**

Append a dated local/no-mutation checkpoint to `docs/architecture/V3_EXECUTION_STATE.md` with the first-broken boundary, changed shared contracts, focused/full test counts, lint/diff/scan results, and any Integration environment gate. Re-read the approved spec, run `git status --short --branch`, and confirm no migration, environment file, credential, database dump, or unrelated change is included.

- [ ] **Step 7: Commit implementation in logical slices**

Create one commit per completed task using messages such as:

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php
git commit -m "fix: separate canonical owner completion from readiness"
```

Commit only after the task’s focused tests and lint pass. Do not push or create a pull request.
