# Content Preparation Orchestrator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a resumable preparation-first Capture seam that locks one canonical subject packet before Article creation/composition and preserves governed semantic-owner enrichment without allowing Article-owned relations before preparation.

**Architecture:** Add one immutable `ContentPreparationResult` and one `ContentPreparationOrchestrator` under the existing Capture application boundary. The orchestrator reuses the existing interpreter/resolver/Knowledge/Governance paths, persists resumable preparation state in the existing Capture context/diagnostics, and is called by `EditorialCaptureCoordinator` before `draftCreator`; downstream Article, Article-owned `wp_post → about → subject`, composition and MediaUsage placement require `PREPARED`.

**Tech Stack:** PHP 8.x, PHPUnit 11, existing NHK Core application/domain services, existing `CaptureRecord`/`CaptureRepository` persistence boundary, no migration or new database table.

**Spec:** `docs/superpowers/specs/2026-09-23-content-preparation-orchestrator-design.md`

## Global Constraints

- `PREPARED`, `REVIEW_REQUIRED` and `BLOCKED` remain application workflow outcomes, not semantic owners or new domain status tables.
- Preparation state is resumable through the existing durable Capture and must reuse the same idempotency key/request fingerprint.
- No duplicate Authority, Knowledge, Graph, Article or Media owner may be created on retry.
- Article-owned semantic relations, especially `wp_post → about → subject`, require final `PREPARED`.
- Semantic-owner enrichment relations may occur before final preparation only through Proposal → Governance → Apply → canonical readback → re-resolve.
- Physical/canonical Video or Media intake may inspect supplied sources before preparation, but cannot automatically create primary subject, `about`, Knowledge truth or Evidence truth before packet lock.
- Do not touch Article 636, deploy, staging or production data.
- Do not create a new semantic owner, direct Graph writer, legacy Article-body migration, persistence table or schema migration.
- Preserve existing Content Intent rules: Video, Knowledge-only and Media-enrichment inputs do not implicitly create an Article.

## Review Focus

- Interrupted after governed semantic enrichment and before draft creation: retry resumes the same Capture and does not duplicate owners or proposals. Test in `EditorialCaptureConvergenceE2ETest`.
- Ambiguous primary subject: preparation returns `REVIEW_REQUIRED` and draft/composer/Article-owned relation/media placement callbacks are not invoked. Test in `ContentPreparationOrchestratorTest`.
- Explicit UUID plus conflicting prose/hints: explicit UUID wins and the final packet retains the canonical revision. Test in `ContentPreparationOrchestratorTest`.
- Physical Video/Media input with semantic-looking observations: source intake is retained as candidate context, but no primary subject/Knowledge/Evidence/`about` relation is created before packet lock. Test in `ContentPreparationOrchestratorTest`.
- Governed semantic-owner relation: it may be applied before final packet lock only through the injected governed callback and must be re-read and re-resolved. Test in `ContentPreparationOrchestratorTest`.

---

### Task A: Add the preparation result value object

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationResult.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`

**Interfaces:**
- Consumes: `SubjectResolutionPacket`, preparation status, candidate/gap/plan arrays, read-back diagnostics, blockers and warnings.
- Produces: immutable `ContentPreparationResult` with `status`, `subjectResolutionPacket`, `candidates`, `gaps`, `plan`, `enrichment`, `blockers`, `warnings`, and `toArray(): array`.

- [ ] **Step 1: Write the failing value-object tests**

Add tests that construct a prepared result with a valid `SubjectResolutionPacket`, assert `toArray()` contains the packet exactly once under a canonical packet key, and assert the result does not contain raw input/body fields. Add a test that rejects an unknown workflow status with `InvalidArgumentException`.

- [ ] **Step 2: Run the focused test to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php --filter 'PreparationResult'`

Expected: FAIL because `ContentPreparationResult` does not exist.

- [ ] **Step 3: Implement the minimal immutable value object**

Use a `final readonly class` in the existing Capture namespace. Accept only the three requested transient outcomes, normalize array fields to arrays, preserve the packet as a `SubjectResolutionPacket` or null, and return a body-free array. Do not add an enum, database status or CaptureStage.

- [ ] **Step 4: Run the focused tests to verify green**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php --filter 'PreparationResult'`

Expected: PASS for the value-object tests.

- [ ] **Step 5: Commit the value object**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationResult.php public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php
git commit -m "feat: add content preparation result"
```

