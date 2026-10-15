# NHK V3 Dictionary Capture Routing Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route Capture-bound Dictionary CREATE and related operations through the existing Dictionary/Governance owner path without requiring a Knowledge Subject or Authority Owner.

**Architecture:** The Capture coordinator remains orchestration only. A typed `dictionary_owner_plan` is normalized and re-planned by the canonical Dictionary owner, then admitted by the existing pre-create packet/CAS checks and applied through the registered Governance-backed Dictionary owner. Standalone lexical operations use a dedicated resolved Capture intent and stop before subject/Knowledge/Graph/Article branches.

**Tech Stack:** PHP 8.x, PHPUnit, NHK V3 WordPress plugin runtime, existing Dictionary Observation Registry, Capture coordinator and Entry/Sense repositories.

**Spec:** User request for GitHub Issue #31, constrained by `AGENTS.md`, `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`, `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`, and current MCP/Governance/Capture contracts.

## Global Constraints

- `nhk.capture.ingest` remains the only normal new-content entry point.
- Dictionary remains lexical ownership; Mention is not Evidence and lexical CREATE must not create Article, Knowledge, Authority or Graph records.
- All durable Dictionary mutation remains behind the existing Dictionary owner/Governance path with duplicate checks, idempotency, revision/CAS and canonical read-back.
- No migration, deploy, staging/production mutation, V2 mutation, direct database write, generic WordPress writer or Governance bypass.
- Existing Capture Article/Knowledge/Media/Video behavior must remain regression-safe.

## Review Focus

- A typed `operation=CREATE` must override heuristic Article/Knowledge routing and remain standalone when no subject exists.
- Replayed CREATE with the same idempotency key must reuse one owner result and not duplicate Entry/Sense.
- `ADD_FORM`, `ADD_SENSE` and `ENRICH` must retain their existing registered operation mapping.
- Subject ambiguity must not erase a valid lexical Capture, while lexical observations must not become Evidence or semantic truth.
- A failed/unavailable Dictionary owner must remain an explicit review/unavailable outcome and must not fall through to another writer.

### Task 1: Regression coverage for typed standalone Dictionary routing

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`

**Interfaces:**
- Consumes the existing `ContentIntentRouter` and `EditorialCaptureCoordinator` test seams.
- Produces executable assertions for operation routing, standalone owner completion, idempotency and no unintended owner writes.

- [x] Add a router test proving `dictionary_owner_plan.operation=CREATE` resolves to a non-Article Dictionary intent even when the text would otherwise be classified as an article or knowledge delta.
- [x] Add a coordinator test with the exact Bính boong preferred form and definition, no subject hints, and a registered owner planner/applier; assert one Dictionary owner apply, no `PRIMARY_SUBJECT_AMBIGUOUS`, no Article/Knowledge/Graph calls, private/non-public state, and canonical Entry/Sense read-back.
- [x] Add a replay assertion using the same idempotency key and assert the owner is applied once and the second result is the same canonical read-back.
- [x] Add operation coverage for `ADD_FORM`, `ADD_SENSE` and `ENRICH` at the existing Capture-to-owner boundary without weakening their precondition checks.
- [x] Run the focused tests and confirm they fail for the current routing behavior before changing production code.

### Task 2: Fix typed-plan preservation and standalone admission

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentIntentRouter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`

**Interfaces:**
- `ContentIntentRouter::route(array $input, array $interpretation, array $assets): array` recognizes the registered Dictionary owner operations.
- `EditorialCaptureCoordinator` preserves the server-owned typed plan across the interpreted checkpoint and applies it through `DictionaryObservationRegistry` plus `CaptureDictionaryCreatePrecondition`.

- [x] Add a registered Dictionary-only Capture intent/result shape using existing vocabulary; do not invent a new semantic owner or public intent.
- [x] Persist typed owner plans under the same canonical diagnostic/context key used by natural owner plans, while retaining a bounded typed-plan diagnostic if useful for audit.
- [x] Make standalone Dictionary operations exit before Content Preparation/subject resolution and before Article, Knowledge and Graph branches, while retaining Governance/read-back failure behavior.
- [x] Keep CREATE mapped to Entry+first Sense, ADD_FORM to form addition, ADD_SENSE to sense addition, and ENRICH to existing enrichment through the current resolver/precondition/apply mapping.

### Task 3: Verification, checkpoint and delivery evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [x] Run focused Dictionary/Capture tests, the related Unit suite, contract tests, PHP lint, `git diff --check`, and a repository secret review.
- [x] Re-read the current execution state and parity matrix before the checkpoint; record only local/no-mutation evidence in the execution state.
- [ ] Review the complete diff, preserve unrelated worktree changes, and commit only Issue #31 files.
- [ ] Request code review on the committed branch, fix any critical/important findings, then create a Pull Request linked to Issue #31 without merging or deploying.
