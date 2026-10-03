# Dictionary Public Detail Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement the approved Entry/Sense-aware public Dictionary detail projection without duplicating semantic ownership.

**Architecture:** Add mapping-first read helpers, compose a bounded per-Sense public detail packet, then add reverse Mentions/related terms and make the route/theme consume the packet. Keep the hub lightweight and preserve compatibility mode when Migration024 is unavailable.

**Tech Stack:** PHP 8+, WordPress repositories, PHPUnit 11, existing NHK V3 projection services.

**Spec:** `docs/superpowers/specs/2026-10-03-dictionary-public-detail-discovery-design.md`

## Global Constraints

- Dictionary Entry/Sense is never a Graph endpoint.
- No legacy Article-body migration, semantic backfill, Graph edge, production/staging mutation, deployment, push or merge.
- No raw SQL outside repository implementations.
- Public packets contain only renderable public data and no internal diagnostics.
- Existing owner projections remain the source of truth.
- Hub/search must remain lightweight; detail enrichment is detail-only.
 - The user-requested completion checkpoint is committed once after all verification passes; no intermediate checkpoint commit.

## Completion evidence — 2026-10-03

- `DictionaryDetailQuery` is the runtime composition boundary for one Entry and independent Sense packets.
- Hub/search uses lexical summaries only; semantic dossier, Graph, source and related-term work is detail-only.
- Mapping references take precedence; invalid/stale mapping states never fall through to another owner.
- Reverse Mentions and bounded related-term projection are wired through application adapters.
- Route SEO consumes the detail SEO packet; theme renders per-Sense semantic sections and a final, weaker Mention section.

## Review Focus

- Mapping-level semantic reference must beat legacy Concept destination: `DictionaryPublicQueryTest` and repository tests.
- Multi-Sense Entries must not leak the first Sense’s owner: detail packet tests.
- Schema-unavailable mode must remain compatibility-safe: runtime tests.
- Mention-only sources must not become semantic relations: mention projection tests.
- Owner-backed lexical pages must not emit unconditional indexability: route/SEO tests.

### Task 1: Mapping-first Entry/Sense read model

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryEntryRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryEntryRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryEntrySenseResolver.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryEntrySenseResolverTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRepositoryContractTest.php`

**Interfaces:** Add a mapping-aware read method returning Sense plus `semantic_reference` metadata; retain Concept fallback only when no durable mapping exists.

- [ ] Write failing tests for mapping precedence, absent mapping and partial/invalid mapping.
- [ ] Run the focused tests and verify the expected failures.
- [ ] Implement the smallest repository/domain read shape and resolver precedence.
- [ ] Run focused tests and the existing Dictionary resolver suite.

### Task 2: Existing Entry/Sense semantic-reference mutation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryEntryRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryEntryRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryMutationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRuntime.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMutationContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRepositoryContractTest.php`

**Interfaces:** Add `setSenseSemanticReference(entryId, senseId, expectedEntryRevision, type, id, targetRevision)` behind `DictionaryMutationService` operation `dictionary.entry-sense.semantic-reference.set`.

- [ ] Write failing tests for attach, change, same-value idempotency, stale CAS, invalid owner and audit/read-back.
- [ ] Run focused tests and verify failures are caused by the missing operation.
- [ ] Implement repository CAS update and mutation receipt/audit wiring without Graph or owner writes.
- [ ] Run focused mutation and repository tests.

### Task 3: Composed per-Sense public detail packet

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryDetailQuery.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySemanticReferenceProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPublicQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRuntime.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRuntimeContractTest.php`

**Interfaces:** `DictionaryDetailQuery::detail(string $slug): array` returns the public packet with Entry-level lexical data and independent per-Sense sections. Existing owner projection services are injected as adapters.

- [ ] Write failing tests for multi-Sense isolation, status values, mapping-first owner resolution and no first-Sense promotion.
- [ ] Run focused tests and verify failures.
- [ ] Implement detail composition with bounded owner deduplication and compatibility fallback.
- [ ] Remove related-term enrichment from `hub()`.
- [ ] Run focused Dictionary/runtime tests.

### Task 4: Reverse Mentions and governed related terms

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryMentionRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryMentionRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryMentionPublicProjection.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRelatedTermProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryDetailQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRepositoryContractTest.php`

**Interfaces:** Add bounded `listByConcept(conceptId, limit, offset)`; public projection resolves Article/Knowledge/Media/Video sources and deduplicates semantic sources before Mention-only groups.

- [ ] Write failing tests for reverse lookup, source grouping, bounds, dedupe and forbidden inferred relations.
- [ ] Run focused tests and verify failures.
- [ ] Implement repository reverse read and public adapters.
- [ ] Implement same-owner related terms with deterministic ordering and current-entry exclusion.
- [ ] Run focused tests.

### Task 5: Route, SEO and theme rendering

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicDictionaryRoutes.php`
- Modify: `public/wp-content/themes/nhk-v3/dictionary.php`
- Modify: `public/wp-content/themes/nhk-v3/dictionary.css`
- Modify: `public/wp-content/plugins/nhk-core/templates/dictionary.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FrontendContractTest.php`

**Interfaces:** Route passes one detail packet to the theme; SEO consumes packet indexability and canonical state. Theme renders the approved Vietnamese-first section order and never inspects repositories.

- [ ] Write failing contract tests for redirect/noindex/indexable cases and section order.
- [ ] Run focused tests and verify failures.
- [ ] Implement route head behavior and theme rendering for one- and multi-Sense packets.
- [ ] Run focused frontend and PHP tests.

### Task 6: Verification and checkpoint

- [ ] Run Dictionary/MCP-focused PHPUnit suite.
- [ ] Run PHP lint for changed files.
- [ ] Run `git diff --check`.
- [ ] Run changed-scope secret review.
- [ ] Update `docs/architecture/V3_EXECUTION_STATE.md` with local/no-mutation evidence only.
- [ ] Confirm no database mutation, migration, deployment, push or commit occurred.