### Task B: Implement candidate discovery, gap analysis and governed re-resolution

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`

**Interfaces:**
- Consumes: `SubjectResolutionService`, optional existing Knowledge/Graph readers, and a Capture-bound callable for governed semantic enrichment.
- Produces: `prepare(array $input, array $interpretation, array $assets = [], array $context = []): ContentPreparationResult`.

- [ ] **Step 1: Write the failing orchestrator tests**

Add these focused tests with generic UUIDs and generic entity names:

```php
public function test_explicit_uuid_produces_prepared_packet_after_fresh_resolution(): void {}
public function test_ambiguous_primary_subject_returns_review_required_without_side_effect_callbacks(): void {}
public function test_missing_candidate_without_supported_evidence_never_creates_semantic_truth(): void {}
public function test_governed_semantic_owner_enrichment_is_read_back_and_re_resolved(): void {}
public function test_prose_mention_does_not_create_article_owned_relation_without_prepared_plan(): void {}
public function test_media_or_video_input_remains_candidate_context_before_packet_lock(): void {}
```

The resolver test double must return a first resolution and a changed fresh resolution after enrichment. The enrichment callback must record its invocation, return a canonical read-back and a registered semantic-owner relation plan, and never be a direct Graph repository call.

- [ ] **Step 2: Run the focused tests to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`

Expected: FAIL because the orchestrator class and `prepare()` method do not exist.

- [ ] **Step 3: Implement candidate-only discovery and gap classification**

Build candidate inputs from `subject_hints`, the existing interpretation, title/topic and user observations. Preserve source labels such as explicit UUID, stable key, hint, title/topic and content mention. Resolve through the injected `SubjectResolutionService`; do not create Authority, Knowledge, Evidence or Graph records from a candidate. Classify unresolved/ambiguous/unsupported candidates in the result arrays.

- [ ] **Step 4: Implement governed enrichment and fresh re-resolution**

Invoke the injected governed callback only for a missing candidate with explicit supported evidence. Pass Capture identity, request fingerprint, candidate, evidence/provenance packet, current resolution and prepared relation intent. Require a successful canonical read-back before treating enrichment as usable. Permit only semantic-owner relations represented by the callback's governed result; reject direct relation side effects. Resolve again against the fresh state and construct one `SubjectResolutionPacket` from that final resolution.

- [ ] **Step 5: Implement status selection and fail-closed diagnostics**

Return `REVIEW_REQUIRED` for an ambiguous primary subject or insufficient evidence, `BLOCKED` for unsupported/failed required dependencies, and `PREPARED` only when the final packet is resolved and required governed/read-back checks pass. Keep physical Media/Video records in candidate context and never infer primary subject, Knowledge, Evidence or Article-owned `about` relations from them.

- [ ] **Step 6: Run the focused tests to verify green**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`

Expected: PASS, including the explicit-UUID precedence, ambiguity, missing evidence, governed re-read, prose-relation prohibition and Media/Video candidate tests.

- [ ] **Step 7: Commit the orchestrator**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php
git commit -m "feat: orchestrate content preparation before composition"
```

### Task C: Inject preparation before draft creation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:**
- Consumes: `ContentPreparationOrchestrator::prepare()` and `ContentPreparationResult`.
- Produces: Capture diagnostics/context containing resumable preparation state and the final packet for later phases.

- [ ] **Step 1: Write the failing coordinator-order test**

Add a generic Capture test with callbacks that append their phase name to an array. Assert the order is `prepare`, then `draft`, then `compose` for a prepared result. Assert an ambiguous result ends after `prepare` with no draft/composer callback.

- [ ] **Step 2: Run the test to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php --filter 'Preparation|Draft|Compose'`

Expected: FAIL because the coordinator has no preparation dependency or pre-draft call.

- [ ] **Step 3: Add the optional constructor seam and invoke it before `draftCreator`**

Add the orchestrator dependency at the end of the existing constructor parameter list to preserve current call sites. Immediately before the current `draftCreator` branch, call `prepare()` with the already interpreted input/assets/context. Store a body-free result in Capture diagnostics and the packet/plan/read-back state in existing Capture context. For `REVIEW_REQUIRED` or `BLOCKED`, save the existing Capture with a non-success status and return without invoking draft creation.

- [ ] **Step 4: Pass the locked packet into downstream contexts**

For `PREPARED`, pass `subject_resolution_packet`, `preparation_plan` and the preparation fingerprint into semantic, draft, composition, Media and publication contexts. Downstream code must use this packet and must not perform a second prose-based primary-subject resolution.

- [ ] **Step 5: Run the coordinator-order tests to verify green**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php --filter 'Preparation|Draft|Compose'`

