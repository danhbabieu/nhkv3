# Shared Enrichment Lifecycle Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Consume the Checkpoint 4 shared enrichment result inside the real Capture, Article, Video, Media, Knowledge, and completion lifecycles without making transient enrichment canonical truth.

**Architecture:** One shared boundary instance is wired into the native Capture composition and the Article/Video adapters. Capture assembles the authoritative transient request after phase admission and passes the result to downstream owner/proposal callbacks; Article/Video composition and SEO consume the content branch, Media receives bounded context only, Knowledge remains governed by the existing semantic writer, and completion records local readiness without inventing a global status.

**Tech Stack:** PHP 8+, PHPUnit, existing NHK V3 Capture/Article/Video/Media/Knowledge/Governance contracts.

**Spec:** `/Users/imac24-2125d/Developer/nhk-v3/docs/superpowers/specs/2026-09-23-shared-dual-enrichment-design.md`

## Global Constraints

- Shared enrichment is transient input, never canonical truth, Evidence, Knowledge, Graph authorization, or owner creation.
- Article body/title/public URL remain native WordPress authority.
- Video keeps one external-reference owner and exact subject packet.
- Media fast path and MediaUsage ownership remain unchanged.
- Knowledge mutation remains Proposal → Approval → Eligibility → Controlled Apply → canonical read-back.
- Optional enrichment remains local; required factual dependencies remain strict.
- No schema/data migration, live/staging mutation, deployment, publication, push, or Checkpoint 6 work.

## Review Focus

- The same shared result must reach real Article and Video downstream consumers without a second retrieval or owner; test lifecycle diagnostics and adapter input.
- Media enrichment must receive bounded Claims without entering Article composition or semantic writes; test the callback context and unchanged fast path.
- Knowledge candidate review must not block independent content completion; test separate branch readiness.
- Relation/readiness context must remain non-applied and use existing semantic writer/reconciliation inputs; test no direct boundary mutation.
- Retry must reuse owner and transient result without duplicate proposal/relation behavior; test repeated Capture execution.

---

### Task 1: Wire one shared boundary through real lifecycle construction

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

- [ ] Add optional precomputed `shared_enrichment` input handling to Article/Video adapters while preserving their existing downstream composition and quality calls.
- [ ] Inject one `SharedEnrichmentBoundary` instance into Plugin-created adapters and the Capture coordinator.
- [ ] Assemble the shared request after minimum owner admission and authoritative subject resolution; store only a body-free readiness summary in Capture diagnostics.
- [ ] Pass the full transient result to Article composition, Video semantic/publication callbacks, Media reconciliation, and governed semantic write-back context.
- [ ] Add lifecycle tests proving the shared result is consumed and repeated execution remains idempotent.

### Task 2: Preserve independent owner/proposal readiness

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php` only if existing vocabulary cannot represent the evidence
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

- [ ] Ensure optional Knowledge/relation gaps remain local while Article/Video owner completion uses existing quality/publication/read-back evidence.
- [ ] Ensure required unsupported factual dependencies are represented through existing blockers and cannot reach public eligibility.
- [ ] Ensure Knowledge Delta proposal readiness is passed to the existing semantic/Governance callback without direct Apply.
- [ ] Add tests for content-ready/Knowledge-review-required, optional relation incomplete, and required dependency blocked outcomes.

### Task 3: Verify the full regression surface

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Run focused Checkpoints 1–4 and Capture/Article/Video/Media/Knowledge/Governance/Relation/SEO/Quality/Completion/Living Knowledge suites.
- [ ] Run full Unit and the repository suite when the WordPress environment is available; record environment-gated failures separately.
- [ ] Run PHP lint on all changed PHP files, `git diff --check`, and a scoped secret review.
- [ ] Update execution state with CP5 evidence and explicitly defer Checkpoint 6.
