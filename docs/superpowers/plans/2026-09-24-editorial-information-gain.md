# Editorial Information Gain Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ensure adaptive editorial selection rejects candidates that add no new supported reader coverage instead of manufacturing marginal gain from a synthetic context key.

**Architecture:** Preserve `EditorialKnowledgeSelector` as the orchestration boundary and reuse `KnowledgeUnit`/coverage metadata. Exact and relaxed/contextual treatments remain distinct; only exact applicable coverage can advance exact coverage, while contextual material is selectable only when it meets the existing reader-value policy.

**Tech Stack:** PHP 8+, PHPUnit, existing NHK semantic application classes.

**Spec:** `docs/superpowers/specs/2026-09-24-universal-enrichment-core-design.md` and the approved Universal Enrichment Editorial Architecture report in the preceding task.

## Global Constraints

- Do not create owner-specific semantic brains.
- Do not create entity types, predicates, relations, schema or migrations.
- Do not mutate canonical Knowledge, Evidence, Graph, staging or production data.
- Usage memory remains a tie-breaker only and never changes truth or eligibility.
- Public specificity must not exceed support specificity.
- Sparse Knowledge remains sparse; no filler is introduced to satisfy length.

## Review Focus

- A second Claim for an already-covered exact facet must be excluded as redundant.
- A relaxed/contextual Claim must not manufacture exact coverage.
- A Claim with no coverage aspect must not bypass marginal-gain rejection through a synthetic ID.
- Rich pools must remain bounded and deterministic.
- Existing useful multi-facet selection and Article/Video parity must remain green.

### Task 1: Correct marginal information gain

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialKnowledgeSelectorAdaptiveTest.php`
- Update: `docs/architecture/V3_EXECUTION_STATE.md`

**Interfaces:**
- Consumes the existing `KnowledgeUnit::coverage_aspects`, `coverage_kind`, retrieval tier and selection policy.
- Produces the same `EditorialContextPack` shape and diagnostics, with redundant no-aspect units excluded as `LOW_MARGINAL_INFORMATION_GAIN`.

- [ ] **Step 1: Write the failing regression test**

Add a test with two eligible Claims in the same exact facet and identical normalized proposition group behavior, plus a second distinct facet. Assert that only the first Claim for the covered facet and the distinct-facet Claim are selected; a candidate whose exact coverage aspect is already covered must be excluded with `LOW_MARGINAL_INFORMATION_GAIN` rather than selected via `context:<claim_id>`.

- [ ] **Step 2: Run the focused test and verify RED**

Run the repository's PHPUnit command for `EditorialKnowledgeSelectorAdaptiveTest.php`. Expected result: the new test fails because the selector currently replaces empty aspects with a synthetic context aspect.

- [ ] **Step 3: Implement the minimal fix**

Keep empty aspects empty for exact Claims after subtracting `$covered`. For non-exact Claims preserve the existing contextual behavior only when the candidate has an explicit contextual coverage policy; do not allow the fallback to create exact coverage. Evaluate `LOW_MARGINAL_INFORMATION_GAIN` before any synthetic contextual accounting.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run the adaptive selector test file and the related semantic/editorial focused matrix. Expected result: all pass, including existing rich-pool, duplicate, relaxed/background and Article/Video parity tests.

- [ ] **Step 5: Run repository verification**

Run PHP lint for changed PHP files, Composer validation/lint where configured, and `git diff --check`. Record exact results in `V3_EXECUTION_STATE.md`; classify integration separately if the test database remains unavailable.

- [ ] **Step 6: Commit the focused slice**

Commit only the plan, selector, regression test and execution-state evidence with a focused message after verification.

## Plan self-review

- No new DTO, class, owner, relation, field or migration is required.
- The existing contextual Claim test remains covered by the same selector policy.
- The test asserts reader-visible selection behavior and exclusion reason, not implementation details.
- The plan does not address Media lifecycle or runtime Odo/Jacquemart acceptance; those remain separate causal slices.
