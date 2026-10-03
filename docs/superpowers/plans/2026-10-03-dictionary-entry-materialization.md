# Dictionary Entry Materialization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a read-only, reviewable materialization planner for existing Migration015 Dictionary Concepts, with a bounded governed apply path for the safe one-Concept-to-one-Entry case.

**Architecture:** Keep `DictionaryConcept` as the durable Sense identity. The planner reads Concepts, labels and Migration024 mappings, classifies conflicts without merging meanings, and emits a fingerprinted plan. The service applies only an explicitly approved fingerprint through the existing Dictionary mutation/repository boundary, using CAS, idempotency, audit and canonical read-back.

**Tech Stack:** PHP 8+, WordPress, PHPUnit, existing NHK V3 Dictionary repositories, MCP catalog/dispatch/ability registries.

**Spec:** User-provided request in `/Users/imac24-2125d/.codex/attachments/c7453364-337a-47f8-81bf-8b6f1ea30820/Văn bản đã dán.txt`, constrained by `docs/architecture/DICTIONARY_ENTRY_SENSE_ARCHITECTURE.md` and `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`.

## Global Constraints

- Production is read-only for this task; no V2/staging/production semantic mutation.
- Migration024 is additive schema only and must not populate semantic rows automatically.
- Safe default is `1 existing Concept → 1 Entry → 1 existing Concept-as-Sense`.
- Equal labels, normalized labels, slugs, destinations or similar definitions never auto-merge Concepts.
- Apply requires exact plan fingerprint, Concept/Entry CAS, idempotency, audit and read-back.
- No Graph, Knowledge, Source/Evidence, Mention or Candidate mutation is part of materialization.

## Review Focus

- Existing mapping is reused and never duplicated: repository/service tests.
- Same normalized label across Concepts remains separate and review-only for grouping: planner tests.
- Retired, invalid-destination and preferred-label-divergence cases are classified but not silently repaired: planner tests.
- A stale Concept revision rejects an approved plan: service tests.
- Missing schema/runtime is unavailable, not an empty corpus: runtime/MCP tests.

### Task 1: Inventory and deterministic plan model

**Files:** Create `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryEntryMaterializationPlanner.php`; focused unit tests.

- [ ] Write failing tests for unmapped, already mapped, inconsistent, retired, invalid destination, preferred-label divergence, equal-label grouping candidates, deterministic slug/form proposal and fingerprint.
- [ ] Implement bounded Concept inventory and plan output without writes; preserve definitions, contexts, destinations, candidate and mention counts as read-only diagnostics.
- [ ] Run focused tests and PHP lint.

### Task 2: Safe apply coordinator

**Files:** Create `DictionaryEntryMaterializationService.php`; extend Entry repository contract/implementation, runtime and mutation wiring; focused tests.

- [ ] Write failing tests for explicit fingerprint approval, one-to-one apply, reuse/idempotency, stale Concept/Entry CAS, duplicate detection and no merge.
- [ ] Implement the smallest apply path that reuses the existing Concept as Sense, creates the Entry and preferred Form through repository transactions, and returns canonical read-back/audit data.
- [ ] Run focused tests and migration safety tests.

### Task 3: Read-only MCP audit/plan exposure

**Files:** MCP Dictionary handler/catalog/dispatch/ability/capability wiring; contract tests.

- [ ] Write failing catalog/dispatch/schema/capability parity tests for materialization profile, plan and unmapped Concepts.
- [ ] Expose bounded read-only operations; expose apply only as internal/admin curator operation with exact plan/fingerprint/idempotency fields.
- [ ] Run MCP Dictionary and discovery parity suites.

### Task 4: Documentation and runtime evidence

**Files:** `DICTIONARY_ENTRY_SENSE_ARCHITECTURE.md`, `DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`, `CURRENT_DOCUMENTATION_STATUS_INDEX.md`, `V3_EXECUTION_STATE.md`, `MCP_V3_CONTENT_OPERATIONS.md`, create `DICTIONARY_ENTRY_MATERIALIZATION_RUNBOOK.md`.

- [ ] Document `SCHEMA MIGRATION != SEMANTIC MATERIALIZATION`, safe one-to-one default, grouping review law and production read-only status.
- [ ] Record runtime/schema/data inventory and explicit unavailable/mismatch outcomes without claiming unverified deployment success.
- [ ] Run documentation/bootstrap consistency checks.

### Task 5: Verification checkpoint

- [ ] Run focused Dictionary, MCP, migration and public-route tests, then the repository-required unit/integration checks available in the environment.
- [ ] Run PHP lint, `git diff --check`, secret review and inspect the final diff.
- [ ] Update execution state with evidence; do not commit or push unless separately requested.
