# Generic Video Creation, Enrichment, Optimization and Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the generic Capture → Video owner → Knowledge enrichment → editorial repair → canonical optimization → publication/read-back lifecycle with safe recovery from uncertain transport outcomes.

**Architecture:** Extend the existing Capture, Video, Governance, MCP transport, shared enrichment, SEO and completion boundaries. Add typed outcome/reconciliation behavior at the transport/application seam, preserve the immutable subject packet and normalized external identity, and make canonical owner read-back the handoff between creation, optimization and publication. Do not add a parallel identity or persistence system.

**Tech Stack:** PHP 8.x, PHPUnit 11, WordPress plugin runtime, existing NHK Core repositories/services, guarded `nhk_v3_test` integration harness, MCP documentation snapshot generator.

**Spec:** `docs/superpowers/specs/2026-09-23-generic-video-creation-enrichment-optimization-design.md`

## Global Constraints

- `nhk.capture.ingest` remains the only normal new-submission boundary.
- `OUTCOME_UNKNOWN` always reconciles with the original identity; it never creates a second Capture or idempotency key.
- No new entity type, field, predicate, relation type, identity system or parallel persistence owner is introduced.
- Sparse or unavailable optional Knowledge does not block a valid external Video owner.
- Generated prose, summaries, transcripts and user hints never become Knowledge automatically.
- Public composition consumes a reader-safe projection and must reject internal identifiers, diagnostics and workflow vocabulary.
- Owner creation, optimization and publication remain distinct states with canonical read-back between them.
- No Odo, Jacquemart, public-clock, YouTube ID, UUID, title or leaked phrase is used in production branching.
- No legacy Article-body migration, V2/production mutation, direct SQL writer, server hotfix or Governance bypass.
- Exact staging acceptance requires fresh documentation bootstrap, deployed build identity, duplicate audit and server-issued signed scope; otherwise stop fail-closed.

## Review Focus

- Empty or malformed connector response after persistence must produce `OUTCOME_UNKNOWN` and reconcile by the original request identity, not replay with a new key. Test in Task 1.
- A normalized YouTube URL variant must reuse one external Video owner even when the title or query parameters differ. Test in Task 2.
- An authoritative subject must remain unchanged through enrichment, repair and optimization even when a secondary entity appears in title text. Test in Task 3.
- A repair must revalidate the repaired package and regenerate stale SEO/public projections rather than re-checking the pre-repair package. Test in Task 4.
- An owner row with no verified public route/read-back must not be reported as published, and an optional enrichment gap must not block owner admission. Test in Task 5.

---

### Task 1: Add typed mutation outcomes and idempotent uncertain-response reconciliation

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Capture/MutationOutcome.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Capture/MutationOutcomeClassifier.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Capture/UncertainMutationReconciler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MutationOutcomeTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ChatGptMcpGatewayTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/CaptureCanonicalReadbackIntegrationTest.php`

**Interfaces:**
- `MutationOutcome::successWithReadback(array $payload): self`, `MutationOutcome::failedConfirmed(string $code, array $diagnostics = []): self` and `MutationOutcome::unknown(string $reason, array $identity = []): self` produce immutable typed outcomes.
- `MutationOutcomeClassifier::classify(mixed $response, array $dispatchContext = []): MutationOutcome` treats an empty object, malformed payload, timeout, connection reset and serializer failure after dispatch as `OUTCOME_UNKNOWN`.
- `UncertainMutationReconciler::reconcile(array $identity, callable $captureLookup, callable $videoLookup, callable $ownerLookup): array` returns `REUSE_AND_RESUME`, `REPLAY_SAME_IDENTITY`, `CONFLICT_FAIL_CLOSED` or `NO_CANONICAL_OUTCOME` with capture/owner evidence.

- [ ] **Step 1: Write the failing tests.** Add assertions for the three outcome values, an empty object and malformed response classified as unknown, and a persisted Capture/Video found during reconciliation. Assert the original idempotency key is returned unchanged and no create callback is called.

```php
public function test_empty_success_payload_is_unknown_until_canonical_reconciliation(): void
{
    $outcome = (new MutationOutcomeClassifier())->classify((object) [], ['dispatched' => true, 'idempotency_key' => 'video-1']);
    self::assertSame('OUTCOME_UNKNOWN', $outcome->status());
    self::assertSame('video-1', $outcome->identity()['idempotency_key']);
}
```

- [ ] **Step 2: Run the focused tests and verify RED.** Run:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'MutationOutcomeTest|ChatGptMcpGatewayTest' --no-progress`.
  Expected: failure because the outcome types/reconciliation contract is absent or the empty response is not typed.
