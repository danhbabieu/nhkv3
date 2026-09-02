# Article Ingest Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a reconcile-only Article Ingest coordinator that safely
reconciles semantic deltas for an existing `wp_post`, proves Post 55
preservation, persists durable receipt state, and fails closed for create or
editorial update.

**Architecture:** A thin coordinator owns only stage transitions and delegates
to focused receipt, editorial-read, preflight, proposal-planning, controlled
apply, verification, diagnostic and MCP adapters. WordPress remains editorial
authority; semantic writes remain Proposal → Approval → Eligibility →
Controlled Apply. Phase 1 performs no WordPress editorial mutation.

**Tech Stack:** PHP 8.x, WordPress native APIs for read-only Post/revision
inspection, existing NHK domain services, wpdb, PHPUnit 11, Composer PSR-4.

**Spec:** `docs/superpowers/specs/2026-09-02-article-ingest-phase1-design.md`

## Global Constraints

- Only `intent=reconcile` may execute semantic mutation in Phase 1.
- `create`, editorial `update`, draft creation and publish return
  `UNSUPPORTED_OPERATION` and perform no write.
- No Article Authority type, Article endpoint, Article body projection or new
  generic Governance operation.
- The only WordPress target identity is `wp_post:<blog_id>:<post_id>`.
- Post 55 uses runtime blog ID and numeric Post ID 55; never hard-code blog ID.
- Do not call `V2MigrationService` or `PostKnowledgeLinkService` from Article
  code.
- Every semantic mutation goes through existing Governance and Controlled Apply.
- Receipt state never stores the full Article body.
- No compensating semantic rollback is implemented.
- No production, V2 or staging data is mutated; destructive integration work
  is restricted to `nhk_v3_test` and `TestDatabaseGuard`.
- Run every new behavior through RED → GREEN → REFACTOR, watching the RED
  failure before writing its production implementation.

---

### Task 1: Define Article operation stages, outcomes and receipt value objects

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticleIngestStage.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticleIngestOutcome.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticleOperationReceipt.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Article/ArticleOperationReceiptRepository.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleOperationReceiptTest.php`

**Interfaces:**
- `ArticleIngestStage` exposes `receipt`, `preflight`, `governance`,
  `semantic_apply`, `verification`, and `complete` backed by stable strings.
- `ArticleIngestOutcome` exposes the approved values `COMPLETED`,
  `SEMANTIC_PREFLIGHT_REJECTED`, `GOVERNANCE_PENDING`,
  `GOVERNANCE_REJECTED`, `SEMANTIC_APPLY_FAILED`, `VERIFICATION_FAILED`,
  `STALE_SEMANTIC_REVISION`, `IDEMPOTENCY_CONFLICT`,
  `DEPENDENCY_UNAVAILABLE`, `RECONCILIATION_CONFLICT`, and
  `UNSUPPORTED_OPERATION`.
- `ArticleOperationReceipt` accepts operation UUID, idempotency key, request
  fingerprint, intent, optional WP endpoint/post, stage, outcome, retryable
  flag, proposal IDs, applied proposal IDs, failure details, revision and
  timestamps; it rejects empty identity and invalid UUID values.
- `ArticleOperationReceiptRepository` exposes `findByIdempotencyKey(string):
  ?ArticleOperationReceipt`, `create(ArticleOperationReceipt):
  ArticleOperationReceipt`, and `save(ArticleOperationReceipt):
  ArticleOperationReceipt`.

- [ ] **Step 1: Write the failing test**

```php
public function test_receipt_rejects_empty_identity_and_accepts_terminal_state(): void
{
    $this->expectException(\InvalidArgumentException::class);
    new ArticleOperationReceipt('', 'key', str_repeat('a', 64), 'reconcile', null, null, 'receipt', 'COMPLETED', false, [], [], [], 1);
}
```

Add a second assertion constructing a valid receipt and checking its serialized
state contains no body field.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleOperationReceiptTest.php`

