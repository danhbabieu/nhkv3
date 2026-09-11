# ROOT Repair: Video Governance Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make approved Video proposals fail closed at eligibility with exact dependency reasons, reconcile stale/malformed backlog proposals through governed replacement proposals, and make bulk Governance Queue processing dependency-aware with durable domain outcomes.

**Architecture:** Extend the existing Governance eligibility boundary with a Video-specific evaluator that reads canonical Video, Authority subject, Source, Claim and Evidence owners; keep Controlled Apply as a second validator and preserve coded domain exceptions. Extract the existing Capture provenance planning vocabulary into a reusable Video reconciliation service that creates only missing governed dependencies, never edits an approved proposal in place, deduplicates by platform/external ID, and routes replacement proposals through the existing lifecycle. Queue bulk actions call one reconciler per item and continue after item failures, returning Vietnamese-safe labels with exact codes.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress plugin runtime, existing Governance/Authority/Knowledge/Graph/Video repositories, Composer.

**Spec:** User-provided LIVE ROOT REPAIR requirements in this conversation; governing sources are `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`, `docs/mcp/MCP_V3_VIDEO_WORKFLOW.md`, `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`, and `docs/architecture/V3_EXECUTION_STATE.md`.

## Global Constraints

- Preserve canonical UUID/stable-key, optimistic revision, typed relation, provenance, readiness, idempotency, public identity and fail-closed invariants.
- `wp_posts` remains the sole editorial truth; no Article/Capture placeholder is created for backlog repair.
- New and replacement semantic mutations remain Proposal → submit → approval → eligibility → Controlled Apply → repository → audit → read-back.
- Evidence references are only exact `{'evidence_id': canonical UUID}` objects; `USER_HINT` is not Evidence.
- Existing approved payloads/fingerprints are immutable; replacements use current canonical revisions/fingerprints and never copy old approvals.
- Dedupe Video by `platform + external_video_id`; no V2, staging, production, DEMO data mutation during local tests.
- Development DB `nhk_v3` is UP/read-only-safe only; destructive integration operations are permitted only on guarded `nhk_v3_test`.
- Every checkpoint reads and updates `docs/architecture/V3_EXECUTION_STATE.md`; no claim of deployment or live success without fresh read-back evidence.

### Task 1: Add RED tests for Video eligibility and reason-preserving apply

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ProposalEligibilityServiceTest.php` (create if absent)
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueActionServiceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ControlledApplyServiceTest.php` (use existing focused test file if present)

**Interfaces:**
- Consumes: existing `Proposal`, `ProposalRepository`, `EligibilityReader`, `GovernanceActionPort`, and `EligibilityResult` seams.
- Produces: failing executable examples for empty/malformed Video attachments, stale subject packet, legacy `USER_HINT`, exact dependency codes, and Queue exception-code preservation.

- [ ] **Step 1: Write the failing tests.** Add tests proving `video + ingest` with empty `semantic_attachments` returns `NO_SEMANTIC_ATTACHMENT`; legacy evidence shape returns `CANONICAL_EVIDENCE_REQUIRED`; a dependency validator exception is surfaced as its code; and a Queue apply exception carrying a domain code is not mapped to `OPERATION_FAILED`.
- [ ] **Step 2: Run only the new tests and verify RED.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ProposalEligibilityServiceTest|GovernanceQueueActionServiceTest|ControlledApplyServiceTest'`; confirm failures are caused by missing behavior, not malformed tests.
- [ ] **Step 3: Record the RED checkpoint.** Do not modify production code until the expected failures are observed.

### Task 2: Implement Video eligibility as the pre-apply completeness boundary

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Governance/VideoProposalEligibilityEvaluator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ProposalEligibilityService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Governance/EligibilityReader.php` only if the evaluator requires a minimal new read method
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ProposalEligibilityServiceTest.php`

**Interfaces:**
- Consumes: `Proposal`, `VideoRepository`, `AuthorityRepository`, `KnowledgeRepository`, `SourceRepository`, `EvidenceRepository`, `CanonicalDependencyValidator`, `PredicateRegistry`/existing endpoint policy, and current resolver data.
- Produces: `evaluate(Proposal): list<string>` or equivalent fail-closed result used by `ProposalEligibilityService::check()` before returning ready.

