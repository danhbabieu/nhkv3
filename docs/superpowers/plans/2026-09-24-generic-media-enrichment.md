# Generic NHK V3 Media Enrichment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Capture `MEDIA_ENRICHMENT` generic across registered owners and roles, with capability-driven canonical/projection/public completion and idempotent retry.

**Architecture:** Add a runtime owner-capability contract over the active endpoint/entity registries. Route all media bindings through the governed MediaBindingService, then evaluate a single capability-driven completion packet covering canonical existence, MediaUsage, projection and public/frontend evidence only where applicable.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress/WPDB adapters, existing NHK V3 registries and Governance services.

**Spec:** `docs/superpowers/specs/2026-09-24-generic-media-enrichment-design.md`

## Global Constraints

- WordPress native `wp_posts` remains the sole Article editorial source of truth.
- Runtime registries/contracts are the source of truth; do not hard-code owner lists, UUIDs, URLs or fixtures.
- MediaUsage remains distinct from Media, MediaAsset, Video, Knowledge, Graph and Governance.
- All durable semantic mutation remains Capture → Governance/Controlled Apply → canonical readback.
- Preserve optimistic revision, idempotency, USER_EXPLICIT/PINNED and fail-closed invariants.
- Do not mutate V2/production data, run destructive migrations, or deploy production.
- Preserve IMAGE_ARTICLE, TEXT_ARTICLE, VIDEO, KNOWLEDGE_DELTA, KNOWLEDGE_REPAIR, Authority and relationship flows.

## Review Focus

- A registry-supported endpoint without an explicit media capability must fail closed instead of silently becoming a MediaUsage owner; test in Task 1.
- A public owner with canonical MediaUsage but stale projection must remain retryable; test in Task 4.
- A canonical-only owner must not be blocked by frontend verification; test in Task 4.
- USER_EXPLICIT/PINNED representative usage must survive a system-auto replay and replacement race; test in Task 2.
- Retry must reuse the same Capture and completed binding operation without duplicate usage; test in Task 5.

---

### Task 1: Introduce registry-backed Media owner capabilities

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaOwnerCapability.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaOwnerCapabilityRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerCapability.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerCapabilityRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaOwnerCapabilityRegistryTest.php`

**Interfaces:**
- Consumes: `EndpointTypeRegistry`, `EntityTypeRegistry`, existing public route/eligibility callbacks and `MediaUsageRoleRegistry`.
- Produces: `MediaOwnerCapabilityRegistry::forEndpoint(string): ?MediaOwnerCapability`, `all()`, and capability packets used by binding/completion.

- [ ] **Step 1: Write failing registry tests**

```php
public function test_registered_endpoint_can_declare_media_roles_and_surface_requirements(): void
{
    $registry = new MediaOwnerCapabilityRegistry();
    $registry->register(MediaOwnerCapability::forEndpoint('model', [MediaUsageRoleRegistry::REPRESENTATIVE], true, true, true));
    $capability = $registry->forEndpoint('model');
    self::assertTrue($capability->supportsRole(MediaUsageRoleRegistry::REPRESENTATIVE));
    self::assertTrue($capability->requiresProjection);
    self::assertTrue($capability->requiresFrontendReadback);
}

public function test_unknown_or_unsupported_endpoint_is_not_inferred(): void
{
    $registry = new MediaOwnerCapabilityRegistry();
    self::assertNull($registry->forEndpoint('unregistered_owner'));
}
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter MediaOwnerCapabilityRegistryTest --colors=never`

Expected: FAIL because the capability classes do not yet exist.

- [ ] **Step 3: Implement the minimal immutable capability and registry**

The capability must validate the endpoint type, registered roles, representative slot policy, projection requirement, public-surface requirement and frontend requirement. The registry must normalize keys and expose only explicitly registered capabilities; it must not invent missing owner types.

- [ ] **Step 4: Wire runtime construction from the existing endpoint/entity registries**

Build the registry after `CoreEndpointResolverRegistrar::register()`. Authority entity definitions may receive the default role/surface policy through a single registry adapter, while non-Authority endpoints use their registered resolver plus injected read/projection policies. Keep the policy data centralized; do not add `if Model`/`if Article` branches to Capture.

- [ ] **Step 5: Run the focused test and the existing owner capability tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'MediaOwnerCapabilityRegistryTest|UniversalOwnerAdapterTest|OwnerConsumptionContractTest' --colors=never`

