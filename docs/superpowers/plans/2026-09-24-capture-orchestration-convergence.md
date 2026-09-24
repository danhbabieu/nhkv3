# Capture Orchestration Convergence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Repair systemic Capture convergence for Article preparation, universal enrichment, Media required-owner aggregation, schema truthfulness and composition safety.

**Architecture:** Keep Capture orchestration-only and preserve existing canonical owners and Governance boundaries. Move only the broken admission/diagnostic/aggregation decisions into their existing boundaries; use the server-issued staging scope already owned by `StagingAcceptanceScopeVerifier`.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress plugin runtime, existing MCP Catalog/Ability/Easy MCP projections.

**Spec:** `docs/superpowers/specs/2026-09-24-capture-orchestration-convergence-design.md`

## Global Constraints

- No hard-coded Odo, UUID, attachment, Media or Capture identity in production.
- No direct writer, direct database write, Governance bypass, new semantic owner or new registry vocabulary.
- No live data mutation, migration, backfill, delete, recreate or deployment.
- Direct unscoped Media binding remains fail-closed.
- `COMPLETE` requires canonical read-back; optional enrichment debt remains distinguishable.

## Review Focus

- Preparation review with a resolved subject must either continue safely or expose a typed continuation; test in Task 1.
- Preparation review with ambiguous subject must remain fail-closed and machine-readable; test in Task 1.
- Shared enrichment must run without a client magic flag and must report subject-unresolved/deferred accurately; test in Task 2.
- Required-owner aggregation must never retain blank canonical IDs or duplicate the same owner; test in Task 3.
- Capture-issued Media scope must work while direct unscoped binding remains blocked; test in Task 4.
- Catalog, Ability, Easy MCP and dispatcher must not advertise unsupported Capture dry-run; test in Task 5.
- Non-Video Article prose must exclude workflow/source boilerplate; test in Task 6.

### Task 1: Make preparation outcomes resumable and truthful

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/PreparationPhaseAdmissionPolicy.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`

**Interfaces:**
- Consume `ContentPreparationResult::status`, `subjectResolutionPacket`, `reviewReasons`, `blockers` and `continuationDecision`.
- Produce persisted `content_preparation` diagnostics with explicit reason/candidate/continuation data and preserve existing `REVIEW_REQUIRED`/`BLOCKED` status semantics.

- [ ] Write one failing test for a resolved `IMAGE_ARTICLE` whose optional preparation review does not prevent draft creation.
- [ ] Run the focused test and verify it fails because the coordinator returns at `CONTENT_PREPARATION`.
- [ ] Write one failing test for ambiguous subject review requiring a machine-readable reason and continuation hint rather than an empty blocker set.
- [ ] Run the focused tests and verify the expected failures.
- [ ] Implement the smallest admission/diagnostic change: continue only for a resolved packet with no hard blocker; otherwise persist typed preparation reasons and a supported continuation state.
- [ ] Run the focused tests and verify they pass.
- [ ] Run the existing Capture reducer tests and verify retry truth is unchanged for hard blocks and is resumable only when the new continuation contract permits it.

### Task 2: Run shared enrichment from server-owned intent/needs

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify if required by failing tests: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentPreparationOrchestrator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalEnrichmentContractTest.php`

**Interfaces:**
- Consume resolved Content Intent, subject packet and existing `SharedEnrichmentBoundary`.
- Produce existing `shared_enrichment`, `deep_enrichment`, retrieval and completion diagnostics without adding a schema field or semantic owner.

- [ ] Add a failing generic Article test showing enrichment executes when no client `enrichment_requests`/magic flag is supplied.
- [ ] Add a failing test showing subject-unresolved preparation reports the actual existing reason instead of `NOT_REQUESTED`.
- [ ] Run both tests and verify they fail at the current early-exit/default path.
- [ ] Implement server-owned request derivation from the resolved intent/needs envelope, preserving facet-first retrieval and existing status vocabulary.
- [ ] Run the focused enrichment matrix and verify pass.
- [ ] Add/assert >50 candidate starvation, exact→relaxed→contextual, Coverage/KnowledgeUnit preservation, no fixed truncation and specimen-scope protection using existing shared-core fixtures.