- [ ] **Step 1: Implement the smallest evaluator contract.** Validate only `entity_type=video`, `operation=ingest`; require at least one attachment, exact `evidence_id` UUID references, active canonical Evidence→Claim→Source read-back, active exact target endpoint, exact locked subject scope, and duplicate external reference reconciliation. Map each failure to the existing/domain codes `NO_SEMANTIC_ATTACHMENT`, `EVIDENCE_REQUIRED`, `CANONICAL_EVIDENCE_REQUIRED`, `SOURCE_UNAVAILABLE`, `SUBJECT_UNRESOLVED`, `SUBJECT_SCOPE_MISMATCH`, or `DUPLICATE_CANONICAL_VIDEO` as applicable.
- [ ] **Step 2: Wire it into `ProposalEligibilityService`.** Run Video evaluation after approval binding/revision checks and before generic dependency closure; combine unique reasons so `ready` is false whenever any required Video dependency is missing.
- [ ] **Step 3: Run the focused tests and verify GREEN.** Re-run the Task 1 filter and confirm exact reasons and no executor call for blocked proposals.
- [ ] **Step 4: Add/adjust integration coverage.** Use guarded `nhk_v3_test` to prove canonical read-back and that the evaluator never treats a legacy packet as eligible.

### Task 3: Preserve domain exception codes through Controlled Apply and Queue

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Governance/Exception/DomainOperationException.php` if no existing coded application exception can carry a public code
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueRenderer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueActionServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueRendererTest.php`

**Interfaces:**
- Consumes: existing executor exceptions and `EligibilityResult`.
- Produces: `reason` equal to the domain/application code, optional bounded `message`, and outcome values suitable for `BLOCKED`, `FAILED`, and `APPLIED` display.

- [ ] **Step 1: Add the failing exception-mapping assertions.** Cover `VIDEO_COMPLETENESS_BLOCKED:NO_SEMANTIC_ATTACHMENT`, `DependencyValidationException`, `ProposalBindingConflict`, and generic sanitized infrastructure failure separately.
- [ ] **Step 2: Implement code extraction.** Extract a code from known coded exceptions, `getCode()`, or the first structured token in a controlled message; preserve a domain code and only use `OPERATION_FAILED` for genuinely uncoded infrastructure failures.
- [ ] **Step 3: Separate blocked and failed outcomes.** Eligibility blocks return `BLOCKED` with exact reasons; apply exceptions return `FAILED` with exact reasons; one item never aborts `bulk()`.
- [ ] **Step 4: Update Vietnamese labels.** Add exact user-facing labels such as `Thiếu Evidence cho quan hệ Video → Variant` while retaining the raw code in parentheses; keep generic fallback only for uncoded failures.
- [ ] **Step 5: Run focused Queue tests and renderer tests.** Confirm result counts and per-item codes remain stable.