Expected: PASS.

- [ ] **Step 6: Commit the capability boundary**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Media/MediaOwnerCapability.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaOwnerCapabilityRegistry.php public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerCapability.php public/wp-content/plugins/nhk-core/src/Application/Consumption/OwnerCapabilityRegistry.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/MediaOwnerCapabilityRegistryTest.php
git commit -m "feat: add registry-backed media owner capabilities"
```

### Task 2: Make MediaBindingService generic and role-aware

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Media/MediaBindingPort.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Media/MediaUsageRoleRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceGenericOwnerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingReadbackContractTest.php`

**Interfaces:**
- Consumes: `MediaOwnerCapabilityRegistry`, registered endpoint resolvers/readers, canonical Media/Asset/Usage repositories.
- Produces: generic `bindMany()` results containing normalized target, requested role, operation receipt, canonical readback and invalidation receipt.

- [ ] **Step 1: Write failing matrix tests**

Use generated UUIDs and in-memory repositories for `brand`, `model`, `variant`, `classification`, `knowledge`, `wp_post`, `video`, `media` and every additional capability registered by the fixture. Assert exact target type/id and role in the result. Add separate tests for `evidence`, `technical_detail`, `featured_primary`, `inline_primary` and `inline_supporting` according to capability policy.

- [ ] **Step 2: Run the tests and verify the current implementation fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter MediaBindingServiceGenericOwnerTest --colors=never`

Expected: FAIL for non-Authority targets and non-representative roles.

- [ ] **Step 3: Implement registry-backed target resolution**

Resolve the target through the registered endpoint resolver/read port, preserving `wp_post`’s native blog/post key. Do not convert Knowledge, Video, Media, Source or Evidence into Authority entities. Reject missing or inactive canonical endpoints with typed diagnostics.

- [ ] **Step 4: Implement capability role and placement validation**

Validate the requested role against the owner capability and the global role registry. Preserve explicit selection metadata. Use a capability-provided representative slot key/cardinality rather than a target-type conditional. Keep logical retirement and revision/CAS behavior for replacement.

- [ ] **Step 5: Implement idempotent canonical readback and invalidation receipt**

Read the exact usage by endpoint/usage ID, verify Media ID, role, placement and active-slot invariant, then emit the existing projection/SEO invalidation hooks and return their structured result. A failed readback or invalidation is retryable and cannot return `COMPLETE`.

- [ ] **Step 6: Run focused binding and existing Media tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'MediaBindingServiceGenericOwnerTest|MediaBindingReadbackContractTest|MediaUsageReconcilerTest|MediaPresentationProjectionTest' --colors=never`

Expected: PASS.

- [ ] **Step 7: Commit the generic binding change**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php public/wp-content/plugins/nhk-core/src/Contracts/Media/MediaBindingPort.php public/wp-content/plugins/nhk-core/src/Domain/Media/MediaUsageRoleRegistry.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceGenericOwnerTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingReadbackContractTest.php
git commit -m "feat: bind media usage through registered owner capabilities"
```

### Task 3: Replace Capture’s ad hoc MediaEnrichment branch

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureMediaEnrichmentMatrixTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php`

**Interfaces:**
- Consumes: capability-aware `MediaBindingPort`, persisted Capture phase receipts and staged asset IDs.
- Produces: one `media_enrichment` receipt whose bindings are the current owner outcomes and whose required owners come from the capability packet.

- [ ] **Step 1: Write failing Capture matrix tests**

Cover new binding for Brand, Model, Variant, Classification, Knowledge and Article plus registered Video/Media owners. Assert that the target owner is bound, the requested role is retained, no Article owner is injected into pure MediaEnrichment, and no unrelated semantic write is called.

