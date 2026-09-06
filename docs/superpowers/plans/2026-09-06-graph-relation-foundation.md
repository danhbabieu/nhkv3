# Graph Relation Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make governed typed Graph relations usable by Knowledge and other semantic domains, queryable in both directions, and safely backfillable in development.

**Architecture:** Reuse the existing Graph nodes/edges repository, `PredicateRegistry`, endpoint resolvers and `GraphService`. Separate proposal identity from relation endpoint identity, then layer bounded read-model/neighborhood and generic governed backfill on top. No second store, raw writes, keyword relation inference, Product–Specimen predicate, or legacy-body migration.

**Tech Stack:** PHP 8 project, WordPress plugin runtime, PHPUnit, WPDB repositories, existing MCP catalog/transport, existing migration and governance services.

**Spec:** `docs/superpowers/specs/2026-09-06-graph-relation-foundation-design.md`

## Global Constraints

- `wp_posts` remains editorial truth; Graph remains the only canonical semantic relation store.
- All semantic mutations use Proposal → approval → eligibility → Controlled Apply → owning service/repository → canonical readback.
- No raw database writes, legacy-body import, V2/staging/production mutation, duplicate Graph store, keyword-derived canonical relation or invented Product–Specimen predicate.
- Existing UUIDs, stable keys, valid relations, Video semantic attachments, Media identity/assets/usages and Article bodies are preserved.
- Development `nhk_v3` apply is allowed only after tests and dry-run pass; no DOWN, DROP, TRUNCATE or reset.
- Every implementation change follows TDD: write a failing test, verify RED, implement minimally, verify GREEN, then refactor.
- At each checkpoint run relevant PHPUnit tests, PHP lint, `git diff --check` and secret review before claiming success.

## File Map

- Modify Graph domain/runtime: `public/wp-content/plugins/nhk-core/src/Domain/Graph/PredicateDefinition.php`, `PredicateRegistry.php`, `NodeReference.php` as needed for explicit endpoint identity and matrix policy.
- Modify governed command/apply boundary: `src/Application/Governance/ControlledApplyOperationRegistry.php`, `AuthorityProposalExecutor.php`, `src/Domain/Governance/Proposal.php`, `src/Infrastructure/Http/GovernanceApi.php`.
- Reuse/extend Graph application and repository: `src/Application/Graph/GraphService.php`, `RelatedSemanticQuery.php`, `PredicateTraversalPolicy.php`, `src/Contracts/Graph/GraphRepository.php`, `src/Infrastructure/Graph/WpdbGraphRepository.php` only if query batching/index evidence requires it.
- Add focused read-model/backfill classes under `src/Application/Graph/` and tests under `tests/Unit/`.
- Extend MCP only through `src/Application/Mcp/McpToolCatalog.php`, `McpReadHandler.php`, transport schemas and corresponding contract tests.
- Update canonical docs: Graph/Authority/Knowledge/Governance/MCP/read-model/frontend/backfill contracts plus `V3_EXECUTION_STATE.md` and `CURRENT_DOCUMENTATION_STATUS_INDEX.md`.

