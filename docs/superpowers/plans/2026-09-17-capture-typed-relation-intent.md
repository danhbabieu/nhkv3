# Capture Typed Relation Intent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make explicit, exact Capture relation requests produce deterministic Graph relation candidates or typed fail-closed diagnostics and carry them through the existing Authority/Governance lifecycle.

**Architecture:** Add a narrow `authority_intent.relation_intents` schema packet containing exact typed source/predicate/target identity and provenance. A reusable planner validates the packet against the registered endpoint and predicate registries, resolves active canonical revisions, checks the existing Graph edge, and returns ordered relation candidates/reuse/blockers. The existing Authority plan fingerprint, `GovernedAuthorityPlanExecutor`, Proposal lifecycle, Controlled Apply and Graph read-back remain the only mutation path.

**Tech Stack:** PHP 8.x, WordPress plugin runtime, PHPUnit, MCP catalog/transport schemas, Authority/Graph/Governance services.

**Spec:** User-provided live staging bug report in `/Users/imac24-2125d/.codex/attachments/848f55a2-09a7-4c46-9cb5-1fb10b0bdea2/pasted-text.txt` and `docs/superpowers/specs/2026-09-17-governed-content-automation-expansion-design.md`.

## Global Constraints

- Graph is the single relation owner; no direct SQL, direct Graph mutation, parallel relation store, or Governance bypass.
- Only registered endpoint types and predicates are accepted; arbitrary prose never authorizes a relation.
- Exact source/target UUIDs, active state, current revisions, predicate and provenance are required before a candidate is emitted.
- Existing active edges return deterministic reuse/existing status and never duplicate or churn revision.
- Missing/invalid/retired/ambiguous endpoints and unsupported predicates return typed blockers or review diagnostics.
- Preserve unrelated dirty worktree changes; do not touch the 108 cuckoo Knowledge records or any staging data.
- Live mutation remains blocked unless the project-local bounded staging acceptance scope includes the exact supplied IDs.

### Task 1: Add the typed relation-intent contract and RED tests

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php` only if nested schema normalization needs explicit handling
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php` only if parity normalization needs a field allowlist
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ConversationalAuthorityMcpTest.php`
- Test: new `public/wp-content/plugins/nhk-core/tests/Unit/ExplicitRelationIntentPlannerTest.php`

**Interfaces:**
- Consumes the existing `authority_intent.mode` and `authority_intent.requests` packet.
- Produces `authority_intent.relation_intents[]` with `source_type`, `source_uuid`, `predicate`, `target_type`, `target_uuid`, and optional `provenance`/`reason`; unknown nested properties fail closed.

- [ ] **Step 1: Write failing schema tests** asserting the new nested field is present in the Capture catalog, requires the five identity fields, permits provenance/reason, rejects unknown fields, and survives `McpAbilityRegistration::canonicalTransportArguments()` unchanged.
- [ ] **Step 2: Run the focused schema tests** with `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ConversationalAuthorityMcpTest|ExplicitRelationIntentPlannerTest'` and confirm failure because `relation_intents` is absent.
- [ ] **Step 3: Add the smallest catalog schema** under `authority_intent`, with bounded array/object sizes and no free-form predicate enum beyond the runtime registry boundary.
- [ ] **Step 4: Run the focused schema tests** and confirm the catalog/WordPress Ability/Easy MCP normalized schema assertions pass.

