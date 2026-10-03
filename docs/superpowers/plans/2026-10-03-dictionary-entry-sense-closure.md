# Dictionary Entry/Sense Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete the bounded Dictionary Entry/Form/Sense lifecycle, context-qualified resolution, Knowledge owner validation, candidate review actions, MCP parity, and Entry-centric public projection without migration or live data mutation.

**Architecture:** Keep `DictionaryConcept` as the durable Sense owner and add only Dictionary-owned Entry/Form relation lifecycle around it. Repository writes use UUIDs, CAS/revisions, idempotent receipts and canonical read-back; public projections are read-only and fail closed when owner validation is unavailable.

**Tech Stack:** PHP 8+, WordPress, PHPUnit, existing NHK V3 repositories, MCP catalog/dispatch/ability registries.

**Spec:** User-provided request in `/Users/imac24-2125d/.codex/attachments/b76eeb55-1eae-43c9-b7d3-3edbfb22e774/Văn bản đã dán.txt`, constrained by `docs/architecture/DICTIONARY_ENTRY_SENSE_ARCHITECTURE.md` and `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`.

## Global Constraints

- No Media/Video expansion, deploy, staging/production/V2 mutation, migration backfill, commit, or push.
- `DictionaryConcept` remains durable Sense identity; no URL-as-identity or label-equality auto-merge.
- Existing `concept.*`, `label.save`, candidate, mentions, resolve/search/profile operations remain compatible.
- Every mutation is capability/internal-only, revision-bound, idempotent, auditable, and read-back verified.

## Review Focus

- Context filtering must distinguish same-entry senses and fail closed without context.
- Invalid/inactive Knowledge references must never resolve or copy claim payload.
- Normalized form collisions and equal labels across different Entries must remain distinct.
- Delegated single-sense routes must redirect once and never enter the sitemap.
- Migration024 remains additive schema-only.

### Task 1: Entry/Form/Sense repository lifecycle

**Files:** `DictionaryEntryRepository`, `WpdbDictionaryEntryRepository`, Dictionary mutation service/runtime; focused unit tests.

- [ ] Add failing tests for create-with-sense, add form, add sense, duplicate/collision, CAS and read-back.
- [ ] Implement minimal repository/service APIs using existing UUID/CAS conventions and additive tables only.
- [ ] Run focused tests and PHP lint.

### Task 2: Context-qualified resolver and Knowledge validation

**Files:** `DictionaryEntrySenseResolver`, runtime Knowledge validator, focused resolver tests.

- [ ] Add failing tests for domain/context selection, ambiguity, invalid semantic references, active Knowledge, and stale route revalidation.
- [ ] Implement deterministic Entry → Sense filtering and owner validation without payload duplication.
- [ ] Run focused tests.

### Task 3: Entry-centric public query/routes/templates/sitemap

**Files:** `DictionaryPublicQuery`, `PublicDictionaryRoutes`, bridge/templates/sitemap, focused public tests.

- [ ] Add failing tests for multi-sense pages, delegated redirects, standalone Entry routes, legacy Concept compatibility, canonical URL and sitemap eligibility.
- [ ] Implement read-only Entry-centric projection and one-hop redirects while preserving Concept fallback.
- [ ] Run route/public tests.

### Task 4: MCP and candidate review parity

**Files:** MCP Dictionary handler/catalog/dispatch/ability wiring, candidate curation, contract tests.

- [ ] Add failing catalog/dispatch/ability and review-action tests.
- [ ] Expose only registered internal/admin Entry operations and route candidate actions through the lifecycle without removing old actions.
- [ ] Run focused MCP/candidate suite.

### Task 5: Final verification and checkpoint evidence

- [ ] Run targeted Dictionary/Semantic/MCP/public suites, then project-required baseline/full suite with suitable memory.
- [ ] Run PHP lint, diff-check, secret review, and inspect Migration024 for additive/idempotent/no-population behavior.
- [ ] Update `docs/architecture/V3_EXECUTION_STATE.md` with evidence; do not commit.
