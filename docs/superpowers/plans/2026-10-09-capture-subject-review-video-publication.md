# Capture Subject Review and Video Publication Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Re-evaluate a stale Capture subject-review decision from the persisted canonical subject packet, resume the existing Video child without changing Capture/Video identity, and verify governed public publication end to end.

**Architecture:** Keep Capture as orchestration and keep Authority, Video, Governance and WordPress/public projection as separate owners. The continuation boundary will derive a fresh preparation result from the server-owned resolved subject packet when the prior review is specifically superseded; the retry path then reuses the existing canonical Video and idempotency identity and proceeds only through registered Governance/publication workflows.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress plugin runtime, existing NHK V3 MCP transport and governed Video/Governance services.

**Spec:** User request `CODEX — FIX CAPTURE SUBJECT REVIEW AND COMPLETE VIDEO PUBLICATION`, with active Video/Capture/Governance/public URL contracts.

## Global Constraints

- Preserve the supplied Capture UUID, Video UUID, source revision, request fingerprint and idempotency key.
- Treat the server-owned `subject_resolution_packet` as canonical only after strict packet validation and canonical read-back.
- Do not delete review history; move superseded review evidence to history and clear it only from the current projection.
- Do not use direct SQL, direct WordPress post writing, force transitions, fake URLs, duplicate Video creation or Governance bypass.
- Live staging mutation is allowed only after fresh documentation/build/runtime/schema/read-only duplicate checks pass; otherwise return `AWAITING_DEPLOYMENT` or the exact blocker.

## Review Focus

- A resolved packet with `status=resolved`, exact canonical UUID and `match_reason=uuid_exact` must not produce `PRIMARY_SUBJECT_NOT_RESOLVED`; pinned by the stale-review continuation test.
- Stale review invalidation must preserve immutable failure history and current Capture identity; pinned by the same test.
- Video retry must reuse the existing Video UUID and idempotency key and must not call a generic writer; pinned by retry/idempotency tests.
- Governance must remain the only semantic mutation path and public state must remain gated by canonical/readiness/frontend read-back; pinned by Video workflow tests.
- The public HTTPS response must identify YouTube `5CqjJDgzFcI` and be accessible without authentication; pinned by live read-back evidence.

### Task 1: Add the failing stale-subject review regression

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ContentPreparationOrchestratorTest.php`

**Interfaces:**
- Consumes: existing Capture retry fixture, persisted `SubjectResolutionPacket`, `resume_children=['video']` and Video/Governance test doubles.
- Produces: executable proof that a resolved packet clears only the stale subject-review symptom while preserving Capture/Video/idempotency identity and Governance call boundaries.

- [ ] **Step 1: Write tests for stale review re-evaluation and packet precedence.**
- [ ] **Step 2: Run the focused tests and observe the expected failure: the video-only continuation reuses the old `REVIEW_REQUIRED` preparation result instead of deriving a current resolved result.**

### Task 2: Fix the Capture continuation review boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php` only if the regression proves the preparation boundary itself diverges.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php` only if the regression proves the read/retry projection still diverges.

**Interfaces:**
- Consumes: persisted subject packet, current Capture diagnostics/receipts, original input fingerprint and bounded Video-only resume control.
- Produces: fresh `ContentPreparationResult`/current blocker projection for a superseded subject review; immutable prior review evidence remains available in failure/phase history.

- [ ] **Step 1: Implement the smallest owner-boundary change that re-evaluates stale `PRIMARY_SUBJECT_NOT_RESOLVED` only when a valid resolved packet exists.**
- [ ] **Step 2: Preserve all other review/blocker reasons and do not re-run weaker subject resolution when the packet is canonical and current.**
- [ ] **Step 3: Run the new regression and the existing Capture continuation/current-outcome tests.**

### Task 3: Verify canonical Video/Governance/publication invariants

**Files:**
- Modify: `public/wp-content/plugins/nhk-v3/public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php` if additional identity assertions are needed.
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with checkpoint evidence only after code verification.

- [ ] **Step 1: Run focused Capture, Video recovery, Governance and MCP contract tests.**
- [ ] **Step 2: Run PHP lint, relevant integration/contract tests, `git diff --check`, and secret review.**
- [ ] **Step 3: Regenerate MCP documentation/manifest only if the changed contract/runtime boundary requires it, then re-run its verification.**

### Task 4: Commit, push, and verify deployment before mutation

- [ ] **Step 1: Commit the verified code and evidence changes without credentials or runtime data.**
- [ ] **Step 2: Push the commit through the permitted Git workflow; do not force-push.**
- [ ] **Step 3: Verify STAGING runtime identity, deployed source revision, documentation checkpoint, schema `26/26`, and duplicate/read-only state over HTTPS.**
- [ ] **Step 4: If deployment/source revision is not active, stop with `AWAITING_DEPLOYMENT`.**

### Task 5: Execute bounded Video continuation and public read-back

- [ ] **Step 1: Invoke canonical `nhk.capture.ingest` for the exact Capture with the supplied idempotency key and `resume_children=['video']`.**
- [ ] **Step 2: Read back Capture, canonical Video, Governance status and public identity; confirm no duplicate Video.**
- [ ] **Step 3: Complete only registered Governance review/eligibility/approval/apply operations and publish through the canonical Video workflow.**
- [ ] **Step 4: Read archive/detail/canonical URL over HTTPS without authentication and confirm the page identifies YouTube `5CqjJDgzFcI`; report exact status or blocker.**