### Task 1: Lock the current behavior and reproduce the identity bug

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceApplyContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php`
- Inspect only: `src/Application/Governance/AuthorityProposalExecutor.php`, `src/Domain/Governance/Proposal.php`, `src/Infrastructure/Http/GovernanceApi.php`

**Interfaces:**
- Consumes: current `Proposal`, `AuthorityProposalExecutor`, `GraphService`, `InMemoryGraphRepository`.
- Produces: failing regression tests proving `knowledge + relation_create` is rejected by the compatibility registry and that a relation packet must preserve source/target UUIDs independently of `subjectId`.

- [ ] Step 1: Add a test that constructs a valid Knowledge-to-Classification relation packet with canonical UUID endpoint keys and asserts the current compatibility registry rejects `knowledge + relation_create`.
- [ ] Step 2: Add a serializer/fixture assertion showing `subject_id` is not a substitute for `source_key` and `target_key`, and that the packet contains the exact endpoint UUIDs.
- [ ] Step 3: Run `vendor/bin/phpunit public/wp-content/plugins/nhk-core/tests/Unit/GovernanceApplyContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php`; expect the new behavior tests to fail for the missing capability while existing tests remain green.
- [ ] Step 4: Commit the red tests with `git add public/wp-content/plugins/nhk-core/tests/Unit/GovernanceApplyContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/GraphCoreContractTest.php && git commit -m "test: reproduce governed knowledge relation gap"`.

### Task 2: Make relation commands generic and preserve endpoint UUIDs

**Files:**
- Modify: `src/Application/Governance/ControlledApplyOperationRegistry.php`
- Modify: `src/Application/Governance/AuthorityProposalExecutor.php`
- Modify: `src/Domain/Governance/Proposal.php`
- Modify: `src/Infrastructure/Http/GovernanceApi.php`
- Test: `tests/Unit/GovernanceApplyContractTest.php`, `tests/Unit/GovernanceCoreTest.php`

**Interfaces:**
- Consumes: relation packet fields `source_type`, `source_key`, `target_type`, `target_key`, `predicate`, optional `source_uuid`/`target_uuid` compatibility fields.
- Produces: `AuthorityProposalExecutor::relation()` that validates the packet and calls only `GraphService`; compatibility policy accepts the approved semantic relation command boundary without changing predicate endpoint rules.

- [ ] Step 1: Add failing tests for Knowledge relation apply to Brand, Model, Variant, Movement, Component, Classification and permitted Specimen endpoints, plus invalid source/target/predicate/missing UUID failures.
- [ ] Step 2: Add failing tests asserting an approved proposal serializes the exact relation endpoint UUIDs and apply does not derive an endpoint from `subjectId`.
- [ ] Step 3: Run the focused tests and confirm failures are caused by the registry gap or missing explicit packet validation.
- [ ] Step 4: Update `ControlledApplyOperationRegistry` to permit governed relation lifecycle operations for the supported semantic command boundary while preserving `relation` compatibility.
- [ ] Step 5: Add a small relation-packet validator in the existing governance/Graph boundary; require non-empty typed endpoint references, use `source_uuid`/`target_uuid` when present, and accept legacy key names only when they pass endpoint resolver validation.
- [ ] Step 6: Keep `Proposal.subjectId` unchanged as proposal subject metadata; update REST serialization/input normalization so relation endpoint UUIDs remain in the relation payload and malformed UUIDs fail closed.
- [ ] Step 7: Run focused tests and refactor only after GREEN; confirm Product–Specimen remains rejected and retired edges require explicit reactivation.
- [ ] Step 8: Run `git diff --check`, PHP lint on changed files, then commit with `git add ... && git commit -m "fix: govern semantic relation commands"`.

### Task 3: Formalize the executable predicate matrix

**Files:**
- Modify: `src/Domain/Graph/PredicateDefinition.php`, `PredicateRegistry.php`
- Modify: `src/Domain/Graph/RelationPolicy.php` only if current policy cannot express the approved pair
- Test: `tests/Unit/GraphCoreContractTest.php`, `tests/Unit/BrandRelationshipRegistryTest.php`, `tests/Unit/GovernanceApplyContractTest.php`

**Interfaces:**
- Consumes: registered endpoint types and existing predicate vocabulary.
- Produces: one runtime matrix with typed source/target pairs, cardinality and active policy; `classified_as` only for model/variant/specimen to classification if the current Authority contract confirms it.

- [ ] Step 1: Add failing matrix tests for all existing predicates and the three approved `classified_as` pairs; add a negative test for Product–Specimen.
- [ ] Step 2: Run the matrix tests and confirm `classified_as` fails before registration.
- [ ] Step 3: Register only the contract-approved `classified_as` definition and preserve current cardinalities for structural predicates.
- [ ] Step 4: Ensure `GraphService::create` checks endpoint existence before persistence and reports source/target errors separately.
- [ ] Step 5: Run Graph and governance tests; verify invalid predicate, invalid endpoint type, nil/malformed UUID and dangling endpoint cases fail closed.
- [ ] Step 6: Commit with `git add ... && git commit -m "feat: formalize graph predicate matrix"`.

### Task 4: Add direct Graph read service guarantees

**Files:**
- Modify: `src/Application/Graph/GraphService.php`
- Modify: `src/Contracts/Graph/GraphRepository.php` and `WpdbGraphRepository.php` only if needed for bounded query semantics
- Test: `tests/Unit/GraphCoreContractTest.php`, new `tests/Unit/GraphReadServiceTest.php`

**Interfaces:**
- Consumes: `NodeReference`, predicate registry and existing repository `outgoing/incoming` methods.
- Produces: bounded outbound/inbound reads with predicate, subject type, target type, active and cursor filters; stable edge ordering and duplicate-free items.

- [ ] Step 1: Add failing tests for outbound, inbound, predicate filter, endpoint-type filter, inactive exclusion, bounded limit and stable cursor behavior.
- [ ] Step 2: Run the new test file and confirm missing filters or ordering behavior.
- [ ] Step 3: Implement the smallest filter/read API on `GraphService` and repository contract, reusing existing SQL ordering by edge id.
- [ ] Step 4: Ensure default reads exclude retired edges and `includeRetired` remains explicit/admin-only.
- [ ] Step 5: Verify no N+1 endpoint resolution is introduced; use already hydrated edge nodes for returned endpoints.
- [ ] Step 6: Run Graph unit tests plus available WPDB Graph integration tests, lint and diff check; commit `feat: expose bounded graph relation reads`.

### Task 5: Build bounded semantic neighborhood profiles

**Files:**
- Modify: `src/Application/Graph/RelatedSemanticQuery.php`, `PredicateTraversalPolicy.php` only where reuse is required
- Create: `src/Application/Graph/SemanticNeighborhoodProfile.php`, `SemanticNeighborhoodQuery.php`
- Test: new `tests/Unit/SemanticNeighborhoodQueryTest.php`, existing `RelatedContentQueryTest.php`/`SemanticDossierQueryTest.php`

**Interfaces:**
- Consumes: `GraphService`, `PredicateRegistry`, `PredicateTraversalPolicy`, canonical starting `NodeReference`.
- Produces: `query(NodeReference $root, string $profile, int $maxHops=2, int $limit=50): array` with direct/derived classification, bounded path, deduplicated targets and fail-closed unavailable status.

- [ ] Step 1: Add failing tests for Classification, Variant and Model profiles, direct-vs-derived output, max-hop rejection, duplicate target suppression and deterministic ordering.
- [ ] Step 2: Run the tests and confirm the profile/read-model classes are absent or behavior is missing.
- [ ] Step 3: Implement immutable profile definitions using only registered predicates and endpoint types; do not invent inverse predicates.
- [ ] Step 4: Implement bounded traversal through `GraphService` with a visited set and a hard maximum hop count; keep direct and derived paths explicit.
- [ ] Step 5: Verify inactive edges are excluded and exceptions become an unavailable result rather than fabricated content.
- [ ] Step 6: Run all related semantic query tests and commit `feat: add bounded semantic neighborhood profiles`.

### Task 6: Add read-only MCP relation/neighborhood capability

**Files:**
- Modify: `src/Application/Mcp/McpToolCatalog.php`, `McpReadHandler.php`, `McpTransport.php` only following existing schema patterns
- Modify: `tests/Unit/McpContractTest.php`, `McpReadContractTest.php`, `McpSemanticContextResolverTest.php`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`

