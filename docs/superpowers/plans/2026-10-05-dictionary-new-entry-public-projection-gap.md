# Dictionary New-Entry Public Projection Gap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make newly created approved Dictionary Entries receive one persisted, collision-safe public route identity and appear consistently in detail, search and resolver projections.

**Architecture:** Keep Entry public identity in the existing Entry `context_json` boundary; do not add a second Dictionary identity store. Allocate it through one canonical writer using `CanonicalPublicSlugPolicy`, then invalidate only the existing Dictionary runtime label/projection cache after non-replay mutations.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress/WPDB repositories.

**Global Constraints:** Do not rerun materialization, rewrite Migration015, mutate semantic owners, touch production, derive URLs from UUIDs/labels during reads, or bypass Entry/Sense ownership.

**Review Focus:**

- New Entry route identity is persisted before public reads.
- Duplicate/replay does not mint a second Entry or identity.
- Slug collisions fail closed or use deterministic meaningful qualifiers only.
- Entry approval and preferred-form activation refresh public eligibility.
- Existing 29 materialized Entries remain byte-for-byte behaviorally unchanged.

### Task 1: Lock the failing public-identity lifecycle behavior

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMutationContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMutationContractTest.php`

- [ ] Add a regression proving create-with-sense passes a persisted `public_slug`, uses the Entry status expected by the approved lifecycle, and replay returns the same read-back without a second write.
- [ ] Add collision and lifecycle/cache callback assertions.
- [ ] Run the focused test and confirm it fails for the missing slug/lifecycle behavior.

### Task 2: Implement the canonical Entry public identity writer

**Files:**
- Create or modify the smallest Dictionary application/repository boundary needed for the writer.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryMutationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryEntryRepository.php`

- [ ] Allocate deterministic `/tu-dien/{entry-slug}/` identity with `CanonicalPublicSlugPolicy` and the existing Entry route scope.
- [ ] Persist the identity in Entry context with CAS/read-back and deterministic collision handling.
- [ ] Preserve caller-supplied canonical slugs when valid and never use opaque identifiers as suffixes.

### Task 3: Close projection invalidation and lifecycle parity

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryMutationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRuntime.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDictionaryHandler.php` only if the existing callback boundary requires it.

- [ ] Invalidate the existing runtime label/projection cache after create, preferred-label activation and lifecycle/public-eligibility changes, excluding idempotent replay.
- [ ] Keep hub/search/detail/resolver on persisted Entry identity and current approved state.

### Task 4: Verify focused behavior and record the checkpoint

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Run focused Dictionary mutation/public/detail/resolver/MCP tests, PHP lint, `git diff --check`, and changed-scope secret review.
- [ ] Confirm no materialization, migration, database mutation, deployment or production operation occurred.
- [ ] Commit only the minimal fix and checkpoint evidence.