Expected: FAIL because the Article domain classes do not exist.

- [ ] **Step 3: Write minimal implementation**

Implement the two string-backed enums, immutable receipt object and repository
interface. Keep failure details as an array and do not add editorial body or
semantic canonical fields.

- [ ] **Step 4: Run test to verify it passes**

Run the same PHPUnit command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Domain/Article public/wp-content/plugins/nhk-core/src/Contracts/Article public/wp-content/plugins/nhk-core/tests/Unit/ArticleOperationReceiptTest.php
git commit -m "feat: define Article Ingest receipt contract"
```

### Task 2: Add durable receipt storage and UP migration

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Article/WpdbArticleOperationReceiptRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/ArticleIngestMigration010.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` migration target/boot/activation wiring
- Modify: `public/wp-content/plugins/nhk-core/src/Shared/Migration/MigrationStatus.php` readiness list
- Create: `public/wp-content/plugins/nhk-core/tests/Support/InMemoryArticleOperationReceiptRepository.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/ArticleReceiptRepositoryIntegrationTest.php`

**Interfaces:**
- Storage table is `${prefix}nhk_article_operations` with unique
  `idempotency_key`, request fingerprint, intent, WP endpoint/post, stage,
  outcome, retryable, proposal/applied IDs JSON, failure JSON, revision and
  timestamps.
- The repository uses optimistic receipt revision in `save()` and returns the
  existing row after a duplicate-key race so the coordinator can compare the
  request fingerprint.
- The migration is UP-only in normal runtime and DOWN is guarded to exact
  `nhk_v3_test`, matching existing migration policy.

- [ ] **Step 1: Write the failing test**

Add integration assertions for same-key/same-fingerprint replay, same-key/
different-fingerprint conflict at the coordinator boundary, revision conflict
on stale receipt save, and JSON round-trip of proposal IDs and failure details.

- [ ] **Step 2: Run test to verify it fails**

Run: `NHK_WP_TEST_PATH=public vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Integration/ArticleReceiptRepositoryIntegrationTest.php`

Expected: FAIL because the table and repository are absent. Do not run any DOWN
operation and do not target `nhk_v3` destructively.

- [ ] **Step 3: Write minimal implementation**

Follow `WpdbProposalRepository` hydration and optimistic-save conventions. Use
`dbDelta()` only in `ArticleIngestMigration010::up()`. Do not persist the body.
Register the migration after version 9 and include receipt-table readiness in
health/storage checks.

- [ ] **Step 4: Run test to verify it passes**

Run the guarded integration command again. Expected: PASS on configured
`nhk_v3_test`.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Infrastructure/Article public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/ArticleIngestMigration010.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/src/Shared/Migration/MigrationStatus.php public/wp-content/plugins/nhk-core/tests/Support/InMemoryArticleOperationReceiptRepository.php public/wp-content/plugins/nhk-core/tests/Integration/ArticleReceiptRepositoryIntegrationTest.php
git commit -m "feat: persist Article Ingest operation receipts"
```

### Task 3: Implement read-only WordPress editorial state fingerprinting

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/EditorialStateToken.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/EditorialPostState.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Article/EditorialStateReader.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Article/WpEditorialStateReader.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialStateTokenTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/EditorialStateReaderIntegrationTest.php`

**Interfaces:**
- `EditorialStateReader::read(int $postId): ?EditorialPostState`.
- `EditorialPostState` exposes post ID, endpoint key, type, status, title,
  content, excerpt, slug, permalink, latest revision ID, revision count and
  opaque token.
- `EditorialStateToken::fromState(array $state): string` hashes a canonical
  ordered snapshot. The token is not a CAS claim and is never persisted with
  the body.
- `WpEditorialStateReader` uses `get_post()`, `get_permalink()` and
  `wp_get_latest_revision_id_and_total_count()` when available. It performs no
  write and rejects an invalid endpoint/post.