**Interfaces:**
- Consumes: `GraphService`/`SemanticNeighborhoodQuery` and current MCP authentication/transport validation.
- Produces: one read-only tool (prefer `nhk.entity.neighborhood`) with canonical UUID, profile, bounded depth and page-limit validation; raw Graph REST remains admin-only.

- [ ] Step 1: Add failing catalog/schema/handler tests for valid canonical UUID requests, invalid UUID/profile/depth/limit, inactive exclusion and unavailable Graph state.
- [ ] Step 2: Run MCP tests and confirm the tool is not currently exposed.
- [ ] Step 3: Register the tool through the current catalog and wire the handler to the read-model; preserve required/type/bounds/pattern/additional-property transport rules.
- [ ] Step 4: Verify the tool is read-only and does not create Proposal or Graph mutations.
- [ ] Step 5: Run MCP unit and transport integration tests; update the MCP contract with current capability status and commit `feat: expose governed graph read capability`.

### Task 7: Implement generic backfill dry-run and governed apply

**Files:**
- Create: `src/Application/Graph/RelationBackfillCandidate.php`, `RelationBackfillReport.php`, `RelationBackfillService.php`
- Modify: existing governed proposal factory/orchestrator only where an existing seam is appropriate
- Test: new `tests/Unit/RelationBackfillServiceTest.php`, existing migration dry-run/governance tests

**Interfaces:**
- Consumes: canonical record enumerators/resolvers, explicit relation hints, `GovernanceService`/`ControlledApplyService`, `GraphService` and the existing idempotency conventions.
- Produces: `dryRun(array $records): RelationBackfillReport` and `apply(RelationBackfillReport $report, callable $approvalRunner): RelationBackfillReport`; no candidate without deterministic resolution or explicit reviewed mapping.

- [ ] Step 1: Add failing tests for deterministic candidate creation, ambiguous/unsupported/evidence-gap/registry-gap classification, report counters and required ambiguous-row fields.
- [ ] Step 2: Add failing tests for existing-edge preservation, governed proposal creation, canonical readback and second-run zero-change.
- [ ] Step 3: Run the tests and confirm no generic service exists.
- [ ] Step 4: Implement resolver precedence: explicit UUID metadata, stable key, explicit relation hints, Article/Video intended relations, structured identity, exact normalized identifiers, then reviewed mapping; reject broad keyword matches.
- [ ] Step 5: Implement dry-run report counters and deterministic stable ordering; preserve ambiguity instead of auto-applying.
- [ ] Step 6: Implement apply by creating/submitting/approving/applying only governed candidates supplied to the approved runner; verify edge and inverse readback.
- [ ] Step 7: Run unit tests, confirm identical second run returns `ZERO_CHANGE_ON_SECOND_RUN`, and commit `feat: add generic governed relation backfill`.