- [ ] **Step 3: Implement the minimal typed outcome boundary.** Preserve existing MCP payload fields and add only canonical identity, revision, stage/status, readiness/failure and resume hints where available. Keep transport errors distinct from canonical lookup results. Do not convert an unknown result to a failed result.
- [ ] **Step 4: Wire Capture retry/reconcile.** When the canonical mutation callback returns an uncertain result, lookup the persisted Capture and Video using the original fingerprint/key and normalized owner identity, then resume only the missing phase. If no authoritative record exists, return a replay instruction retaining the same key; if owners conflict, return a typed fail-closed diagnostic.
- [ ] **Step 5: Run the focused tests and verify GREEN.** Re-run the command from Step 2, then run:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'EditorialCaptureConvergenceE2ETest|CaptureCurrentOutcomeReducerTest|McpTransportBoundaryTest' --no-progress`.
- [ ] **Step 6: Lint and commit the task.** Run `php -l` on every changed PHP file and `git diff --check`, then commit:
  `git add public/wp-content/plugins/nhk-core/src/Application/Capture public/wp-content/plugins/nhk-core/src/Application/Mcp public/wp-content/plugins/nhk-core/tests/Unit public/wp-content/plugins/nhk-core/tests/Integration && git commit -m "fix: classify uncertain video mutations"`.

### Task 2: Close generic external Video identity and owner-reuse behavior

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/YouTubeUrlNormalizer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoProvenancePlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoExternalIdentityTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/CaptureVideoRecoveryIntegrationTest.php`

**Interfaces:**
- Preserve `YouTubeUrlNormalizer::normalize(string $url): YouTubeVideoIdentity` as the single URL-normalization API.
- Add `VideoExternalIdentity::fingerprint(string $platform, string $externalId): string` only if the existing domain has no equivalent; otherwise reuse the current `platform + external_video_id` lookup key.
- `VideoService::ingestUrl()` remains idempotent for equivalent normalized identities and never creates an alternate owner for a URL spelling change.

- [ ] **Step 1: Write the failing parameterized identity tests.** Cover watch, `youtu.be`, Shorts and query-parameter URL forms; assert one normalized external ID/canonical URL and one owner on replay. Add a second unrelated external ID to prove there is no fixture-specific branch.
- [ ] **Step 2: Run RED.** Run:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'VideoExternalIdentityTest|VideoSemanticCoreTest' --no-progress`.
- [ ] **Step 3: Implement only generic normalization/reuse fixes.** Preserve malformed/non-YouTube rejection, external identity scope and canonical URL projection. Use the existing repository lookup before generating a new canonical ID. Do not infer subject identity from title text.
- [ ] **Step 4: Add recovery integration assertions.** Verify existing Video reuse, Capture-created/Video-absent continuation, duplicate relation avoidance and same-key replay after an uncertain response.
- [ ] **Step 5: Run GREEN and guarded integration.** Run the focused unit command, then, when exact test environment variables are available:
  `NHK_WP_TEST_PATH=public NHK_WP_TEST_DB=nhk_v3_test vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Integration' --filter 'CaptureVideoRecoveryIntegrationTest' --no-progress`.
  If the environment is unavailable, record the explicit skip/blocker and do not substitute `nhk_v3`.
- [ ] **Step 6: Lint, diff-check and commit.** Commit as `fix: make video external identity replay-safe`.