- [ ] **Step 1: Write the failing test**

Test that changing body, title, status, slug, permalink or revision metadata
changes the token; reordering associative input does not; and the token never
contains the body as a stored field.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/EditorialStateTokenTest.php`

Expected: FAIL because the value objects do not exist.

- [ ] **Step 3: Write minimal implementation**

Use fixed field ordering and SHA-256 over canonical JSON. Preserve the raw
WordPress values in the in-memory read result for comparison, but only pass the
opaque token into receipt state.

- [ ] **Step 4: Run test to verify it passes**

Run both the unit test and the guarded reader integration test. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Domain/Article public/wp-content/plugins/nhk-core/src/Contracts/Article/EditorialStateReader.php public/wp-content/plugins/nhk-core/src/Infrastructure/Article/WpEditorialStateReader.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialStateTokenTest.php public/wp-content/plugins/nhk-core/tests/Integration/EditorialStateReaderIntegrationTest.php
git commit -m "feat: fingerprint WordPress editorial state"
```

### Task 4: Add reconcile preflight and deterministic semantic proposal planning

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestPreflight.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/SemanticProposalPlanner.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticlePreflightResult.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/SemanticProposalCommand.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Governance/CommandCanonicalizer.php` only if a missing deterministic helper is proven by a failing test
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestPreflightTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticProposalPlannerTest.php`

**Interfaces:**
- `ArticleIngestPreflight::check(string $endpointKey, string $intent, array $commands): ArticlePreflightResult`.
- `SemanticProposalPlanner::plan(string $operationId, array $commands): list<SemanticProposalCommand>`.
- A command has `slot`, existing Governance `operation`, `entity_type`,
  `subject_id`, optional `target_uuid`, positive `expected_revision`, payload
  and `dependency_slots`.
- Preflight validates endpoint/target, current registered operation vocabulary,
  Authority type registry, Graph endpoint/predicate allow-list, source/evidence
  references and duplicate-risk Evidence. It never calls a mutation service.
- Planner derives child idempotency keys from operation ID plus slot and derives
  content/dependency fingerprints with existing `CommandCanonicalizer`.

- [ ] **Step 1: Write the failing test**

Cover Post 55 endpoint acceptance, `create`/editorial `update` rejection,
unknown entity/predicate rejection, missing Source/Evidence rejection,
duplicate Evidence rejection, duplicate slot rejection, and deterministic
child key equality for two identical requests.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticProposalPlannerTest.php`

Expected: FAIL because the Article preflight/planner classes do not exist.

- [ ] **Step 3: Write minimal implementation**

Use registries and repository reads only. Do not parse editorial prose. Do not
call `PostKnowledgeLinkService`, `V2MigrationService`, `GraphService::create`,
Knowledge mutators or any repository write. Preserve lower-level diagnostic
codes in the preflight result.

- [ ] **Step 4: Run test to verify it passes**

Run the same unit command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Article public/wp-content/plugins/nhk-core/src/Domain/Article public/wp-content/plugins/nhk-core/src/Domain/Governance/CommandCanonicalizer.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticProposalPlannerTest.php
git commit -m "feat: preflight Article semantic reconciliation"
```

