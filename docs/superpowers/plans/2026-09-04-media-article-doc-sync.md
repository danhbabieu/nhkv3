# Media Article Documentation Synchronization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Synchronize V3 Constitution, Media/Article/MCP contracts, projection/governance guidance, and execution state with the completed local Media V3 and Article V3 implementation and its latest runtime evidence.

**Architecture:** Keep the Constitution as the sole normative authority. Merge implementation facts into the relevant non-normative contracts, and record evidence with explicit `implemented locally`, `verified by focused tests`, and `runtime verification still required` states. Preserve unrelated working-tree changes and do not mutate live or legacy data.

**Tech Stack:** Markdown contracts, PHP/PHPUnit repository evidence, Composer validation/lint, Git.

**Spec:** `AGENTS.md`, `docs/constitution/NHK_V3_CONSTITUTION.md`, and the owner decisions in the current implementation/runtime checkpoints.

## Global Constraints

- Every Media file ingest/adoption uses the governed Media V3 flow.
- WordPress attachments are storage/projection records, never semantic authority.
- One canonical Media identity owns retained private source-original and public optimized derivatives.
- `representative`, `evidence`, and `technical_detail` roles remain distinct; evidence/detail never auto-replaces representative.
- Corrupt/fake image payloads fail closed before durable persistence and must not leave orphan records.
- Article subject resolution is UUID, stable key, exact canonical name/alias; ambiguity fails closed and generic preflight never hard-codes a Post ID.
- Runtime acceptance must cover real-file ingest through attachment, Media identity, assets/usages, projection, and Article preflight.
- No secrets, live/legacy data mutation, or unrelated user-owned changes.

### Task 1: Reconcile normative and implementation contracts

**Files:**
- Modify: `docs/constitution/NHK_V3_CONSTITUTION.md`
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- Modify: `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: affected Media/Projection/Governance guidance identified by repository search

- [ ] Read implementation and current checkpoints.
- [ ] Merge the owner-approved Media and Article laws into the relevant sections, correcting stale status claims.
- [ ] Add the fail-closed image validation/orphan invariant and the complete runtime acceptance chain without claiming unproven runtime success.

### Task 2: Update execution evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Add a current checkpoint that separates local implementation, focused-test proof, and remaining runtime verification.
- [ ] Preserve prior historical evidence while making the current gate status authoritative for this turn.

### Task 3: Verify, review, commit, and push

- [ ] Re-read the complete changed diff for cross-contract contradictions.
- [ ] Run relevant docs/contract tests, `composer lint`, `git diff --check`, and secret/status checks.
- [ ] Commit only this turn's documentation changes with a clear message.
- [ ] Push the current branch to `origin` and report files, checks, SHA, branch, and push result.