- [ ] **Step 2: Run the matrix and verify the current branch fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter EditorialCaptureMediaEnrichmentMatrixTest --colors=never`

Expected: FAIL for targets not represented by the current typed-binding fast path and for roles currently forced to `featured_primary`.

- [ ] **Step 3: Route both typed and generic inputs through one binding request builder**

Normalize target, media reference, role, placement, selection metadata and Capture-bound idempotency key. Reuse staged Media IDs and never re-ingest successful assets on retry. Remove the direct `MediaService::addUsage` path from `MEDIA_ENRICHMENT`.

- [ ] **Step 4: Derive required owners from capability evidence**

Replace the intent-specific fixed owner list for MediaEnrichment with the returned capability/binding packet. Keep Media itself as a required canonical dependency only when the binding contract declares it; never add Article, Knowledge or another semantic owner solely because the Capture is finalizing.

- [ ] **Step 5: Run Capture regressions**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'EditorialCaptureMediaEnrichmentMatrixTest|EditorialCaptureContinuationTest|CaptureCurrentOutcomeReducerTest|CapturePhaseReceiptReducerTest' --colors=never`

Expected: PASS.

- [ ] **Step 6: Commit the Capture orchestration change**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureMediaEnrichmentMatrixTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php
git commit -m "fix: route media enrichment through generic capture binding"
```

### Task 4: Implement capability-driven final readback and projection verification

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentFinalReadbackPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentFrontendReadbackVerifier.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentCompletionEvaluator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentCompletionEvaluatorTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentFinalReadbackPolicyTest.php`

**Interfaces:**
- Consumes: capability packet, binding receipt, canonical repositories, projection callback and public/frontend verifier.
- Produces: typed final-readback packet with `canonical_existence`, `relation_or_usage_state`, `dependency_state`, `projection_state`, `public_state`, `frontend_state`, `missing_required_owners` and retryable blockers.

- [ ] **Step 1: Write failing evaluator tests for cases A–J**

Assert canonical-only completion without frontend, projection-required blocking on projection failure, public-required blocking on stale public media, invalid target failure, unsupported role rejection, representative cardinality and pinned protection. Use multiple owner types generated from the registry and no fixture names.

- [ ] **Step 2: Run the evaluator tests and verify they fail**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter MediaEnrichmentCompletionEvaluatorTest --colors=never`

Expected: FAIL because the current policy treats MediaUsage as sufficient and CompletionCoordinator owns a hard-coded public list.

- [ ] **Step 3: Implement the evaluator as the single completion contract**

Evaluate only gates declared by the capability. Require exact canonical owner/MediaUsage identity and readback. Do not require Article owner for pure media enrichment. Map every failed required gate to `FAILED_RETRYABLE` unless the input is invalid/unsupported, which is a fail-closed permanent request error.

- [ ] **Step 4: Remove the hard-coded public owner list from CompletionCoordinator**

Accept capability-derived surface requirements in evidence. Preserve existing non-media completion behavior by using the current default for callers that do not provide a MediaEnrichment capability packet, without adding another owner list.

- [ ] **Step 5: Make EntityMediaProjection role/capability aware**

Use canonical MediaUsage rows and declared role mappings. Preserve gallery/evidence ordering and avoid reading a legacy field as a competing source. Ensure representative projection returns the new Media ID only after active-slot and Media readiness checks pass.

- [ ] **Step 6: Run focused completion/projection tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'MediaEnrichmentCompletionEvaluatorTest|MediaEnrichmentFinalReadbackPolicyTest|MediaBindingReadbackContractTest|PublicProjectionGapTest|FrontendSemanticProjectionV2Test|EntityMediaProjection' --colors=never`

Expected: PASS.

- [ ] **Step 7: Commit final readback/projection changes**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentFinalReadbackPolicy.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentFrontendReadbackVerifier.php public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentCompletionEvaluator.php public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentCompletionEvaluatorTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentFinalReadbackPolicyTest.php
git commit -m "fix: make media enrichment completion capability driven"
```

### Task 5: Make retry/resume phase-aware and idempotent

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentRetryTest.php`

**Interfaces:**
- Consumes: persisted Capture phase receipts and binding operation state.
- Produces: same Capture ID, same request fingerprint, no duplicate canonical rows, and completion after transient re-readback/projection recovery.

- [ ] **Step 1: Write failing retry tests**

