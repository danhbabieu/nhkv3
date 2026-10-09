# Video Outcome Relation Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the shared Outcome plan explicitly require a registered Video semantic relation whenever a Video requests public, frontend, or publication completion, without relaxing the existing Evidence-backed `about` law.

**Architecture:** Keep `OutcomeObligationCompiler` as the single receipt-level policy compiler. Derive the relation obligation from the normalized Video intent and existing public signals; leave owner-only canonical Video outcomes relation-optional. No new enum, schema, predicate, owner, writer, or runtime mutation is introduced.

**Tech Stack:** PHP 8.x, PHPUnit, existing NHK V3 Outcome compiler and unit-test conventions.

**Spec:** `docs/superpowers/specs/2026-10-09-video-semantic-architecture-review-cross-domain-law-alignment.md`

## Global Constraints

- Public/publish/frontend Video outcomes retain mandatory Evidence-backed `Video → about → target` semantics.
- Canonical owner completion remains separate from semantic, public and frontend completion.
- Do not mutate staging, production, V2 or real Video data.
- Preserve unrelated dirty worktree changes and stage only this plan's files.
- Work directly on `main` as explicitly authorized; do not push or deploy.

## Review Focus

- Video publish request with omitted `relations_required`: relation must become `REQUIRED`.
- Video frontend/public request without publication: relation must still become `REQUIRED`.
- Video canonical-only owner outcome: relation remains `OPTIONAL`.
- Non-Video public owner: existing relation behavior remains unchanged.
- Explicit generic `relations_required` and `relations_requested`: existing behavior remains compatible.

---

### Task 1: Pin Video relation obligation behavior

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php`

**Interfaces:**
- Consumes: `OutcomeObligationCompiler::compile()`.
- Produces: regression expectations for the compiler implementation.

- [ ] **Step 1: Add failing assertions**

Extend the existing explicit Video publication test to assert:

```php
$this->assertSame('REQUIRED', $plan['obligations']['relations']['class']);
$this->assertSame('VIDEO_PUBLIC_RELATION_REQUIRED', $plan['obligations']['relations']['reason']);
```

Add a test that `frontend_request=true` for a Video also requires relations,
and a test that a Video with no public/frontend/publication request keeps
`relations=OPTIONAL`.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
vendor/bin/phpunit public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php
```

Expected: failure because the current compiler reports `OPTIONAL` or
`RELATIONS_NOT_REQUIRED` when the relation signal is omitted.

### Task 2: Implement minimal compiler alignment

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/OutcomeObligationCompiler.php:68-87`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php`

**Interfaces:**
- Consumes: normalized intent, owner types and existing public signals.
- Produces: existing `obligations.relations` shape with a Video-specific reason only when the public surface is requested.

- [ ] **Step 1: Implement derived Video requirement**

Normalize the intent before constructing obligations. When the normalized intent
is `VIDEO`, `video` is an owner type, and `publicRequested` is true, set the
relation obligation to `REQUIRED` with reason
`VIDEO_PUBLIC_RELATION_REQUIRED`. Otherwise preserve the current precedence:
explicit `relations_required`, then `relations_requested`, then `OPTIONAL`.

- [ ] **Step 2: Run the focused test and verify GREEN**

Run:

```bash
vendor/bin/phpunit public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php
```

Expected: all tests in the file pass.

### Task 3: Verify parity and commit

**Files:**
- Review only: changed files from Tasks 1–2.

- [ ] **Step 1: Run focused parity tests**

```bash
vendor/bin/phpunit \
  public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/UniversalOutcomeObligationParityTest.php \
  public/wp-content/plugins/nhk-core/tests/Unit/CompletionConvergenceTest.php
```

- [ ] **Step 2: Run PHP lint and diff checks**

```bash
php -l public/wp-content/plugins/nhk-core/src/Application/Completion/OutcomeObligationCompiler.php
php -l public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php
git diff --check
```

- [ ] **Step 3: Inspect staged scope and commit**

Stage only the compiler, its test, and this plan. Confirm unrelated dirty
files are not staged, then commit:

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Completion/OutcomeObligationCompiler.php \
  public/wp-content/plugins/nhk-core/tests/Unit/OutcomeObligationCompilerTest.php \
  docs/superpowers/plans/2026-10-09-video-outcome-relation-alignment.md
git commit -m "fix: align video outcome relation obligations"
```