### Task 3: Normalize completion required owners

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php` only if aggregation-level filtering is required by the red test.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CompletionCoordinatorTest.php` or the existing completion contract test file.

**Interfaces:**
- Consume Media reconciliation/read-back packets and Article intent requirements.
- Produce deterministic, deduplicated `required_owners` with non-empty canonical IDs and typed missing/enrichment diagnostics for absent optional slots.

- [ ] Add a failing test for Media IDs `[blank, canonical, canonical]` and assert one canonical Media required owner only.
- [ ] Add a failing test for missing Media on an image Article and assert no blank owner is emitted while the required media diagnostic remains.
- [ ] Run focused tests and verify the current blank-owner behavior fails them.
- [ ] Implement non-empty identity filtering and deterministic deduplication at the owner-spec construction boundary.
- [ ] Re-run completion tests, including partial child success and idempotent retry/no duplicate owner.

### Task 4: Prove Capture-issued Media staging scope

**Files:**
- Modify only if the new regression exposes a wiring/propagation defect: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`, `public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScopeVerifier.php`, or `public/wp-content/plugins/nhk-core/src/Plugin.php`.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingStagingAdmissionTest.php`
- Test: new focused Capture Media binding test under `public/wp-content/plugins/nhk-core/tests/Unit/`

**Interfaces:**
- Consume exact typed `media_bindings[]` through `nhk.capture.ingest`.
- Produce server-issued signed scope, `MediaBindingService` receipt and verified MediaUsage read-back; direct compatibility calls remain blocked.

- [ ] Add a failing test that exercises the coordinator fast path with a fake verifier/service and asserts scope fingerprint propagation.
- [ ] Add/assert the existing direct unscoped `STAGING_SCOPE_REQUIRED` behavior.
- [ ] Run the focused tests and verify the new end-to-end assertion fails before any production change.
- [ ] Fix only the missing propagation/wiring if needed.
- [ ] Run all Media binding and Governance staging tests.

### Task 5: Remove unsupported Capture dry-run advertisement

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify if projection-specific assertion fails: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`, `public/wp-content/plugins/nhk-core/src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ConversationalAuthorityMcpTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

**Interfaces:**
- Consume the single Catalog schema and its existing projection functions.
- Produce no `dry_run` property in Capture descriptors while preserving dedicated relation/Knowledge repair previews.

- [ ] Add a failing schema parity test asserting Capture does not advertise `dry_run`.
- [ ] Run the test and verify Catalog currently contains the field.
- [ ] Remove the field at the canonical schema owner and update derived projection assertions only where needed.
- [ ] Run Catalog, Ability, Easy MCP and dispatcher parity tests.

### Task 6: Protect Article composition from cross-domain contamination

**Files:**
- Modify only the existing shared editorial boundary/composer identified by the failing regression, likely `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php` or its Article adapter.
- Test: existing Article/quality contract test file plus a focused regression in `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`.

**Interfaces:**
- Consume claim trace and editorial-role metadata from the existing shared enrichment envelope.
- Produce Article title/body bounded to user input, media observations and applicable canonical claims; workflow diagnostics remain machine-readable only.

- [ ] Add a failing IMAGE_ARTICLE composition test containing Video/source boilerplate and assert it is absent from public title/body.
- [ ] Run the test and verify current leakage.
- [ ] Implement the narrowest existing-boundary filter; do not add a second claim store or semantic owner.
- [ ] Run Article, Video, compliance and editorial quality tests.

### Task 7: Full verification and checkpoint evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Run focused matrix for Capture, Completion, Enrichment, Media binding, MCP schema and composition.
- [ ] Run full PHPUnit with repository-supported memory limit.
- [ ] Run full PHP lint, `git diff --check`, Composer validation and scoped secret review.
- [ ] Attempt guarded integration only against exact `nhk_v3_test`; record environment gating without mutating data.
- [ ] Read `docs/architecture/V2_V3_PARITY_MATRIX.md` before any parity statement.
- [ ] Update execution state with root cause, files, tests, limitations and no-live-mutation evidence.
- [ ] Commit logical changes only after verification.