### Task 3: Preserve authoritative subject and Knowledge direction through Video enrichment

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoKnowledgeEnrichmentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialEnrichmentTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialAdapterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoKnowledgeSafetyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSubjectAuthorityOptimizationTest.php`

**Interfaces:**
- `VideoEditorialAdapter::prepare(array $context): array` consumes the locked subject packet and returns retrieval, public-safe pack, editorial draft, SEO plan, quality and transient enrichment diagnostics.
- `VideoKnowledgeEnrichmentPlanner` may return candidates/proposal readiness but has no Knowledge writer.
- `SharedEnrichmentBoundary::enrich(array $context): array` remains transient and must preserve sparse/optional status separately from content readiness.

- [ ] **Step 1: Write failing tests.** Assert rich Knowledge is selected by canonical subject, sparse Knowledge still yields a valid owner-admission result, a title alias does not replace the locked subject, generated prose produces zero Knowledge writes, and a secondary entity remains contextual.
- [ ] **Step 2: Run RED.** Run:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'VideoEditorialEnrichmentTest|VideoEditorialAdapterTest|VideoKnowledgeSafetyTest|VideoSubjectAuthorityOptimizationTest' --no-progress`.
- [ ] **Step 3: Implement the smallest seam fix.** Pass the immutable subject packet into retrieval, statement decisions and optimization context. Remove any fallback that re-resolves the primary subject from title/body after packet hydration. Mark optional enrichment unavailable without converting it to identity/factual failure.
- [ ] **Step 4: Assert Knowledge safety.** Keep generated title/summary/body/SEO transient until the governed Video update; do not call a Knowledge repository from composition or repair. Preserve provenance and claim revisions in diagnostics only.
- [ ] **Step 5: Run GREEN plus existing subject/convergence tests.** Run the focused command and:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'VideoSubjectResolutionDecisionTest|CaptureVideoProvenancePlannerTest|GovernedCaptureContinuationServiceTest|SharedEnrichmentBoundaryTest' --no-progress`.
- [ ] **Step 6: Lint, diff-check and commit.** Commit as `fix: keep video subject authoritative through enrichment`.

### Task 4: Make public-copy repair converge and regenerate dependent projections

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialDecisionPipeline.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialRepairPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Compliance/PublicEditorialCopyGuard.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialDecisionPipelineTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEditorialCopyGuardTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoPublicProjectionConvergenceTest.php`

**Interfaces:**
- `VideoEditorialDecisionPipeline::run(array $package, array $decisionContext, callable $compose, callable $critique): array` continues to return final package, findings, trace and bounded rounds, but every critique after repair sees the mutated package.
- `VideoEditorialRepairPlanner::plan(array $findings, array $package, int $round): array` and `apply(array $package, array $operations): array` remain the bounded repair boundary.
- `PublicEditorialCopyGuard::findings(array $package): array` is applied to title, summary, body, SEO description and other public fields before owner mutation and after every repair.

- [ ] **Step 1: Add the failing regression tests.** Reproduce an internal-token leak in a package, assert the first quality pass returns a repairable finding, the repair changes the package, the second pass inspects the changed package, and the final package contains no internal token. Add a stale-SEO test proving the SEO description is rebuilt from the repaired package.
- [ ] **Step 2: Run RED.** Run:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'VideoEditorialDecisionPipelineTest|PublicEditorialCopyGuardTest|VideoPublicProjectionConvergenceTest' --no-progress`.
- [ ] **Step 3: Implement bounded convergence.** Ensure repaired fields replace the package before the next compose/critique call, dependent SEO/Open Graph/VideoObject projections are regenerated from the repaired reader-safe package, and the existing maximum round count remains enforced. Keep structural leaks and unsupported factual claims distinct from repairable presentation defects.
- [ ] **Step 4: Run GREEN and historical regression.** Run the focused command and the existing public-copy/convergence filter:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'PublicEditorialCopyGuardTest|VideoEditorialDecisionPipelineTest|EditorialCaptureConvergenceE2ETest' --no-progress`.
- [ ] **Step 5: Lint, diff-check and commit.** Commit as `fix: converge video public editorial repair`.