Expected: PASS with prepared ordering and no draft side effect for review/block results.

- [ ] **Step 6: Commit the integration seam**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
git commit -m "feat: gate article creation on content preparation"
```

### Task D: Enforce Article-owned relation, composition and Media placement gates

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:**
- Consumes: prepared packet and plan from Task C.
- Produces: fail-closed downstream contexts with explicit prepared status and no Article-owned relation before preparation.

- [ ] **Step 1: Write failing gate tests**

Assert that `REVIEW_REQUIRED` and `BLOCKED` do not call the Article draft creator, Article composer, Article-owned relation proposal callback or MediaUsage placement callback. Assert that a governed semantic-owner relation returned during enrichment is retained in preparation diagnostics and is not mistaken for an Article-owned `wp_post → about → subject` relation.

- [ ] **Step 2: Run the gate tests to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php --filter 'Relation|Media|Compose|Preparation'`

Expected: FAIL against the current draft-first path or missing gate assertions.

- [ ] **Step 3: Add explicit prepared checks at downstream entry points**

Before Article-owned relation planning, composition, Article MediaUsage reconciliation or publication preparation, require the persisted preparation result to be `PREPARED` and the packet to be valid. Preserve existing non-success diagnostics and return the existing Capture outcome rather than throwing a broad success-swallowing catch.

- [ ] **Step 4: Keep semantic-owner enrichment relations governed**

Do not block the existing governed callback's semantic-owner relation plan. Require its proposal/apply/read-back diagnostics to remain attached to the preparation result and require final re-resolution before setting `PREPARED`.

- [ ] **Step 5: Run the gate tests to verify green**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php --filter 'Relation|Media|Compose|Preparation'`

Expected: PASS with no pre-prepared Article-owned side effect and with governed semantic-owner enrichment retained.

- [ ] **Step 6: Commit the gate changes**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
git commit -m "feat: enforce prepared downstream content gates"
```

### Task E: Persist resumable preparation state in Capture

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Inspect/modify only if required by existing serialization: `public/wp-content/plugins/nhk-core/src/Domain/Capture/CaptureRecord.php`

**Interfaces:**
- Consumes: same Capture idempotency key, request fingerprint, preparation plan, governed read-back and packet.
- Produces: durable `context['content_preparation']` and/or `diagnostics['content_preparation']` state that can reconstruct the result without a second semantic write.

- [ ] **Step 1: Write the failing same-Capture resume test**

Create a Capture repository fake that persists `CaptureRecord` instances. Make the governed enrichment callback return one canonical semantic-owner read-back, then make the first run interrupt immediately before draft creation. Retry with the same idempotency key and unchanged payload. Assert the second run reuses the stored preparation/read-back/packet, calls enrichment once, creates one draft and keeps one Capture ID.

- [ ] **Step 2: Run the resume test to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php --filter 'resume|idempotency|same Capture'`

Expected: FAIL because preparation state is not yet durably resumed before draft creation.

- [ ] **Step 3: Persist only bounded preparation state**

Save phase, status, preparation fingerprint, candidate/gap/plan diagnostics, governed enrichment read-back summaries, and `SubjectResolutionPacket::toArray()` under the existing Capture context/diagnostics. Never persist raw Article body as semantic state, and never create a second Capture or owner table.

- [ ] **Step 4: Add idempotent resume lookup**

On retry, first validate the existing Capture request fingerprint/idempotency binding. If durable preparation is already `PREPARED` with a valid packet and matching fingerprint, reconstruct `ContentPreparationResult` from the stored packet and continue to draft/composition without invoking enrichment again. If preparation is incomplete, resume the exact missing phase and preserve prior canonical read-back.

- [ ] **Step 5: Verify duplicate prevention**

Assert the retry produces the same Capture ID, one governed enrichment call, one Article draft callback, one Media adoption/placement sequence and no second semantic-owner mutation. Assert changed payload under the same idempotency key remains the existing conflict outcome.

- [ ] **Step 6: Run the resume tests to verify green**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php --filter 'resume|idempotency|same Capture'`