### Task 5: Implement the reconcile coordinator and Governed Apply adapter

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestCoordinator.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/ControlledArticleApply.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestCoordinatorTest.php`

**Interfaces:**
- `ArticleIngestCoordinator::execute(array $input): ArticleOperationReceipt`.
- `ControlledArticleApply::apply(string $proposalId): array` delegates directly
  to existing `ControlledApplyService::apply()`.
- Coordinator dependencies are receipt repository, editorial state reader,
  preflight, planner, Governance service, dependency repository,
  ControlledApply service, proposal repository and verification reader.

The coordinator algorithm is:

1. Canonicalize request and reserve/replay receipt.
2. Reject `create` and editorial `update` as `UNSUPPORTED_OPERATION` before
   reading or writing a WP mutation.
3. Require `reconcile`, parse runtime blog ID/Post ID and require Post ID 55.
4. Read and store initial editorial token.
5. Run semantic preflight.
6. Create or reuse deterministic child proposals through `GovernanceService`.
7. Submit only new DRAFT proposals. Return `GOVERNANCE_PENDING` if approval is
   absent or proposal state is not eligible.
8. Apply only approved/eligible children. Continue missing children after a
   retry; never compensate already applied children.
9. Invoke verification and persist terminal/non-terminal receipt.

- [ ] **Step 1: Write the failing test**

Write one test per behavior: unsupported create/update, Post 55 success with
no editor writer dependency call, same receipt replay, pending approval,
already-applied child reuse, partial apply continuation, and direct Graph/
PostKnowledgeLinkService non-use.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestCoordinatorTest.php`

Expected: FAIL because the coordinator and apply adapter do not exist.

- [ ] **Step 3: Write minimal implementation**

Keep stage transitions explicit. Catch only typed domain/infrastructure errors
needed to map an outcome; preserve the original lower-level code in failure
details. Never turn unavailable runtime or malformed data into empty success.

- [ ] **Step 4: Run test to verify it passes**

Run the same unit command, then the existing Governance unit suite. Expected:
all pass with no changed existing assertions.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Article public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestCoordinatorTest.php
git commit -m "feat: coordinate governed Article reconciliation"
```

### Task 6: Add semantic/editorial read-back verification and diagnostics

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleVerificationReader.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleDiagnosticReader.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Article/ArticleVerificationReader.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Article/ArticleDiagnosticReader.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php` only to add a bounded receipt lookup, not a CMS
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleVerificationReaderTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleDiagnosticReaderTest.php`

**Interfaces:**
- Verification compares initial/final editorial state token, Post ID/status/
  title/body/excerpt/slug/permalink/revision state, semantic proposal state,
  applied results, Graph direction and duplicate constraints.
- Diagnostic read returns receipt, WP target, preflight result, child proposal
  state, apply attempts, last failure and retry eligibility without body.

- [ ] **Step 1: Write the failing test**

Test unchanged Post 55 state returns success, any changed editorial field returns
`VERIFICATION_FAILED`, missing semantic result returns failure, and diagnostic
output contains no full body.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleVerificationReaderTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleDiagnosticReaderTest.php`

Expected: FAIL because verification and diagnostics do not exist.

- [ ] **Step 3: Write minimal implementation**

Use read-only repository methods and existing proposal/apply repositories. Do
not add Article canonical storage, body projection, or direct Graph mutation.

- [ ] **Step 4: Run test to verify it passes**

Run the same unit command and existing Admin/Governance tests. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Article public/wp-content/plugins/nhk-core/src/Contracts/Article public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleVerificationReaderTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleDiagnosticReaderTest.php
git commit -m "feat: verify and diagnose Article reconciliation"
```

### Task 7: Expose coordinated MCP preflight and execute/resume abilities

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpArticleIngestHandler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php` only for the approved coordinated abilities
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/GovernanceCapabilities.php` to add the Article-specific `nhk_ingest_articles` capability
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` to wire dependencies
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpArticleContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`

**Interfaces:**
- Add `nhk.article.preflight` as read-only and `nhk.article.ingest` as a
  governed mutation in the existing `nhk.*` catalog.
- `nhk.article.ingest` accepts the Phase 1 input contract and only
  `intent=reconcile`; create/editorial update return `UNSUPPORTED_OPERATION`.
- Repeating the same idempotency key resumes the receipt; no separate resume
  tool exists.
- MCP capability checks execute before coordinator mutation. Approval remains
  mandatory and cannot be bypassed by the Article capability.

- [ ] **Step 1: Write the failing test**

Assert exact catalog schemas, read/mutation classification, no generic WP write
tool, unauthenticated denial, create/update fail-closed, preflight read-only,
same-key resume and Article capability approval enforcement.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/McpArticleContractTest.php public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`