### Task 2: Implement generic exact relation planning and RED/GREEN coverage

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Graph/ExplicitRelationIntentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Authority/AuthorityIntentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Authority/AuthorityPlanFingerprint.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityIntentPlannerTest.php`
- Test: new `public/wp-content/plugins/nhk-core/tests/Unit/ExplicitRelationIntentPlannerTest.php`

**Interfaces:**
- `ExplicitRelationIntentPlanner::__construct(EndpointTypeRegistry $endpoints, PredicateRegistry $predicates, callable $endpointState, callable $relationState)`.
- `ExplicitRelationIntentPlanner::plan(array $relationIntents): array` returns `relation_candidates`, `relation_reuse`, `blockers`, and `ambiguities`; candidates contain exact endpoint types/UUIDs/revisions, predicate, provenance, reason, action, candidate ID and dependencies.
- `AuthorityIntentPlanner` accepts the planner as an optional dependency for backward-compatible unit construction and merges its result into the existing plan without changing no-relation behavior.

- [ ] **Step 1: Add RED tests** for missing `classification → about → knowledge`, active existing edge reuse, unsupported predicate, invalid source UUID, invalid target UUID, wrong endpoint type, retired endpoint, deterministic ordering of multiple intents, and prose-only non-creation.
- [ ] **Step 2: Run only those tests** and verify they fail because no typed relation planner/path exists.
- [ ] **Step 3: Implement exact endpoint resolution** using the registered resolver/revision reader plus an explicit canonical state callback; reject missing, wrong-type, inactive and revision-unavailable endpoints with machine-readable codes.
- [ ] **Step 4: Implement predicate validation** through `PredicateRegistry`; never infer or rewrite the requested predicate.
- [ ] **Step 5: Implement exact active-edge lookup** through the injected read-only Graph callback; emit `REUSE/EXISTING` with canonical edge ID/revision, or a typed `CREATE` candidate when absent.
- [ ] **Step 6: Sort intents by canonical `(source_type, source_uuid, predicate, target_type, target_uuid)` before planning and include relation reuse/candidates in `AuthorityPlanFingerprint` candidate IDs and relation packets.
- [ ] **Step 7: Re-run the focused tests** and confirm GREEN, including unchanged existing Authority tests and the direct-prose negative case.

### Task 3: Wire Capture → plan → Proposal lifecycle without a second writer

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/AuthorityCaptureService.php` only if relation reuse/read-back diagnostics need persistence
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernedAuthorityPlanExecutor.php` only for relation reuse/result accounting if required by tests
- Modify: existing Governance/Graph read-back tests or add `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityRelationGovernanceLifecycleTest.php`

**Interfaces:**
- Runtime wiring supplies the registered `EndpointTypeRegistry`, `PredicateRegistry`, canonical endpoint-state resolver and exact Graph read callback to the planner.
- Existing `GovernedAuthorityPlanExecutor` remains the only path that creates relation Proposals; `AuthorityProposalExecutor` remains the only domain executor; `GraphService` remains the only Graph writer.

- [ ] **Step 1: Add RED lifecycle tests** proving missing relation candidate travels through Proposal → submit → approval → eligibility → Controlled Apply → canonical Graph read-back with bound endpoint revisions and provenance.
- [ ] **Step 2: Add RED tests** for source/target revision drift requiring reapproval/stale dependency behavior, changed typed request with the same idempotency key causing conflict, and retry/replan preserving exact relation intent and fingerprint sensitivity to each endpoint/predicate/revision change.
- [ ] **Step 3: Run the lifecycle tests** and confirm failure before runtime wiring/typed plan propagation exists.
- [ ] **Step 4: Wire the planner in the canonical Authority Capture factory** using existing canonical repositories/resolvers and the existing Graph read service; do not add a new mutation service.
- [ ] **Step 5: Preserve `relation_reuse` through plan/result diagnostics** so exact existing edges are reported without entering Proposal creation or mutating Graph.
- [ ] **Step 6: Run the focused Authority/Graph/Governance lifecycle tests** and confirm all RED cases are GREEN.

### Task 4: Schema parity, regression suite, and execution-state evidence

**Files:**
- Modify: `public/wp-content/plugins/nhk-v3/public/wp-content/plugins/nhk-core/...` only if a tracked generated/Ability projection is actually required by the repository
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test: MCP catalog/Ability/Easy MCP contract tests and relevant Capture/Authority/Graph/Governance tests

- [ ] **Step 1: Run focused Capture/Authority/Graph/Governance tests**, including retry/addendum tests and all new typed relation tests.
- [ ] **Step 2: Run MCP catalog/Ability/Easy MCP schema parity tests** and verify the typed relation packet is identical across layers.
- [ ] **Step 3: Run the full Unit suite, Contract suite, and guarded Integration suite when the required environment is available; record unrelated pre-existing failures separately.**
- [ ] **Step 4: Run PHP lint on changed PHP files, `git diff --check`, and the repository secret review without reading or committing credentials.
- [ ] **Step 5: Re-read `docs/architecture/V3_EXECUTION_STATE.md` and update it with the local root cause, files, test evidence, and the explicit live acceptance boundary.
- [ ] **Step 6: Verify current Git status/diff and do not claim live deploy, proposal, apply, or Graph read-back unless a bounded approved staging scope and fresh runtime identity are available.

### Task 5: Bounded live acceptance only after explicit scope verification

**Files:**
- No source-file changes; use the canonical MCP Capture/Governance/read-back boundaries only.

- [ ] **Step 1: Verify documentation bootstrap, deployed source revision, manifest hash, documentation version, and exact capture/owner IDs.
- [ ] **Step 2: Verify the project-local `STAGING_ACCEPTANCE_SCOPE` includes the supplied Capture `01a0afaf-e02a-7cc3-881a-99a16e665164`, the exact source/target records, and only relation reconciliation operations. If not, stop and report the missing authorization.
- [ ] **Step 3: Perform a read-only duplicate audit and Capture PLAN; require the exact relation candidate fields and source/target revisions.
- [ ] **Step 4: Continue only through normal Proposal → Approval → Eligibility → Controlled Apply and read `nhk.entity.neighborhood` for canonical Graph evidence.
- [ ] **Step 5: Replay the same typed intent and verify idempotency, no duplicate edge, and no revision churn.

