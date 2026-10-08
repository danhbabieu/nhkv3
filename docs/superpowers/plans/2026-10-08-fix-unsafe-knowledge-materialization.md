# Unsafe Knowledge Materialization Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent unasserted or non-evidence-backed Capture text from materializing Knowledge facts or Graph relations while preserving structured, governed semantic intake.

**Architecture:** Keep the fix at the semantic admission owner boundary. `TextInputInterpreter` may continue producing review candidates for general editorial interpretation, but `SemanticClaimCandidateGuard` must admit Knowledge candidates only when they exactly match a structured semantic assertion; `GovernedCaptureContinuationService` remains the sole governed planner/apply path. Existing proposal idempotency and canonical read-back behavior remain unchanged.

**Tech Stack:** PHP 8.x, PHPUnit, NHK V3 Capture/Governance/Knowledge/Graph application services.

**Spec:** User task “NHK V3 — FIX UNSAFE KNOWLEDGE MATERIALIZATION” and the active NHK V3 Constitution/contracts under `docs/constitution/` and `docs/architecture/`.

## Global Constraints

- No production access, deployment, push/pull, migration, schema change, documentation regeneration, direct database cleanup, or new staging Capture.
- Do not mutate, delete, retire, or repair the incident Knowledge or Graph records in this task.
- Do not bypass Governance; preserve Capture-bound idempotency and canonical read-back.
- Empty `semantic_assertions`, explicit non-assertive text, and missing `knowledge_observation` must not create Knowledge facts or Graph relations.
- Valid structured/evidence-backed observations and existing legitimate Knowledge flows must remain governed and idempotent.

## Review Focus

- A raw KNOWLEDGE_DELTA candidate with `semantic_assertions=[]` must be review-blocked before any Knowledge or relation plan.
- A continuation delta must not bypass structured semantic admission merely because it is labelled `CONTINUATION_DELTA`.
- A valid structured assertion must still create the expected governed Knowledge and relation plans, with evidence references preserved.
- Replaying an applied proposal must read back the canonical owner without applying again.
- Missing observation input must remain fail-closed and must not be converted into a fact from fallback text.

### Task 1: Lock the semantic admission regression contract

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/TextInputInterpreterRegressionTest.php` only if an interpreter-level assertion is needed to expose the existing fallback.

**Interfaces:**
- Consumes `GovernedCaptureContinuationService::execute()` and its existing `interpretation` packet shape.
- Produces named PHPUnit coverage proving invalid candidates yield no writes/plans while valid structured assertions preserve governed planning and replay read-back.

- [ ] **Step 1: Write failing tests** for empty `semantic_assertions`, explicit no-new-fact text, missing `knowledge_observation`, and continuation-delta bypass; assert `REVIEW_REQUIRED`, the semantic handoff blocker, and empty writes.
- [ ] **Step 2: Add a valid evidence-backed structured assertion test** asserting Knowledge and Graph plans remain present, preserve evidence references, and do not create an Article.
- [ ] **Step 3: Add/retain replay and canonical read-back assertions** using the existing applied-proposal tests, explicitly asserting no second apply and stable canonical identity.
- [ ] **Step 4: Run the focused tests and observe failure on the current guard behavior.**

### Task 2: Implement the minimal semantic owner-boundary fix

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticClaimCandidateGuard.php`

**Interfaces:**
- Consumes the existing structured interpretation packet and candidate array.
- Produces `ALLOWED` only for a non-empty candidate exactly matching a structured `semantic_assertions` text; otherwise produces `REVIEW_REQUIRED` with a stable blocker.

- [ ] **Step 1: Remove the no-dictionary-command allow-all path and the unconditional `CONTINUATION_DELTA` bypass.**
- [ ] **Step 2: Require a non-empty structured assertion exact match before Knowledge admission, preserving the existing blocker/diagnostic shape.**
- [ ] **Step 3: Run focused regression tests and confirm invalid inputs produce no Knowledge/Graph plans while valid evidence-backed assertions remain admitted.**

### Task 3: Verify the complete local boundary and incident disposition

**Files:**
- No production/schema/runtime files.
- Optional: update `docs/architecture/V3_EXECUTION_STATE.md` with a concise checkpoint only if repository workflow requires it; do not regenerate documentation.

- [ ] **Step 1: Run focused Capture/Semantic/Governance/Graph tests, PHP lint, `git diff --check`, and a changed-scope secret review.**
- [ ] **Step 2: Run the full NHK unit suite if feasible; record pre-existing/environment failures separately without masking them.**
- [ ] **Step 3: Perform final read-only diff review and confirm the incident records remain untouched; document the governed repair/retirement path as a future Capture-bound Governance operation requiring exact IDs, expected revisions, eligibility, controlled apply, and canonical read-back.**
- [ ] **Step 4: Commit the minimal code/test fix locally with no push or deployment.**

