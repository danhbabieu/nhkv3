# NHK V3 Video Recovery and Publication Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Diagnose and, where all constitutional gates are satisfied, recover the two supplied Captures through canonical Video Governance and verify their public HTTPS projections without creating duplicate owners.

**Architecture:** Treat Capture, Authority subject resolution, Video, Graph/Evidence, Governance, Public Identity and frontend as separate owner boundaries. Use read-only runtime evidence first, then the original Capture idempotency identity and the canonical Capture continuation; never substitute a generic writer or invent a subject, proposal, Evidence packet or URL.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress plugin runtime, v59 NHK MCP abilities, governed Proposal lifecycle, public WordPress Video routes.

**Spec:** User-provided `CODEX TASK — END-TO-END NHK V3 VIDEO RECOVERY & PUBLICATION` in `/Users/imac24-2125d/.codex/attachments/b1a282e6-6187-4844-a2a8-025fb33aebb8/Văn bản đã dán.txt`.

## Global Constraints

- Preserve exact supplied Capture/Video/subject UUIDs, external YouTube identity, revisions, request fingerprint and original idempotency key.
- Capture A may use its persisted resolved subject packet only after canonical read-back; the stale review must be superseded in current projection while history remains durable.
- Capture B remains fail-closed until the exact canonical subject and any required server-signed reconciliation packet are available; do not choose among `ÔĐô 36/8`, `Odo 36`, and `8 côn` by guesswork.
- All semantic mutation follows Proposal → Submit → Review/Approve → Eligibility → Controlled Apply → canonical read-back.
- Staging mutation requires fresh documentation/build identity, exact IDs, duplicate/read-only audit, valid bounded packet, and the original idempotency identity; missing state is an external gate.
- Do not use direct SQL, generic WordPress writers, synthetic owners/Evidence, duplicate Video creation, hard delete, force-push or production cutover.

## Review Focus

- A resolved subject packet must clear only stale `PRIMARY_SUBJECT_NOT_RESOLVED`; verify with Capture retry regression.
- Capture retry must rehydrate the persisted source and original idempotency identity; verify that changed or guessed payloads fail closed.
- A referenced Video UUID with `video.get=null` must not be treated as a canonical owner; verify no proposal/apply/public URL is inferred.
- Ambiguous Capture B must not be forced to a Model/Variant; verify subject conflict remains review-required.
- Public completion requires canonical Video, valid Graph/Evidence closure, persisted Public Identity, detail/archive/SEO/frontend read-back and HTTPS 200.

### Task 1: Establish fresh baseline and deployed identity

**Files:**
- Read: `AGENTS.md`, Constitution, current documentation status, execution state, Video/Capture/Governance/Public Identity/SEO contracts.
- Read-only runtime: v59 documentation bootstrap, Capture get, Video get, canonical inventory, Proposal discovery, Graph inventory and Public URL audit.

- [ ] Confirm local HEAD, remote HEAD and deployed source revision/build/documentation identity.
- [ ] Read both exact Captures and the referenced subject/Video without mutation.
- [ ] Record duplicates, proposals, Graph/Evidence dependencies, public URL decisions and current frontend/public availability.
- [ ] Stop at any missing or stale runtime identity instead of substituting local state.

### Task 2: Verify local root-cause repairs

**Files:**
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoProposalReconciliationServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/CaptureVideoRecoveryIntegrationTest.php`

- [ ] Run focused tests covering stale subject review, resolved-packet retry control, source identity preservation, Video proposal reconciliation and public publication gates.
- [ ] If a reproducible local regression remains, write the smallest failing test first, then implement one owner-boundary fix and rerun the focused suite.
- [ ] Run PHP lint, contract tests, diff checks and scoped secret review.

### Task 3: Recover exact Capture continuation authorization

- [ ] Recover each original idempotency key only from an approved persisted/recovery read surface; never infer it from Capture UUID, Video UUID or URL.
- [ ] Verify the current documentation checkpoint and deployed source revision again immediately before mutation.
- [ ] Verify exact existing IDs, current revisions, duplicate audit and TEST runtime identity.
- [ ] If the key or server-signed packet is not available through a permitted boundary, record `EXTERNAL_GATE` and do not call a mutation surface.

### Task 4: Execute bounded Capture A/B recovery

- [ ] Capture A: continue through `nhk.capture.ingest` with the original idempotency key and `resume_children=["video"]`; verify stale-review re-evaluation and unchanged Video/source identity.
- [ ] Capture B: resolve the exact canonical subject through the server-owned reconciliation packet; if ambiguity remains, leave it review-required.
- [ ] Read back Capture, Video, Graph/Evidence and Proposal state after every governed stage; never treat transport acknowledgement as completion.

### Task 5: Governance, Public Identity and frontend verification

- [ ] Complete only registered Proposal approval, eligibility and Controlled Apply operations with exact revisions/fingerprints.
- [ ] Audit and, only after a clean scoped decision, reproject one persisted Public Identity owner.
- [ ] Run owner-bound frontend reconciliation and read `/video/`, detail route, canonical URL, sitemap/SEO/VideoObject and homepage selection policy.
- [ ] Mark `PUBLIC_VERIFIED` only with fresh unauthenticated HTTPS 200/read-back evidence; otherwise report the exact external gate.

### Task 6: Checkpoint and final report

- [ ] Update `docs/architecture/V3_EXECUTION_STATE.md` with evidence, not assumptions, after each completed checkpoint.
- [ ] Re-read `docs/architecture/V2_V3_PARITY_MATRIX.md` before any parity claim.
- [ ] Return exactly the requested fields: `ROOT_CAUSE`, `FIXES`, `COMMITS`, `TEST_RESULTS`, `LOCAL_HEAD`, `REMOTE_HEAD`, `DEPLOYED_SOURCE_REVISION`, `CAPTURE_A_STATUS`, `CAPTURE_B_STATUS`, `CANONICAL_VIDEO_IDS`, `GOVERNANCE_STATUS`, `PUBLIC_URLS`, `PUBLIC_HTTP_STATUS`, `FRONTEND_READBACK`, `REMAINING_BLOCKERS`, `FINAL_STATUS`.
