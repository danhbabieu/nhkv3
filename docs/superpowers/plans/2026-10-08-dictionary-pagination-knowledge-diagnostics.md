# NHK V3 Dictionary Pagination and Knowledge Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add complete read-only Dictionary candidate pagination, repair Dictionary duplicate-audit keyset pagination, and expose field-level Knowledge identity diagnostics without changing canonical identity or semantic ownership.

**Architecture:** Keep `listForReview()` and existing response fields for compatibility. Add an optional page-reader contract implemented by the WPDB candidate repository and consumed by MCP. Encode bounded, filter-bound cursors using the existing repository cursor style. Extend the shared identity resolution result and system-wide Knowledge coverage diagnostics only; no data repair, migration, merge, rekey, or Governance mutation is included.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress `$wpdb` repositories, MCP catalog/transport.

**Spec:** User-provided master task: `Dictionary Pagination Repair, Knowledge Identity Diagnostics & End-to-End Acceptance`.

## Global Constraints

- Preserve the Constitution’s `subject + facet + scope + claim_type + proposition` identity law.
- Keep Dictionary lexical ownership separate from Authority, Knowledge, Source/Evidence and Graph.
- Keep all new operations read-only; do not mutate runtime data, production, staging, `Côn hoa thị`, or canonical IDs.
- Preserve existing MCP fields and ability names; add only optional cursor/diagnostic fields.
- Do not stage unrelated dirty working-tree changes.

## Review Focus

- Candidate tie ordering: test equal occurrences and UUID tie-breaks in one stable sequence.
- Candidate filter binding: test state filters and reject/restart cursors from another filter.
- Duplicate boundary: test more rows with one normalized form than one page and preserve every row.
- Knowledge provenance: test every missing identity field, multiple missing fields, Evidence-present rows, retired rows and conflicting video identity.
- Status separation: test unresolved Knowledge coverage remains owner `COMPLETE`, while reader/model failures remain `BLOCKED`.

### Task 1: Candidate pagination contract and repository

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryCandidatePageReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryCandidateRepository.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/WpdbDictionaryCandidateRepositoryTest.php`

- [ ] Write failing tests for 0/1/95/100/101 candidates, equal occurrences, state filtering, malformed cursor and filter-bound cursor.
- [ ] Implement `pageForReview()` with `occurrences DESC, candidate_uuid ASC`, lookahead, exact total, bounded cursor and fail-closed cursor validation.
- [ ] Run the repository test and then the Dictionary repository contract tests.

### Task 2: MCP candidate list pagination

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDictionaryHandler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpDictionaryCandidateListTest.php`

- [ ] Write failing tests for multi-page response, state-filtered pages, backward-compatible first call, malformed cursor and unavailable optional page reader.
- [ ] Consume the page-reader contract while retaining legacy fallback behavior for existing internal fakes.
- [ ] Add optional `cursor` input and return `page_count`, `total`, `has_more`, `next_cursor` without removing `count/items`.

### Task 3: Composite Dictionary duplicate cursor

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryEntryRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryDuplicateCandidateAudit.php` only if response diagnostics require it.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/WpdbDictionaryEntryRepositoryTest.php`

- [ ] Write a failing boundary test with tied normalized forms and multiple entries/senses.
- [ ] Implement a versioned composite cursor containing normalized form, entry numeric id and sense numeric id, with matching lexicographic SQL predicate and ordering.
- [ ] Run duplicate-audit regression tests, including multi-sense and homograph classification.

### Task 4: Knowledge identity diagnostics and status separation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeClaimIdentityResolution.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeClaimIdentity.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Audit/SystemWideDuplicateAuditCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeQualityAuditor.php` only if result diagnostics need projection.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/KnowledgeClaimIdentityTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SystemWideDuplicateAuditTest.php`

- [ ] Write failing assertions for `missing_fields`, lifecycle/source metadata when present, and explicit coverage impact.
- [ ] Add field-level diagnostics without guessing legacy/synthetic classification and without using Evidence/Source as identity fallback.
- [ ] Preserve owner/global status semantics and verify unresolved Knowledge rows do not become `BLOCKED`.

### Task 5: Verification and acceptance evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` only with a dated implementation checkpoint after verification.

- [ ] Run focused tests, PHP lint, full relevant Unit suite, `git diff --check`, and secret review.
- [ ] Attempt read-only runtime/database checks; classify unavailable runtime as blocked rather than PASS.
- [ ] Review diff and commit only task files plus the execution-state checkpoint; do not push or deploy.