Create a Capture that fails after usage apply, one that fails after projection invalidation, and one that fails public readback. Retry with the same input and assert the operation is resumed, not recreated; assert only one active usage remains and explicit pinned metadata survives.

- [ ] **Step 2: Run retry tests and verify the current implementation fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter MediaEnrichmentRetryTest --colors=never`

Expected: FAIL for phase resume and duplicate-safe projection/readback recovery.

- [ ] **Step 3: Implement resume from durable phase receipts**

Read the existing Capture phase receipt and MediaBindingOperation receipt. Skip physical ingest and completed canonical apply, rerun only missing projection/readback gates, and preserve the original Capture ID/fingerprint. Reject changed binding packets as idempotency conflicts.

- [ ] **Step 4: Run retry and idempotency regressions**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'MediaEnrichmentRetryTest|EditorialCaptureContinuationTest|MediaServiceUsageIdentityTest|CaptureMediaIdsReuseTest' --colors=never`

Expected: PASS.

- [ ] **Step 5: Commit retry changes**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentRetryTest.php
git commit -m "fix: resume media enrichment idempotently"
```

### Task 6: Add compatibility/contract coverage and update execution state

**Files:**
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentCompatibilityTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Contract/MediaEnrichmentCapabilityContractTest.php`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md` only if the current checkpoint requires a dated media/projection status entry

**Interfaces:**
- Consumes: all previous task outputs.
- Produces: regression evidence for existing intents and a dated execution-state checkpoint.

- [ ] **Step 1: Write compatibility tests**

Cover IMAGE_ARTICLE, TEXT_ARTICLE, VIDEO, KNOWLEDGE_DELTA, KNOWLEDGE_REPAIR, Authority intent and relationship operations. Assert no media capability changes alter their existing owner/gate semantics.

- [ ] **Step 2: Run focused compatibility and contract tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'MediaEnrichmentCompatibilityTest|MediaEnrichmentCapabilityContractTest|ContentIntentRouterTest|AuthorityIntentPlannerTest|ExplicitRelationIntentPlannerTest' --colors=never`

Expected: PASS.

- [ ] **Step 3: Update execution state with evidence**

Record root cause, files, owner/role matrix, test counts, integration availability, no-mutation boundary and remaining live acceptance gates. Do not claim Odo or public runtime verification unless actually observed.

- [ ] **Step 4: Commit compatibility evidence**

```bash
git add public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentCompatibilityTest.php public/wp-content/plugins/nhk-core/tests/Contract/MediaEnrichmentCapabilityContractTest.php docs/architecture/V3_EXECUTION_STATE.md docs/architecture/V2_V3_PARITY_MATRIX.md
git commit -m "test: cover generic media enrichment compatibility"
```

### Task 7: Full verification and bounded fixture acceptance

**Files:**
- Modify only files already changed by Tasks 1–6 if verification finds a defect.

- [ ] **Step 1: Run focused Media/Capture/Projection suite**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'Media|Capture|Projection|EditorialCapture|ContentIntent' --colors=never`

- [ ] **Step 2: Run the complete Unit suite**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite Unit --colors=never`

- [ ] **Step 3: Run Contract tests**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite Contract --colors=never`

- [ ] **Step 4: Run guarded Integration tests when configured**

Run: `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite Integration --colors=never`

Expected when unavailable: explicit skip/bootstrap evidence, never a claim of integration pass.

- [ ] **Step 5: Run PHP lint and repository checks**

Run: `find public/wp-content/plugins/nhk-core/src public/wp-content/plugins/nhk-core/tests -name '*.php' -print0 | xargs -0 -n1 php -l`

Run: `git diff --check`

Run: `git status --short --branch`

- [ ] **Step 6: Perform read-only fixture verification**

Use the existing approved read-only/deployment verification path to inspect the Odo 30 route and at least one non-Model owner. Do not direct-write WordPress or call production/staging mutation. If the runtime is unavailable, record the exact blocker and leave `ODO30_REAL_CASE=UNVERIFIED`.

- [ ] **Step 7: Commit only after fresh verification**

Review `git diff`, secret scan, test output and execution state. Commit the implementation only when all required local gates pass; do not deploy.
