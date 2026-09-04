# Governed Semantic Ingest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce proposal/canonical identity separation and dependency-aware governed ingest from Source through Video relation.

**Architecture:** Keep MCP as an input/orchestration adapter and route all mutations through Governance. Add explicit canonical dependency/read-back ports around existing owners; use the existing proposal dependency graph, fingerprints and idempotency storage.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress plugin runtime, existing Governance repositories/services.

**Spec:** `docs/superpowers/specs/2026-09-04-governed-semantic-ingest-design.md`

## Global Constraints

- Preserve Constitution ownership, fail-closed behavior, `evidence_refs`, and Authority → Projection → Frontend boundaries.
- Never use a proposal UUID as a canonical entity UUID.
- Never mutate production, staging, V2, or seed semantic data.
- PRIVATE/HIDDEN evidence is verified internally without visibility promotion.

### Task 1: Response identity contract and ingest input validation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpGovernanceHandler.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

- [ ] Add failing tests asserting governed ingest responses expose `proposal_id`, `proposal_state`, `target_uuid`, `canonical_id`, and never expose proposal ID as canonical ID.
- [ ] Run the focused PHPUnit tests and confirm the expected RED failures.
- [ ] Implement a shared response envelope and preserve `canonical_id: null` for un-applied creates.
- [ ] Add structured validation for canonical dependency identifiers at request boundary.
- [ ] Re-run focused tests and refactor only after GREEN.

### Task 2: Canonical dependency validator and visibility-independent evidence checks

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/CanonicalDependencyValidator.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Knowledge/DependencyValidationException.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoRelationCandidatePlanner.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/P7KnowledgeTest.php`

- [ ] Add failing tests for proposal/draft/unresolved Claim, Source and Evidence UUID rejection.
- [ ] Add failing tests proving active PRIVATE/HIDDEN Evidence is valid internally but not public-readable.
- [ ] Implement validator using canonical repositories and structured errors.
- [ ] Route Evidence ingest and Video relation planning through the validator while preserving `evidence_refs`.
- [ ] Re-run focused tests.

### Task 3: Controlled Apply canonical owner/internal read-back

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Governance/CanonicalReadBack.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Governance/CanonicalApplyReadBackVerifier.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceApplyContractTest.php`

- [ ] Add failing tests for mismatched type, UUID, inactive state, revision and missing snapshot after apply.
- [ ] Implement owner/internal read-back verification without changing visibility.
- [ ] Make apply return verified canonical snapshot and fail closed when verification fails.
- [ ] Preserve idempotent replay semantics with the same verification requirement.
- [ ] Re-run focused Governance tests.

### Task 4: Dependency-aware governed orchestration

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedSemanticIngestOrchestrator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedSemanticIngestOrchestratorTest.php`

- [ ] Add failing happy-path and dependency-order tests for Source → Claim → Evidence → Video → Variant relation.
- [ ] Add failing tests proving no downstream create/apply after any upstream failure and no auto-approval under manual policy.
- [ ] Implement orchestration as Governance calls only, carrying idempotency/content/dependency fingerprints.
- [ ] Add canonical read-back gate after each apply.
- [ ] Add retry tests proving no duplicate proposals or entities.
- [ ] Run the focused orchestration suite.

### Task 5: Contract and execution documentation

**Files:**
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/MCP_V3_VIDEO_WORKFLOW.md`
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`
- Modify: `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Document proposal/canonical ID distinction, lifecycle, dependency order and internal read-back semantics.
- [ ] Record test evidence, environment limits and no-production-mutation status in execution state.
- [ ] Run full relevant PHPUnit, PHP lint, migration checks if applicable, `git diff --check`, and secret review.