### Task 5: Separate owner admission, optimization, SEO and publication read-back

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoCompletenessReconciliationService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoOwnerOptimizationLifecycleTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoCompletenessPersistenceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoPublicationVerifierTest.php`

**Interfaces:**
- Add or preserve a canonical-owner optimization boundary that accepts `canonical_id`, `expected_revision`, `editorial_package`, `seo_plan`, `subject_packet` and `idempotency_key`, and returns a controlled update receipt with canonical read-back.
- `VideoCompletenessReconciliationService::reconcile(string $videoId): Video` remains the read-back repair boundary; it must not create a new owner.
- Completion remains false until required owner, SEO/public identity and publication/public route checks are verified.

- [ ] **Step 1: Write failing lifecycle tests.** Assert owner admission succeeds with optional enrichment absent; optimization is rejected without canonical owner read-back; optimization updates the existing owner with CAS/idempotency; a stale revision fails without mutation; and publish=true is incomplete without public route/read-back.
- [ ] **Step 2: Run RED.** Run:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'VideoOwnerOptimizationLifecycleTest|CompletionConvergenceTest|VideoCompletenessPersistenceTest|CaptureVideoPublicationVerifierTest' --no-progress`.
- [ ] **Step 3: Implement owner/read-back handoff.** Persist the minimum valid external Video owner through the existing governed path, read it back, then build optimization input from the canonical owner. Do not optimize a transient imagined owner. Preserve category lookup through the existing registry; do not hardcode category `06`.
- [ ] **Step 4: Implement controlled optimization/read-back comparison.** Apply only supported facts and editorial fields through the existing Governance/update path. Compare identity, subject, title, summary/body, SEO, category, relation set, readiness and revision against canonical read-back. Return typed mismatch diagnostics instead of claiming success.
- [ ] **Step 5: Enforce publication state.** Require canonical owner read-back, public identity and publication state, then invoke the existing public route/projection verifier. Map missing route/read-back to publication-only incompleteness, not owner failure.
- [ ] **Step 6: Run GREEN and focused completion suite.** Run the command from Step 2 plus:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'VideoUrlPolicyTest|VideoSeoProjectionTest|SeoRuntimeReadbackTest|PublicSeoProjectionTest|GovernedCaptureContinuationServiceTest' --no-progress`.
- [ ] **Step 7: Lint, diff-check and commit.** Commit as `fix: separate video owner optimization and publication`.

### Task 6: Update ACTIVE contracts and execution evidence from behavior

**Files:**
- Modify: `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`
- Modify: `docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md`
- Modify: `docs/mcp/MCP_V3_VIDEO_WORKFLOW.md`
- Modify: `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoDocumentationContractTest.php`

- [ ] **Step 1: Write failing documentation assertions.** Assert the ACTIVE contracts contain the nine laws from the spec: transport outcome differs from canonical outcome; uncertain mutation reconciles with original identity; owner/read-back precedes optimization; public composition is reader-safe; optional enrichment is not a universal owner gate; repair revalidates the repaired package; canonical read-back is mandatory; public read-back is mandatory for publication; generated prose never becomes Knowledge automatically.
- [ ] **Step 2: Run RED.** Run:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --filter 'VideoDocumentationContractTest' --no-progress`.
- [ ] **Step 3: Update only owning ACTIVE contracts.** Reconcile conflicting wording in the Video/MCP/SEO contracts without creating a second Constitution or weakening existing registry, scope, Evidence, public identity or Governance laws.
- [ ] **Step 4: Record evidence in execution state.** Add a dated checkpoint with actual test counts, infrastructure gates, changed files, no-live-mutation status and unresolved deployment blockers. Do not record staging/live success without read-back evidence.
- [ ] **Step 5: Run GREEN, lint and diff-check.** Run the documentation test, PHP lint for changed PHP files and `git diff --check`.
- [ ] **Step 6: Commit documentation.** Commit as `docs: record generic video lifecycle contract`.

### Task 7: Full local verification, guarded integration and release audit

**Files:**
- Modify only files already listed by Tasks 1–6 unless a test exposes a directly related defect.
- Test: existing full Unit, Contract and Integration/P4 suites.

- [ ] **Step 1: Run all focused groups fresh.** Run Video, Capture, external identity, enrichment, quality/repair, SEO, relation, Knowledge safety, subject authority and CP1–CP6 filters. Save command output and counts in the checkpoint evidence.
- [ ] **Step 2: Run full Unit and Contract suites.** Run:
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Unit' --no-progress` and
  `vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'NHK Contract' --no-progress`.