### Task 4: Extract reusable provenance planning and build existing-proposal reconciliation

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoProposalReconciliationService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Governance/ProposalReconciliationService.php` if a Queue seam is needed
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoProvenancePlanner.php` to expose shared normalized planning helpers without changing the current Capture contract
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoProposalReconciliationServiceTest.php`

**Interfaces:**
- Consumes: existing proposal/governance lifecycle, `CaptureVideoProvenancePlanner`, canonical repositories, current `SubjectResolutionService`/resolver, configured `GovernanceAutomationPolicy`, and Controlled Apply/read-back.
- Produces: `reconcile(string $proposalId): array` with one of `APPLIED`, `REBUILT_AND_APPLIED`, `REUSED_CANONICAL`, `SUPERSEDED`, `BLOCKED`, `FAILED`, plus `reason`, replacement ID/result/read-back data.

- [ ] **Step 1: Write RED reconciliation tests.** Cover existing proposal input, missing Source/Claim/Evidence creation, canonical dependency read-back before Video, replacement fingerprints/revisions, old approval not copied, and no Capture/Article creation.
- [ ] **Step 2: Add external-reference search/reuse.** Read the proposal, derive platform/external ID, reuse the existing canonical Video when present, and never mint a second Video identity.
- [ ] **Step 3: Resolve the locked subject with current resolver.** Require exact Variant `852da54d-457a-4397-a16d-52d9452ba766` for the Odo 36/8 title case; reject broadened Brand/Model or unresolved scope.
- [ ] **Step 4: Reuse or create only missing Source/Claim/Evidence dependencies.** Use planner keys, governed proposals and read-back; reject legacy `USER_HINT` evidence shapes and attach only `{'evidence_id': UUID}`.
- [ ] **Step 5: Build a new Video proposal.** Hydrate current target and dependency revisions, calculate fresh content/dependency fingerprints, and create a new idempotency key; never mutate the approved original.
- [ ] **Step 6: Submit, approve by configured policy, re-check eligibility, apply, and read back Video/Graph/Public Identity.** Return distinct `REBUILT_AND_APPLIED`, `REUSED_CANONICAL`, `SUPERSEDED`, or exact `BLOCKED`/`FAILED` results.
- [ ] **Step 7: Run reconciliation unit tests and verify GREEN.** Confirm duplicate external IDs converge to one Video and both malformed legacy examples enter rebuild.

### Task 5: Make bulk Queue apply dependency-aware

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueAdminPage.php` only if request snapshots need a read/reconcile marker
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueRenderer.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueActionServiceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueRendererTest.php`

**Interfaces:**
- Consumes: `VideoProposalReconciliationService` for Video ingest, existing `GovernanceActionPort` for non-Video operations.
- Produces: per-item statuses `APPLIED`, `REBUILT_AND_APPLIED`, `REUSED_CANONICAL`, `SUPERSEDED`, `BLOCKED`, `FAILED`, with no indefinite stale skip.

- [ ] **Step 1: Write RED bulk tests.** Use one stale binding, one missing dependency, one duplicate, one malformed legacy proposal, and one ready proposal; assert all five process independently and aggregate counts are exact.
- [ ] **Step 2: Implement classification order.** `stale binding → rebuild`, `missing dependency → repair`, `duplicate → reuse`, `malformed → blocked`, `ready → apply`; call `read-back` after every successful/reused path and continue on exceptions.
- [ ] **Step 3: Keep legacy lifecycle actions unchanged.** Submit/approve/reject still enforce snapshots and capabilities; only apply of eligible Video proposals enters reconciliation.
- [ ] **Step 4: Render status and exact diagnostics.** Replace “Bỏ qua” for repairable stale proposals with the domain outcome and show raw code plus Vietnamese explanation.
- [ ] **Step 5: Run all focused Queue/reconciliation tests.** Verify no generic collapse and no item stops the batch.

### Task 6: Full verification, checkpoint documentation, commit, push and DEMO procedure

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` only if the checkpoint changes current runtime evidence
- Test: all changed tests and configured suites

- [ ] **Step 1: Run focused tests.** Run the Video eligibility, reconciliation, Queue, Capture planner, Video semantic and Governance tests.
- [ ] **Step 2: Run Unit, Contract and guarded Integration suites.** Use `NHK_WP_TEST_DB=nhk_v3_test NHK_WP_TEST_PATH=public` for integration; do not run destructive commands against `nhk_v3`.
- [ ] **Step 3: Run quality gates.** Run Composer validation, PHP lint for all changed PHP files, `composer lint`/configured equivalent, `git diff --check`, and changed-scope secret review.
- [ ] **Step 4: Update execution state with evidence.** Record code identity, test counts, environment blockers, and that no semantic data changed locally.
- [ ] **Step 5: Commit the exact verified tree.** Inspect diff/status, commit one logical root-repair commit, verify `git show --check` and commit identity.
- [ ] **Step 6: Push and deploy exact commit through the canonical DEMO procedure.** Do not claim publish until deployment receipt and live build/documentation identity read back match the commit.
- [ ] **Step 7: Live reconcile exactly the 16 supplied proposal IDs.** Run Queue Reconciler, record each status/reason, bootstrap docs and read back Video/Graph/Public Identity; stop and report a deployment/credential gate rather than mutating unsafe data if the procedure fails closed.

## Self-review

- Eligibility split-brain is covered by Tasks 1–2.
- Controlled Apply exception collapse and Vietnamese Admin diagnostics are covered by Task 3.
- Capture planner reuse, existing proposal repair, canonical dependency read-back, fresh fingerprints and no copied approval are covered by Task 4.
- Stale binding, missing dependency, duplicate, malformed and ready paths plus batch continuation are covered by Task 5.
- The exact 16-item live run, documentation bootstrap and Video/Graph/Public Identity read-back are covered by Task 6.
- No task authorizes legacy Article migration, fake Capture/Article creation, direct SQL semantic repair, or production/staging/V2 mutation.