Expected: PASS with one durable Capture and no duplicate owners.

- [ ] **Step 7: Commit Capture resume behavior**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/src/Domain/Capture/CaptureRecord.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
git commit -m "feat: resume content preparation from capture state"
```

### Task F: Verify final packet handoff and no second resolution

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:**
- Consumes: durable `ContentPreparationResult` and final `SubjectResolutionPacket`.
- Produces: downstream contexts whose primary subject is packet-bound and whose relation plans distinguish semantic-owner enrichment from Article-owned relations.

- [ ] **Step 1: Write the failing packet-identity test**

Use a resolver double that would return a different prose-derived subject if called after preparation. Assert Article composition, Media placement and publication contexts all receive the final packet subject ID/revision and that no downstream resolver call changes it.

- [ ] **Step 2: Run the packet test to verify failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php --filter 'packet|re.resolve|handoff'`

Expected: FAIL if any downstream callback re-resolves the primary subject.

- [ ] **Step 3: Remove downstream primary-subject re-resolution in this seam**

Replace only the new-path lookup with the packet-bound context. Preserve existing read-only diagnostics and legacy reconciliation behavior; do not refactor unrelated resolver uses.

- [ ] **Step 4: Run the packet tests to verify green**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php --filter 'packet|re.resolve|handoff'`

Expected: PASS with one final packet consumed downstream.

- [ ] **Step 5: Commit packet handoff**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
git commit -m "feat: hand off one locked subject packet"
```

### Task G: Complete generic vertical-slice coverage

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:**
- Consumes: completed result/orchestrator/coordinator seam.
- Produces: generic regression coverage for preparation, governance ordering, packet lock, physical-source boundaries and Capture resume.

- [ ] **Step 1: Add the remaining generic behavior tests**

Cover: explicit stable key precedence after UUID absence; ordered subject-hint precedence; ambiguous candidate review; unsupported registry value block; insufficient evidence review; governed semantic-owner relation read-back; Article-owned `wp_post → about → subject` blocked before preparation; physical Video/Media source retained without semantic auto-promotion; prepared Article path composes exactly once.

- [ ] **Step 2: Run the complete focused vertical slice**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

Expected: PASS with no Article 636 fixture or Odo-specific production code.

- [ ] **Step 3: Commit generic coverage**

```bash
git add public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
git commit -m "test: cover resumable content preparation slice"
```

### Task H: Full verification and checkpoint evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Do not modify: Article 636 data or deployment configuration.

- [ ] **Step 1: Read the execution state before checkpoint verification**

Run: `sed -n '1,260p' docs/architecture/V3_EXECUTION_STATE.md`

Record the current checkpoint and preserve unrelated state entries.

- [ ] **Step 2: Run the complete plugin test suite**

Run: `composer test`

Expected: exit 0 with all current Unit tests passing; report any pre-existing failure by name rather than masking it.

- [ ] **Step 3: Run PHP lint**

Run: `composer lint`

Expected: exit 0 for every plugin PHP file.

- [ ] **Step 4: Run diff and secret checks**

Run: `git diff --check` and inspect changed files for credentials, tokens, private keys, dumps and Article 636 references.

Expected: no whitespace errors, secrets or forbidden Article 636 changes.

- [ ] **Step 5: Review the final diff and contract boundaries**

Run: `git status --short`, `git diff --stat`, and inspect the changed Capture/orchestrator/tests. Confirm there is no migration, direct Graph writer, new semantic owner, staging mutation or deploy operation.

- [ ] **Step 6: Update execution state with local evidence only**

Add a dated checkpoint entry recording the new files, test/lint results, same-Capture resume proof and explicit limitation that staging/production and Article 636 were not touched. Do not claim live runtime acceptance.

- [ ] **Step 7: Run final verification after the execution-state edit**

Run: `composer test && composer lint && git diff --check`

Expected: exit 0. Only after reading this fresh output may the implementation be described as locally verified.

- [ ] **Step 8: Commit the checkpoint evidence**

```bash
git add docs/architecture/V3_EXECUTION_STATE.md
git commit -m "docs: record content preparation slice evidence"
```
