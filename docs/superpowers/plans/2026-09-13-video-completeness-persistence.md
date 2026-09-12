# Video Completeness Persistence Bug Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist corrected Video completeness after canonical Graph/Evidence attachment and repair already-existing stale Videos through the supported Capture resume path.

**Architecture:** Preserve `VideoCompletenessPolicy` as a pure evaluator. Add one application reconciliation boundary that reads active canonical `about` edges, matches persisted Evidence references, evaluates current completeness, and persists only through `VideoService`/`VideoRepository`. Use that boundary from governed apply and explicit existing-Capture Video resume so the canonical owner—not a projection or serializer—holds the repaired value.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress `wpdb` repository boundary, existing Governance/Graph/Knowledge contracts.

**Spec:** `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`, `docs/constitution/NHK_V3_CONSTITUTION.md` §13.2, §19 and §20.1.

## Global Constraints

- WordPress native `wp_posts` remains editorial truth; Video remains the canonical external-reference owner.
- Graph is the sole relation system; only active registered `about` edges qualify.
- Evidence references must resolve to active canonical Evidence with active Claim and Source dependencies.
- All durable semantic mutation remains Proposal → Approval → Eligibility → Controlled Apply → repository → audit/read-back.
- The fix is generic, idempotent, revision-bound, repository-bound, and must not mutate staging/production runtime data.
- No hard-coded Video, Odo, Jacquemart, YouTube ID, new predicate, direct SQL, frontend masking, or serializer-only suppression.

### Task 1: Add failing persistence-boundary regressions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Integration/GovernedSemanticIngestIntegrationTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoRelationLifecycleTest.php`

**Interfaces:**
- The tests must exercise `WpdbVideoRepository` through a fresh repository instance and assert stored metadata after the governed apply/resume boundary.
- The negative cases must preserve `NO_SEMANTIC_ATTACHMENT` for missing, inactive, or Evidence-invalid relations.

- [ ] **Step 1: Add the fresh-hydration governed-apply test.** Seed a stale completeness package, apply a valid Video/about/Evidence proposal, destroy the first service/repository objects, construct fresh `WpdbVideoRepository` and `VideoService` instances, then assert the reloaded metadata has no `NO_SEMANTIC_ATTACHMENT`.
- [ ] **Step 2: Add the existing-stale Video resume test.** Persist one Video and one active Graph about edge with valid Evidence, leave the Video completeness blocker stale, invoke the supported continuation/reconciliation path, reload through a fresh repository instance, and assert the same Video, one edge, one Evidence, and repaired blockers.
- [ ] **Step 3: Add negative and replay assertions.** Cover no relation, retired relation, invalid Evidence, generic non-Odo target data, and repeated reconciliation/apply without duplicate Video/edge/Evidence.
- [ ] **Step 4: Run the new tests before implementation.** Run the focused Unit test and the guarded Integration test; the Unit regression must fail specifically because the resume path returns without persisting repaired completeness. If the WordPress DB is unavailable, record the Integration test as skipped/unavailable rather than treating it as a pass.

### Task 2: Implement canonical completeness reconciliation

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoCompletenessReconciliationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/AuthorityProposalExecutor.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php` only if constructor wiring requires it

**Interfaces:**
- `VideoCompletenessReconciliationService::__construct(VideoRepository, GraphService, CanonicalDependencyValidator, ?VideoCompletenessPolicy = null)`
- `VideoCompletenessReconciliationService::reconcile(string $videoId): Video`
- Reconciliation reads current active `about` edges, matches their target type/UUID to persisted attachment records, validates every referenced Evidence through `CanonicalDependencyValidator`, evaluates with `evaluateAfterCanonicalReadBack`, and persists a changed package with optimistic revision through `VideoService`/the injected repository.
- The continuation invokes the same boundary for explicit Video resume even when editorial input is unchanged; failures remain typed/non-success.

- [ ] **Step 1: Implement the smallest canonical attachment collector.** Return only active Graph `about` edges whose persisted attachment has non-empty exact Evidence refs that all pass canonical active Evidence/Claim/Source validation. Return an empty attachment list for missing/retired/mismatched/invalid dependencies.
- [ ] **Step 2: Persist the evaluated package through the Video owner.** Replace `semantic_attachments` and `completeness` as complete keys in the Video metadata, preserve unrelated metadata, and update only when the package changes using the current revision.
- [ ] **Step 3: Reuse the boundary after governed Graph attachment creation.** Replace the executor’s duplicate inline persistence logic with the reconciliation service or delegate to the same service after Graph/Evidence read-back; retain the existing completeness assertion and activation order.
- [ ] **Step 4: Wire explicit Capture Video resume.** Ensure `REUSE_EDITORIAL` still runs canonical completeness reconciliation and returns a fresh canonical read-back/revision while retaining idempotent editorial semantics. Do not create a new proposal or owner for unchanged input.
- [ ] **Step 5: Keep relation/Evidence ownership unchanged.** Do not write Graph or Evidence from the reconciliation service; it only reads them and updates the owning Video metadata through the existing governed apply context.

### Task 3: Verify, document checkpoint, commit, and push

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with dated local verification evidence only.

- [ ] **Step 1: Run focused Unit tests.** Include persistence/hydration, completeness policy, relation lifecycle, editorial enrichment/resume, governed semantic ingest, Capture provenance, and replay tests.
- [ ] **Step 2: Run the full NHK Unit suite, Contract suite, and available WordPress Integration suite.** Report unavailable database/runtime honestly as SKIPPED/UNAVAILABLE.
- [ ] **Step 3: Run PHP lint, `git diff --check`, and a secret review.** Confirm no source files outside the local repository and no credentials/private keys changed.
- [ ] **Step 4: Re-read `V3_EXECUTION_STATE.md` and update it with the verified checkpoint.** Do not claim parity; use the current status/contract wording.
- [ ] **Step 5: Create one focused commit and push `origin/main`.** Verify the pushed commit locally; do not deploy or modify the server.
