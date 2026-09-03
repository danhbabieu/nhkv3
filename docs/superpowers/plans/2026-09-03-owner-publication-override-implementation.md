# Owner Publication Override Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a truthful, retry-safe owner exception flow around the existing Article publication gate, with exactly `PASS`, `OWNER_REVIEW_REQUIRED` and `SYSTEM_BLOCKED`, durable append-only decisions, authenticated principal binding, native WordPress read-back, and minimal MCP exposure.

**Architecture:** Keep `ArticlePublicationGate` read-oriented and extend its result with registered diagnostic classifications and a deterministic blocker fingerprint. Add a dedicated `OwnerPublicationDecision` aggregate/repository and an application service that re-reads the Post, re-runs the gate, validates Post/token/policy/fingerprint/principal/expiry, then calls the existing `EditorialPostStore::publish()` and verifies the returned Post. `EditorialDraftGateway` remains the canonical MCP publication entry point and delegates to the owner-publication service; semantic Governance remains untouched.

**Tech Stack:** PHP 8.2+, WordPress native APIs, PHPUnit, existing PSR-4 `NHK\\Core` plugin autoload, `$wpdb`/`dbDelta`, existing JSON-RPC MCP transport and WordPress capability callbacks.

**Spec:** `docs/superpowers/specs/2026-09-03-owner-publication-override-design.md`

## Global Constraints

- Exactly three practical outcomes: `PASS`, `OWNER_REVIEW_REQUIRED`, `SYSTEM_BLOCKED`.
- `SYSTEM_BLOCKED` is never overridable.
- Owner approval expires after 30 minutes and binds Post ID, state token, policy version, blocker fingerprint and authenticated principal.
- Failed diagnostics remain failed after approval; no semantic mutation bypasses Governance.
- The dedicated decision repository is append-only and does not store Article body content.
- Native WordPress read-back is mandatory before success or public URL is returned.
- No Post 87/Sonodo special case, legacy body migration, V2/staging/production mutation, push or deploy.

---