Expected: FAIL because the catalog has no Article entries or handler.

- [ ] **Step 3: Write minimal implementation**

Follow current catalog/transport callback patterns. Do not expose low-level
proposal/Graph/Knowledge sequence as an Article completion shortcut. Keep
generic existing MCP tools unchanged.

- [ ] **Step 4: Run test to verify it passes**

Run the focused MCP tests and existing `McpContractTest`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Mcp public/wp-content/plugins/nhk-core/src/Application/Governance/GovernanceCapabilities.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/McpArticleContractTest.php public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php
git commit -m "feat: expose coordinated Article MCP abilities"
```

### Task 8: Add guarded Post 55 acceptance coverage and final verification

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Integration/ArticleIngestPost55ReconciliationIntegrationTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Integration/ArticleIngestRetryIntegrationTest.php`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

**Interfaces:**
- The fixture creates an existing published Post in `nhk_v3_test`, captures
  editorial state, runs reconcile, and removes only its own test fixture under
  the existing guarded test policy.
- No production Post 55 is read or mutated by the acceptance test.

- [ ] **Step 1: Write the failing test**

Cover exact Post ID preservation in a fixture-equivalent flow, unchanged status,
title, body, excerpt, slug, permalink and revision state, semantic reuse,
governed relation mutation, receipt replay, pending proposal retry, partial
apply continuation, invalid registry input and fail-closed create/update.

- [ ] **Step 2: Run test to verify it fails**

Run: `NHK_WP_TEST_PATH=public vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Integration/ArticleIngestPost55ReconciliationIntegrationTest.php public/wp-content/plugins/nhk-core/tests/Integration/ArticleIngestRetryIntegrationTest.php`

Expected: FAIL on missing coordinator/runtime wiring, or report the existing
WordPress/DB bootstrap blocker without changing data.

- [ ] **Step 3: Write minimal implementation**

Complete only the coordinator/runtime wiring and fixture support named in this
plan. Do not add production Post 55 special cases, UUID constants, migration
calls from the coordinator, or editorial write code.

- [ ] **Step 4: Run test to verify it passes**

Run focused Article tests, the complete unit suite, guarded integration suite,
Composer lint, `git diff --check`, and the repository secret review. Record
actual counts and any infrastructure blocker; do not convert blocked runtime
into success.

- [ ] **Step 5: Update execution state and commit**

Record Phase 1 scope, test evidence, receipt migration version, MCP abilities,
known blockers and the explicit stop before production reconciliation. Then:

```bash
git add public/wp-content/plugins/nhk-core/tests/Integration/ArticleIngestPost55ReconciliationIntegrationTest.php public/wp-content/plugins/nhk-core/tests/Integration/ArticleIngestRetryIntegrationTest.php docs/architecture/V3_EXECUTION_STATE.md
git commit -m "test: verify Article reconciliation safety boundary"
```

## Plan self-review checklist

- [ ] Every approved Phase 1 requirement maps to a task: receipt, reconcile,
  Post 55 preservation, outcome contract, deterministic idempotency, governed
  semantic apply, diagnostics, MCP, TDD and fail-closed create/update.
- [ ] No task calls WordPress editorial create/update/publish.
- [ ] No task calls migration/import, direct Graph mutation or direct
  `PostKnowledgeLinkService` mutation.
- [ ] No task stores the Article body in receipt or semantic storage.
- [ ] No task adds Article Authority/entity/endpoint/predicate or generic
  Governance operation.
- [ ] Existing lower-level error details remain preserved.
- [ ] Every production-code task starts with a failing test and names the
  exact verification command.
- [ ] The pre-existing untracked MCP plan is never staged by these tasks.
- [ ] Production Post 55 reconciliation remains a separate human-approved
  packet after Phase 1 test acceptance.