### Task 8: Run Cuckoo and Odo development fixtures

**Files:**
- Modify: new/appropriate integration fixture tests under `tests/Integration/`
- Modify: `docs/semantic-packs/` only for read-only reports/receipts that are generated by the governed workflow
- Do not modify: V2/production/staging data or legacy article bodies

**Interfaces:**
- Consumes: completed registry, governed relation apply, direct/inverse Graph reads and backfill report service.
- Produces: evidenced development reports for Cuckoo Classification and Odo 36/8, with no recreated Knowledge/Authority/Component records.

- [ ] Step 1: Add integration tests that use the canonical Cuckoo Classification UUID and assert Knowledge inverse retrieval comes from Graph, not search.
- [ ] Step 2: Add integration tests for Odo 36/8 UUID and the three required Knowledge stable keys; assert all three direct `about` edges and inverse reads.
- [ ] Step 3: Run dry-run and inspect report counts, ambiguity rows, registry gaps and evidence gaps before apply.
- [ ] Step 4: Apply only deterministic, approved candidates through development Governance; read back every created edge and inverse result.
- [ ] Step 5: Run the backfill a second time and record zero-change/idempotency evidence.
- [ ] Step 6: Commit only code/tests and governed development reports with `git add ... && git commit -m "test: verify cuckoo and odo graph fixtures"`; do not claim live data changes without readback.

### Task 9: Reconcile frontend projections and documentation

**Files:**
- Modify: `src/Infrastructure/Frontend/FrontendSemanticBootstrap.php` and the existing frontend semantic query seam only where needed
- Modify: relevant frontend projection contract, Graph architecture contract, Authority relationship matrix, Knowledge contract, governance/Controlled Apply contract, MCP contract, backfill runbook, `CURRENT_DOCUMENTATION_STATUS_INDEX.md`, `V3_EXECUTION_STATE.md`
- Test: existing frontend semantic projection/read-model tests and route smoke where available

**Interfaces:**
- Consumes: `SemanticNeighborhoodQuery` and the current public eligibility/readiness policies.
- Produces: frontend sections backed by Graph/read-model data with Vietnamese-first empty/error states and no keyword fallback or internal terms.

- [ ] Step 1: Add failing projection tests for a populated direct relation, derived relation, inactive relation and empty neighborhood.
- [ ] Step 2: Wire existing entity pages to the shared read-model without changing page layout beyond the data-access seam.
- [ ] Step 3: Verify Article/Video/Media compatibility still uses the same Graph service; do not introduce domain-specific relation stores.
- [ ] Step 4: Update the relationship formula ledger with direction, inverse query, cardinality, canonical/derived status, frontend use, runtime status and governance/evidence rule.
- [ ] Step 5: Record `RELATION_PENDING`, ambiguity and live-runtime limitations in the current execution state/index without rewriting historical evidence.
- [ ] Step 6: Run projection/route tests, PHP lint, full unit suite, `git diff --check` and secret review; commit `docs: reconcile graph relation architecture`.

### Task 10: Final verification and completion report

**Files:**
- Modify: `V3_EXECUTION_STATE.md` with final evidence only
- Create if required by existing reporting convention: a dated Graph Relation verification report under `docs/architecture/`

**Interfaces:**
- Consumes: all task checkpoints, test output, dry-run/apply reports and canonical readbacks.
- Produces: evidence-backed final status with exact counts and explicit remaining gaps.

- [ ] Step 1: Run PHP lint on all changed PHP files.
- [ ] Step 2: Run the complete unit suite and all available guarded integration tests.
- [ ] Step 3: Run Graph-specific tests, MCP transport tests, backfill dry-run and second-run idempotency checks.
- [ ] Step 4: Verify Cuckoo and Odo 36/8 direct/inverse retrieval from canonical Graph.
- [ ] Step 5: Run `git diff --check` and secret review; inspect `git status --short --branch`.
- [ ] Step 6: Update execution state with ROOT CAUSE, changed architecture/files, migrations, predicates, MCP capability, backfill counts, fixture evidence, test/lint/migration results, remaining registry gaps, owner actions, commit SHA, push/merge status and working-tree status.
- [ ] Step 7: Commit the final evidence update with `git add ... && git commit -m "docs: record graph relation verification"`.