### Task 1: Publication outcome and diagnostic registry

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticlePublicationOutcome.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/PublicationDiagnosticDefinition.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/PublicationDiagnosticRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticlePublicationGateResult.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/PublicationDiagnosticRegistryTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`

**Interfaces:**
- `ArticlePublicationOutcome::PASS`, `OWNER_REVIEW_REQUIRED`, `SYSTEM_BLOCKED` string enum.
- `PublicationDiagnosticDefinition::__construct(string $code, ArticlePublicationOutcome $classification, string $ownerMessage, string $remediationHint, string $policyVersion)` and `toArray(): array`.
- `PublicationDiagnosticRegistry::policyVersion(): string`, `definition(string $code): ?PublicationDiagnosticDefinition`, `classify(array $failedCodes): ArticlePublicationOutcome`, `fingerprint(array $failedCodes): string`.
- `ArticlePublicationGateResult` gains `outcome()`, `blockerFingerprint(string $policyVersion)`, and `toArray()` fields `outcome`, `policy_version`, `blocker_fingerprint`; `eligible` remains backward-compatible.

- [ ] **Step 1: Write the failing test**

```php
public function test_multiple_registered_quality_failures_have_one_review_outcome_and_stable_fingerprint(): void
{
    $gate = new ArticlePublicationGate();
    $result = $gate->check($this->draft(), $this->validEvidence(['real_image_requirements_met' => false, 'seo_projection_valid' => false]), $this->draft()->token);
    self::assertSame('OWNER_REVIEW_REQUIRED', $result->outcome()->value);
    self::assertSame($result->blockerFingerprint('owner-publication-v1'), $result->blockerFingerprint('owner-publication-v1'));
    self::assertContains('REAL_IMAGE_INCOMPLETE', $result->blockers);
    self::assertContains('SEO_PROJECTION_INVALID', $result->blockers);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'PublicationDiagnosticRegistryTest|ArticlePublicationGateTest'`

Expected: FAIL because the outcome enum, registry classification and gate fingerprint methods do not exist.

- [ ] **Step 3: Write minimal implementation**

Register every current gate code explicitly. Quality codes such as `REAL_IMAGE_INCOMPLETE`, `MEDIAUSAGE_INCOMPLETE`, `SEO_PROJECTION_INVALID`, `STRUCTURED_DATA_INCOMPLETE`, `INTERNAL_LINKS_INCOMPLETE`, `SEMANTIC_READBACK_UNVERIFIED` and `KNOWLEDGE_EVIDENCE_INCOMPLETE` use `OWNER_REVIEW_REQUIRED` only where the approved policy allows it. Identity, CAS, authorization, security, route, corruption, unknown and runtime diagnostics use `SYSTEM_BLOCKED`; warnings do not classify as blockers. Sort unique codes before hashing `policyVersion . ':' . implode('|', $codes)` with SHA-256.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'PublicationDiagnosticRegistryTest|ArticlePublicationGateTest'`

Expected: PASS, with existing gate tests still green.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Domain/Article public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php public/wp-content/plugins/nhk-core/tests/Unit/PublicationDiagnosticRegistryTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php
git commit -m "feat: classify article publication diagnostics"
```

### Task 2: Dedicated durable owner-decision aggregate and migration

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Article/OwnerPublicationDecision.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Article/OwnerPublicationDecisionRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Article/WpdbOwnerPublicationDecisionRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/OwnerPublicationDecisionMigration013.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/OwnerPublicationDecisionTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/WpdbOwnerPublicationDecisionRepositoryTest.php`

**Interfaces:**
- `OwnerPublicationDecision` constructor fields: `decisionId`, `idempotencyKey`, `requestFingerprint`, `wpPostId`, `decision`, `gateOutcome`, `diagnostics`, `overriddenDiagnosticCodes`, `blockerFingerprint`, `editorialStateToken`, `policyVersion`, `principalId`, `approvalProvenance`, `approvedAt`, `expiresAt`, `stage`, `publishAttempt`, `readback`, `finalOutcome`, `revision`; methods `isExpired(DateTimeImmutable $now): bool`, `bindingFingerprint(): string`, `toArray(): array`.
- Repository methods: `findByIdempotencyKey(string): ?OwnerPublicationDecision`, `findActiveApproval(int,string,string,string,string): ?OwnerPublicationDecision`, `create(OwnerPublicationDecision): OwnerPublicationDecision`, `append(OwnerPublicationDecision): OwnerPublicationDecision`.
- Migration 013 creates `{$wpdb->prefix}nhk_owner_publication_decisions` with unique `decision_id` and `idempotency_key`, Post/policy/token/fingerprint indexes, JSON columns for diagnostics/provenance/attempt/readback, and advances migration options to 13. `down()` is guarded to exact `nhk_v3_test` and empty table.

- [ ] **Step 1: Write the failing test**

```php
public function test_decision_is_append_only_and_expiry_is_thirty_minutes(): void
{
    $decision = OwnerPublicationDecision::approved('decision-uuid', 'publish-key', 87, 'token', 'fp', 'owner-1', '2026-09-03T10:00:00+00:00');
    self::assertFalse($decision->isExpired(new DateTimeImmutable('2026-09-03T10:29:59+00:00')));
    self::assertTrue($decision->isExpired(new DateTimeImmutable('2026-09-03T10:30:00+00:00')));
    self::assertArrayHasKey('overridden_diagnostic_codes', $decision->toArray());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'OwnerPublicationDecisionTest|WpdbOwnerPublicationDecisionRepositoryTest'`

Expected: FAIL because the aggregate, repository and migration do not exist.

- [ ] **Step 3: Write minimal implementation**

Validate UUID/fingerprint/state-token formats, positive Post ID, non-empty principal/policy/idempotency values and ISO UTC timestamps. Never include body/content fields. `create()` performs an insert-only operation; duplicate idempotency returns the original only when the request fingerprint matches, otherwise throws `OWNER_PUBLICATION_IDEMPOTENCY_CONFLICT`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'OwnerPublicationDecisionTest|WpdbOwnerPublicationDecisionRepositoryTest'`

Expected: PASS; run the guarded migration integration test only when `nhk_v3_test` is available.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Domain/Article/OwnerPublicationDecision.php public/wp-content/plugins/nhk-core/src/Contracts/Article/OwnerPublicationDecisionRepository.php public/wp-content/plugins/nhk-core/src/Infrastructure/Article/WpdbOwnerPublicationDecisionRepository.php public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/OwnerPublicationDecisionMigration013.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/OwnerPublicationDecisionTest.php public/wp-content/plugins/nhk-core/tests/Unit/WpdbOwnerPublicationDecisionRepositoryTest.php
git commit -m "feat: persist owner publication decisions"
```

### Task 3: Owner publication application service

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Article/PublicationPrincipal.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Article/OwnerPublicationService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/OwnerPublicationApplicationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/WordPress/EditorialDraftGateway.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/OwnerPublicationApplicationServiceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialPublicationWriterTest.php`

**Interfaces:**
- `PublicationPrincipal::__construct(string $id, string $channel, string $requestReference)` with `id()`, `channel()`, `requestReference()`.
- `OwnerPublicationService::request(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey, PublicationPrincipal $principal): array` and `approveAndPublish(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey, string $decisionId, PublicationPrincipal $principal, string $affirmation): array`.
- Return arrays always contain `outcome`, `diagnostics`, `policy_version`, `blocker_fingerprint`; successful results contain verified `post`, `state_token`, `public_url`, `decision`; review results contain `decision_id`, `expires_at`, `confirmation_question`; system-blocked results never contain an approval affordance.

- [ ] **Step 1: Write the failing test**

```php
public function test_owner_approval_publishes_with_exceptions_and_keeps_failed_codes(): void
{
    $result = $this->service()->approveAndPublish(1, $this->draft()->token, $this->validEvidence(['real_image_requirements_met' => false]), 'owner-publish', 'decision-id', new PublicationPrincipal('owner-1', 'mcp', 'turn-1'), 'Đăng.');
    self::assertSame('PASS', $result['outcome']);
    self::assertSame('published_with_exceptions', $result['final_outcome']);
    self::assertContains('REAL_IMAGE_INCOMPLETE', $result['diagnostics']);
    self::assertSame('publish', $result['post']['status']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'OwnerPublicationApplicationServiceTest|EditorialPublicationWriterTest'`

Expected: FAIL because no owner service or principal binding exists and the gateway currently returns `PUBLICATION_BLOCKED` for eligible failures.

- [ ] **Step 3: Write minimal implementation**

`request()` authenticates through the injected principal/capability callback, reads the exact Post, runs the gate and registry, and either publishes on `PASS`, returns a durable review decision on `OWNER_REVIEW_REQUIRED`, or returns `SYSTEM_BLOCKED`. `approveAndPublish()` requires exact affirmative text (`Đăng.`, `Vẫn đăng.`, `Publish.`), re-reads and re-gates, rejects changed Post/token/policy/fingerprint/principal/expired approval, records `APPROVAL_RECORDED`, calls `EditorialPostStore::publish()`, records `PUBLISH_ATTEMPTED`, reads back `publish`, then records `READBACK_VERIFIED` and final completion. A thrown publish/readback error is recorded as failed/uncertain and never returns a URL.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'OwnerPublicationApplicationServiceTest|EditorialPublicationWriterTest'`

Expected: PASS for PASS, review, owner, Post/token/fingerprint/policy/expiry/principal, system-blocked and retry cases.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Contracts/Article/PublicationPrincipal.php public/wp-content/plugins/nhk-core/src/Contracts/Article/OwnerPublicationService.php public/wp-content/plugins/nhk-core/src/Application/Article/OwnerPublicationApplicationService.php public/wp-content/plugins/nhk-core/src/Application/WordPress/EditorialDraftGateway.php public/wp-content/plugins/nhk-core/tests/Unit/OwnerPublicationApplicationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialPublicationWriterTest.php
git commit -m "feat: add owner publication exception flow"
```

### Task 4: MCP review/approval continuation and server wiring

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`

**Interfaces:**
- Add catalog tools `nhk.article.publish.review` and `nhk.article.publish.approve`; both use the existing `nhk_ingest_articles` capability, strict schemas, and no generic WordPress path.
- Review arguments: `post_id`, `expected_state_token`, `idempotency_key`, `evidence`; approval adds `decision_id`, `affirmation` and authenticated server principal context. Transport maps both to `OwnerPublicationService` and supplies `current_user_id()` plus MCP request/turn reference.

- [ ] **Step 1: Write the failing test**

```php
public function test_authenticated_owner_review_then_approval_is_exposed_through_mcp(): void
{
    $review = $this->call('nhk.article.publish.review', $this->reviewArguments(), true);
    self::assertSame('OWNER_REVIEW_REQUIRED', $review['structuredContent']['outcome']);
    self::assertArrayHasKey('decision_id', $review['structuredContent']);
    $approved = $this->call('nhk.article.publish.approve', $this->approvalArguments($review), true);
    self::assertSame('PASS', $approved['structuredContent']['outcome']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'McpContractTest|McpTransportIntegrationTest'`

Expected: FAIL because the two tools are absent from the catalog and transport match.

- [ ] **Step 3: Write minimal implementation**

Register strict schemas and descriptions, keep server-side capability/principal resolution mandatory, and serialize the application result identically for MCP review and approval. Reject missing/unauthenticated owner context as `SYSTEM_BLOCKED`; do not infer identity from the affirmation string.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'McpContractTest|McpTransportIntegrationTest'`

Expected: PASS where the integration runtime exists; otherwise report the exact existing WordPress/database runtime block separately.

- [ ] **Step 5: Commit**

```bash
git add public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php
git commit -m "feat: expose owner publication continuation in MCP"
```

### Task 5: Constitutional regression, execution-state evidence and full verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/OwnerPublicationConstitutionTest.php`

**Interfaces:**
- The Constitution test asserts the canonical text contains §14.2, the three outcomes, the 30-minute binding law, mandatory read-back and invariants 65–74.
- MCP documentation describes the two-stage review/approval flow and keeps generic WordPress publication separate.

- [ ] **Step 1: Write the failing test**

```php
public function test_owner_publication_invariants_are_present_in_the_sole_constitution(): void
{
    $constitution = file_get_contents(dirname(__DIR__, 4) . '/../../../../docs/constitution/NHK_V3_CONSTITUTION.md');
    self::assertIsString($constitution);
    foreach (['Owner Publication Override Law', '`PASS`', '`OWNER_REVIEW_REQUIRED`', '`SYSTEM_BLOCKED`', '30 minutes', 'mandatory before', '65.', '74.'] as $required) self::assertStringContainsString($required, $constitution);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter OwnerPublicationConstitutionTest`

Expected: FAIL until the Constitution amendment and implementation documentation are visible at the canonical paths.

- [ ] **Step 3: Write minimal implementation**

Record focused test counts, migration/runtime limitations, no-data-mutation evidence and exact unavailable runtime causes in execution state. Update MCP contract wording without inventing a second publication writer or claiming live Post 87 success.

- [ ] **Step 4: Run all verification gates**

Run, in order:

```bash
composer validate --no-check-publish
find public/wp-content/plugins/nhk-core/src public/wp-content/plugins/nhk-core/tests -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit tests/Unit/OwnerPublicationConstitutionTest.php tests/Unit/PublicationDiagnosticRegistryTest.php tests/Unit/OwnerPublicationDecisionTest.php tests/Unit/OwnerPublicationApplicationServiceTest.php tests/Unit/EditorialPublicationWriterTest.php tests/Unit/McpContractTest.php
vendor/bin/phpunit
composer preflight
git diff --check
```

Expected: focused tests, lint, Composer validation and diff check pass. Any WordPress bootstrap, integration database, frontend smoke or MCP wire limitation is reported as `RUNTIME_BLOCKED` with its exact cause; no unavailable runtime is called PASS.

- [ ] **Step 5: Commit**

```bash
git add docs/architecture/V3_EXECUTION_STATE.md docs/mcp/MCP_V3_CONTENT_OPERATIONS.md public/wp-content/plugins/nhk-core/tests/Unit/OwnerPublicationConstitutionTest.php
git commit -m "docs: record owner publication override verification"
```

## Self-review

- Spec coverage: Tasks 1–2 cover the three outcomes, registry, fingerprint, policy version, dedicated append-only persistence and expiry; Task 3 covers authenticated owner binding, stale approvals, publish state sequence, idempotency and mandatory read-back; Task 4 covers MCP review/approval and bypass prevention; Task 5 covers Constitution, documentation and all requested gates.
- Placeholder scan: no TODO, TBD, guessed existing class, or unbounded “handle edge cases” step remains; every new interface is named with exact signatures.
- Type consistency: Tasks 1–3 define the outcome/diagnostic/decision/principal types consumed by later tasks; Task 4 delegates only to the service signatures defined in Task 3.
- Architecture check: no Article Authority, Graph endpoint, semantic mutation path, public identity change, generic receipt replacement, Post-specific branch, or external publish is introduced.