- [ ] **Step 3: Run guarded Integration/P4 only with exact targets.** Require `NHK_WP_TEST_PATH=public` and `NHK_WP_TEST_DB=nhk_v3_test`; never reset, drop, truncate or migrate `nhk_v3`. Run the repository's integration/P4 commands and report skipped infrastructure separately from product failures.
- [ ] **Step 4: Run repository quality checks.** Run `composer test`, `composer lint`, the relevant migration/schema checks if any schema changed, `git diff --check`, and the project secret-pattern review. Do not hide warnings or failures.
- [ ] **Step 5: Audit production source for forbidden fixtures.** Run an explicit changed-source search for supplied YouTube IDs, UUIDs, titles, Odo/Jacquemart/public-clock names and known leaked phrases; expected result is zero production matches.
- [ ] **Step 6: Review the full diff and commit only task files.** Confirm unrelated user changes are preserved and each claim in `V3_EXECUTION_STATE.md` is backed by fresh output. Create the focused implementation commit only after verification.

### Task 8: Deployment, documentation manifest regeneration and bounded runtime acceptance

**Files:**
- No server files are edited directly.
- Use the established repository deployment tools and generated MCP snapshot process.
- Evidence: final report in the task response; update `docs/architecture/V3_EXECUTION_STATE.md` with verified runtime tuple.

- [ ] **Step 1: Verify local commit and remote state.** Record `LOCAL_COMMIT`, configured remote branch and whether the remote contains the commit. Do not force-push.
- [ ] **Step 2: Push through the normal project workflow.** Push the canonical branch only if credentials and repository policy permit it. If blocked, record the exact external error and stop deployment claims.
- [ ] **Step 3: Deploy through the established server pull/deployment mechanism.** Verify `SERVER_REVISION_BEFORE`; never use SSH file editing, `sed`, manual source copy or a generic WordPress writer.
- [ ] **Step 4: Regenerate MCP documentation.** Run the official local process (`composer generate:mcp-docs` or the repository-approved equivalent), then read back documentation version, manifest hash, source revision and build identity.
- [ ] **Step 5: Bootstrap the target runtime and bind a fresh checkpoint.** Fail closed if documentation/runtime identity differs, the target is unavailable, credentials are missing or exact signed scope is absent.
- [ ] **Step 6: Perform the authorized read-only duplicate audit.** Reconcile the previous uncertain Jacquemart request by original deterministic identity; inspect Capture, Video, normalized external identity, subject and relation before any replay.
- [ ] **Step 7: If and only if a valid signed scope exists, run the canonical acceptance.** Reuse/resume the existing Capture, verify owner read-back, enrich, optimize, final-validate, controlled-apply, read back Video/SEO/relation/public identity, publish only if explicitly requested and permitted, verify public route/read-back, replay once with the same identity, and prove no duplicate Capture/Video/relation/Knowledge.
- [ ] **Step 8: Run the public-clock regression through the same generic path.** Verify copy convergence and final public read-back; do not add fixture-specific code.
- [ ] **Step 9: Record final status using only evidence.** Allowed statuses are `DEPLOYED_AND_RUNTIME_VERIFIED`, `DEPLOYED_RUNTIME_ACCEPTANCE_PENDING`, `BLOCKED_BY_TEST_INFRASTRUCTURE`, `BLOCKED_BY_EXTERNAL_DEPLOYMENT_POLICY` or `NOT_READY`.

## Final report checklist

The final report must use the requested 48 sections, including systemic root
cause, all enforced laws, transport/canonical outcome, identity/recovery,
subject/enrichment/public-copy/repair/optimization/SEO/relation/publication
read-back, Knowledge safety, replay proof, future-video guarantee, both
regressions, changed files/contracts/tests, local/remote/server revisions,
manifest/build/runtime read-back and one allowed final status. It must not
claim `DEPLOYED_AND_RUNTIME_VERIFIED` unless the canonical owner, optimized
package and public read-back were actually verified.

