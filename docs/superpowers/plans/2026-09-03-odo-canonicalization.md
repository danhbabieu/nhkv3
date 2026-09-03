# Odo Canonicalization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Normalize the mutable canonical Odo token from `o-do` to `odo` without changing UUIDs or immutable history.

**Architecture:** Add a token-aware canonicalization boundary and a versioned, dry-run/apply migration report. Authority changes use existing rekey/merge and Governance boundaries; WordPress values use recursive serialization-aware rewriting; audit/source quotations remain unchanged.

**Tech Stack:** PHP 8+, PHPUnit, WordPress APIs, existing NHK migration ledger.

**Spec:** User-provided canonical-token migration request and NHK V3 Constitution.

## Global Constraints

- `odo` is the only new canonical token; `o-do` is legacy input only.
- Preserve UUIDs, provenance, evidence quotations and immutable audit history.
- No raw SQL blanket `REPLACE()`, no duplicate semantic identity, no live/staging mutation without verified runtime gates.
- Every operation is idempotent, reports actions, and fails closed on ambiguity.

### Task 1: Canonical token policy and RED tests

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Migration/OdoCanonicalization.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/OdoCanonicalizationTest.php`

- [ ] Write failing tests for token-aware stable-key replacement, URL segment replacement, serialized arrays, JSON values, evidence quotation preservation, and idempotent second pass.
- [ ] Run the focused test and verify RED.
- [ ] Implement the minimal pure policy/reporting service.
- [ ] Run the focused test and verify GREEN.

### Task 2: Migration command/report integration

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/OdoCanonicalizationTest.php`

- [ ] Add dry-run/apply orchestration using existing migration status/ledger conventions, with no apply path when runtime or governance dependencies are unavailable.
- [ ] Add report fields for renamed, merged, rewritten, redirected, immutable and conflicts.
- [ ] Verify idempotency and fail-closed collision behavior.

### Task 3: Documentation and verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md`
- Modify: `docs/architecture/V3_MIGRATION_LEDGER.md`

- [ ] Record the legacy slug-normalization cause, migration boundary and runtime gate.
- [ ] Run focused/full tests, PHP lint, Composer validation, diff check and secret review.
- [ ] Commit implementation and documentation separately when the diff supports it.
