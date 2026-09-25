# Video Universal Owner Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Preserve the generic Capture → Governance → Controlled Apply → canonical readback lifecycle for Video without losing bounded staging failures, while locking receipt uniqueness with a regression test.

**Architecture:** Keep the existing `GovernedCaptureContinuationService` as the shared orchestration boundary. Correct only its receipt classification and failure mapping; do not add a Video-only writer, bypass Governance, or change owner/enrichment/publication contracts.

**Tech Stack:** PHP 8.5, PHPUnit 11, PSR-4 NHK Core.

**Spec:** User-provided lifecycle slice in `Văn bản đã dán.txt`.

## Global Constraints

- Production logic remains fixture-independent and fail-closed.
- Canonical existence, enrichment readiness, and publication readiness remain separate.
- Governance Proposal → approval → eligibility → Controlled Apply → canonical readback remains mandatory.
- No staging or production mutation is performed.

## Review Focus

- A successful governed apply reports `CONTROLLED_APPLY` exactly once.
- A denied bounded staging packet preserves `STAGING_SCOPE_NOT_APPROVED` for operator action.
- A transient Video failure remains retryable and is not misclassified as staging denial.
- Article, Media, and future-owner completion semantics remain unchanged.
- Production source contains no fixture-specific Video data or branch.

### Task 1: Preserve shared lifecycle receipt and correct staging failure classification

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`

**Interfaces:**
- Consumes existing governed child results and `VideoException` messages.
- Produces one `CONTROLLED_APPLY` lifecycle marker and exact `STAGING_SCOPE_NOT_APPROVED` blocker.

- [ ] Write failing tests for the two behaviors using generic UUIDs and generic Video payloads.
- [ ] Run the focused tests and verify they fail for the expected reasons.
- [ ] Preserve the existing single lifecycle append and map the staging blocker before generic Video transient classification.
- [ ] Run the focused tests and the complete Unit suite.
- [ ] Run lint, diff check, and production special-case scan.

### Task 2: Checkpoint evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Record the root cause, changed boundary, test evidence, integration availability, and absence of runtime mutation.
- [ ] Re-read the execution state and parity matrix before final reporting.
