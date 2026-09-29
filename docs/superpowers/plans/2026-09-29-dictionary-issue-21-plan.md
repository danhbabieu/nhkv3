# Issue #21 Dictionary Lexical System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the existing Dictionary lexical slice with deterministic harvesting, MCP/Admin curation, lifecycle/idempotency/read-back, and safe handoff packets to existing semantic owners.

**Architecture:** Reuse `DictionaryRuntime`, migration 015, the four lexical repositories and existing audit/Governance boundaries. Add a Dictionary MCP application service and wire it through the existing catalog/transport/Ability layers; semantic handoff returns a packet only and delegates any Authority/Knowledge/Graph mutation to the existing Governance lifecycle.

**Tech Stack:** PHP 8.x, WordPress plugin, wpdb repositories, PHPUnit, existing MCP JSON-RPC transport and Admin Workbench.

**Spec:** `docs/superpowers/specs/2026-09-29-dictionary-issue-21-design.md`

## Global Constraints

- Dictionary owns lexical Concept/Label/Candidate/Mention state only.
- Do not create Authority types, Graph predicates, parallel relation stores or generic WordPress semantic writers.
- Reuse migration 015; no live seed/backfill, V2 mutation, staging/production write, deploy or push.
- All curated writes require capability, optimistic revision, idempotency binding, audit/read-back and fail-closed conflict handling.
- Retire/reactivate is soft lifecycle; normal MCP has no hard-delete operation.
- Harvester observations from Article/Knowledge/Media/Video remain lexical candidates/mentions and never become Knowledge/Evidence/Graph truth.
- Public projection exposes approved, eligible concepts only; unavailable is not empty.

## Review Focus

- Replaying the same MCP idempotency key with changed payload must return a typed conflict; test in `DictionaryMcpServiceTest`.
- Retiring a concept/label must remove it from public lookup/autolink while preserving the row and allowing explicit reactivate; test in lifecycle contract tests.
- A delegated destination that is ambiguous, retired or unavailable must produce a handoff/public conflict and no Graph write; test in `DictionaryRelationHandoffTest`.
- Harvester dry-run must prove zero repository writes and deterministic source counters; test in `DictionaryBackfillDryRunTest`.
- MCP catalog, dispatch, Ability map and schema must agree; test in `McpDictionaryToolsContractTest`.

### Task 1: Confirm existing storage/audit boundary and extend lexical contracts

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryConceptRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryCandidateRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Dictionary/DictionaryMentionRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryCurationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryConceptRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryCandidateRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WpdbDictionaryMentionRepository.php`
- Modify or create only if required by failing tests: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/DictionaryMigration024.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryCurationServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMutationContractTest.php`

**Interfaces:**
- Consumes: existing Dictionary repositories, `WpdbAuditSink`/`nhk_audit_events` pattern and `DictionaryRuntime`.
- Produces: deterministic lexical mutation methods with request fingerprint/idempotency and audit/read-back semantics.

- [ ] Write failing tests for concept/label edit, retire/reactivate, idempotency replay/conflict, revision conflict and no hard-delete method.
- [ ] Run the focused tests and verify the failures are caused by missing behavior.
- [ ] Implement the smallest service/repository changes; persist audit through the existing audit boundary and add schema only if required.
- [ ] Run focused tests, migration unit tests and PHP lint for changed files.
- [ ] Commit: `feat: complete dictionary lexical mutation boundary`.

### Task 2: Add harvester and relation-handoff application services

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryHarvester.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRelationHandoff.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRuntime.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryBackfillDryRun.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryHarvesterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryRelationHandoffTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryBackfillDryRunTest.php`

**Interfaces:**
- Consumes: registered source observations, existing detector/planner/resolver, Authority/Knowledge/Graph read ports and Governance proposal packet shape.
- Produces: replay-safe mention/candidate harvest results, no-write dry-run reports and typed handoff packets containing canonical owner/revision/provenance without applying relations.

- [ ] Write failing tests for Article/Knowledge/Media/Video source attribution, weak-signal candidate-only behavior, replay-safe counts, no-write dry-run and ambiguous/retired handoff rejection.
- [ ] Run tests to verify RED.
- [ ] Implement bounded harvest orchestration and explicit `RELATION_HANDOFF_REVIEW_REQUIRED`/`REGISTRY_GAP`/`IDENTITY_CONFLICT` outcomes.
- [ ] Verify no call path reaches Graph mutation; run focused tests and lint.
- [ ] Commit: `feat: add dictionary harvester and relation handoff`.

### Task 3: Expose MCP Dictionary lookup and curation tools

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDictionaryHandler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDispatchRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpCapabilityManifest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/SingleEntryPointPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpDictionaryToolsContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpTransportValidationTest.php`

**Interfaces:**
- Consumes: `DictionaryRuntime`, `DictionaryCurationService`, `DictionaryPublicQuery`, `DictionaryHarvester`, `DictionaryRelationHandoff`.
- Produces: catalog/dispatch/Ability-parity tools for bounded lookup, concept/label CRUD, lifecycle, candidate review, handoff preview and dry-run; all mutations return canonical read-back.

- [ ] Write failing catalog/schema/dispatch tests for lookup, candidate queue, concept create/update/retire/reactivate, label add/update/retire/reactivate, candidate review and handoff preview.
- [ ] Run the MCP contract tests and verify RED.
- [ ] Add schemas with UUID/revision/idempotency bounds, capability routing and internal/admin labels; add dispatch and Ability mapping.
- [ ] Wire handler dependencies in `Plugin.php`; ensure unavailable storage returns explicit unavailable and no generic fallback.
- [ ] Run unit/contract transport tests and lint.
- [ ] Commit: `feat: expose governed dictionary MCP operations`.

### Task 4: Complete Admin curator UX and public lifecycle projection

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/DictionaryAdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPublicQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/DictionaryWordPressBridge.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicDictionaryRoutes.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/WordPressDictionarySitemapProvider.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryAdminLifecycleTest.php`

**Interfaces:**
- Consumes: lexical mutation service and public query/handoff packet.
- Produces: curator edit/lifecycle/review UI and public behavior where retired, delegated, ambiguous and unavailable records are explicit and safe.

- [ ] Write failing tests for retired exclusion, explicit reactivate, delegated direct links, no dedicated sitemap entry and unavailable distinction.
- [ ] Implement forms/nonces/capabilities with revision/idempotency inputs and Vietnamese operator labels; show provenance and handoff diagnostics.
- [ ] Run Admin/public focused tests and PHP lint.
- [ ] Commit: `feat: complete dictionary curator lifecycle surfaces`.

### Task 5: Documentation, execution evidence and full verification

**Files:**
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` only for implementation status clarifications, never to weaken law.
- Test: relevant Unit/Integration suites under `public/wp-content/plugins/nhk-core/tests/`

- [ ] Add current MCP tool names, capabilities, ownership split, handoff semantics, migration decision and dry-run/backfill rules to the active contracts.
- [ ] Run focused Dictionary tests, MCP contract tests, migration integration tests when environment permits, full available PHPUnit suite, PHP lint, `git diff --check`, and secret review.
- [ ] Read `V3_EXECUTION_STATE.md` again and record exact commands/results, runtime blockers and no-deploy/no-push status.
- [ ] Inspect final diff for invented types/predicates/writers and commit: `docs: record Dictionary Issue 21 implementation evidence`.

