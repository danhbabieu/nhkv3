# Governance Admin Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a server-side, secure WordPress Admin Governance queue for existing NHK V3 Proposal records with per-item and bulk lifecycle operations.

**Architecture:** Extend the existing `nhk-v3-governance` Workbench with a bounded read-only Proposal query and a POST-only Admin adapter. Actions pass snapshot revision/fingerprints through a small canonical action port into the existing Governance, Eligibility and Controlled Apply services; no queue code writes semantic tables or adds an MCP tool.

**Tech Stack:** PHP 8.1+, WordPress Admin hooks/forms/nonces/capabilities, `$wpdb` read queries, existing NHK V3 Governance services and repositories, PHPUnit, vanilla JavaScript/CSS already used by the Admin Workbench.

**Spec:** `docs/superpowers/specs/2026-09-10-governance-admin-queue-design.md`

## Global Constraints

- The queue record is `Proposal`; Claims are contextual data, not independently actionable queue records.
- Existing `ProposalState` values are the only supported statuses: `draft`, `submitted`, `approved`, `rejected`, `cancelled`, `superseded`, `applied`.
- The SQL path is read-only, bounded, allowlisted and server-side; it never updates Governance tables or performs per-row canonical lookups.
- Semantic durable mutations remain `Proposal → Human Approval → Eligibility → Controlled Apply → canonical owner → durable audit`.
- Admin is a control-plane adapter and never calls repository `save`, direct SQL mutation, generic WordPress writers or a second semantic store.
- Every mutation is POST-only, capability-checked at entry and handler, nonce-protected, sanitized, UUID-validated and fail-closed on stale snapshots.
- `nhk.capture.ingest` remains the only normal new-content entry point; no new MCP/Ability/operator writer is registered.
- Vietnamese-first labels and honest empty/unavailable/blocked/conflict/uncertain states are required.
- No migration, semantic seed/backfill, remote environment, deployment or production/staging/V2 data operation is allowed.

---

### Task 1: Add the bounded Proposal queue query

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Governance/GovernanceQueueQuery.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/WpdbGovernanceQueueQuery.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueQueryTest.php`

**Interfaces:**
- `GovernanceQueueQuery::page(array $filters = []): array` returns `availability`, `items`, `total_items`, `total_pages`, `page`, `per_page` and normalized `filters`.
- `WpdbGovernanceQueueQuery::__construct(?object $database = null)` uses the injected database or global `$wpdb`.
- Normalized filters are `search`, `status`, `type`, `order_by`, `order`, `page` and `per_page`; defaults are empty status/type/search, `updated`, `desc`, page `1`, per-page `20`; per-page is clamped to `100`.
- Every item includes `proposal_id`, `entity_type`, `operation`, `subject_id`, `target_uuid`, `name`, `summary`, `status`, `status_label`, `provenance_summary`, `created_at`, `updated_at`, `revision`, `content_fingerprint` and `dependency_fingerprint`.

- [ ] **Step 1: Write failing query tests.**

Create a recording `$wpdb` fake that captures prepared SQL and returns fixture rows for `get_results()`/`get_var()`. Use valid v7 UUIDs and JSON payloads containing `name`, `text`, `provenance`, `source` and `subject_id`. Add tests with these names and assertions:

```php
public function test_exact_uuid_search_is_pushed_into_sql_and_returns_total(): void
{
    $page = $this->query->page(['search' => $this->proposalId]);
    self::assertSame(1, $page['total_items']);
    self::assertStringContainsString('HEX(proposal_uuid)', $this->db->lastPrepared);
}

public function test_subject_name_and_status_filters_are_server_side(): void
{
    $page = $this->query->page(['search' => 'Vertical Brand', 'status' => 'submitted']);
    self::assertSame('submitted', $page['filters']['status']);
    self::assertStringContainsString('command_json', $this->db->lastPrepared);
    self::assertStringContainsString('state', $this->db->lastPrepared);
}

public function test_invalid_sort_falls_back_to_updated_desc_with_internal_id_tiebreaker(): void
{
    $this->query->page(['order_by' => 'drop_table', 'order' => 'sideways']);
    self::assertSame('updated', $this->db->lastFilters['order_by']);
    self::assertStringContainsString('updated_at DESC', $this->db->lastPrepared);
    self::assertStringContainsString('id DESC', $this->db->lastPrepared);
}

public function test_page_boundaries_and_total_pages_are_database_backed(): void
{
    $page = $this->query->page(['page' => 3, 'per_page' => 2]);
    self::assertSame(3, $page['page']);
    self::assertSame(2, $page['per_page']);
    self::assertSame(3, $page['total_pages']);
    self::assertStringContainsString('LIMIT 2 OFFSET 4', $this->db->lastPrepared);
}
```

Also cover partial UUID, ascending/descending created/name/status ordering, type filtering, stable `id` secondary ordering, empty result and malformed JSON returning a blocked/unavailable diagnostic instead of a false success.

- [ ] **Step 2: Run the focused tests and verify the expected RED failure.**

Run:

```bash
vendor/bin/phpunit --filter GovernanceQueueQueryTest
```

Expected: failure because the contract and implementation do not exist yet. Fix only test-fixture mistakes if the failure is a PHP error unrelated to the missing implementation.

- [ ] **Step 3: Implement the query contract and SQL implementation.**

Use `ProposalState::cases()` for status validation and the current executable entity vocabulary: `CanonicalEntityTypeCatalog` types plus `knowledge`, `source`, `evidence`, `media`, `video`, `wp_post` and `relation`. Use explicit maps for sort expressions:

```php
$sorts = [
    'created' => 'created_at',
    'updated' => 'updated_at',
    'name' => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(command_json, '$.name')), JSON_UNQUOTE(JSON_EXTRACT(command_json, '$.title')), JSON_UNQUOTE(JSON_EXTRACT(command_json, '$.text')), JSON_UNQUOTE(JSON_EXTRACT(command_json, '$.claim_text')), entity_type)",
    'id' => 'proposal_uuid',
    'status' => 'state',
];
```

Search must use prepared `LIKE` clauses for `HEX(proposal_uuid)`, `target_uuid` where supported, `entity_type`, and `command_json`; never concatenate raw request values. Build the same `WHERE` for `COUNT(*)` and item queries. Parse only allowlisted payload keys, map status labels in Vietnamese, and summarize provenance/source without exposing private blobs.

- [ ] **Step 4: Run focused query tests and static checks.**

Run:

```bash
vendor/bin/phpunit --filter GovernanceQueueQueryTest
php -l public/wp-content/plugins/nhk-core/src/Contracts/Governance/GovernanceQueueQuery.php
php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/WpdbGovernanceQueueQuery.php
git diff --check
```

Expected: focused tests pass, both files lint, and diff check is clean.

- [ ] **Step 5: Commit the query slice.**

```bash
git add public/wp-content/plugins/nhk-core/src/Contracts/Governance/GovernanceQueueQuery.php public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/WpdbGovernanceQueueQuery.php public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueQueryTest.php
git commit -m "feat: add bounded governance queue query"
```

### Task 2: Expose one reusable canonical Governance runtime port

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Governance/GovernanceActionPort.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Governance/CanonicalGovernanceActionPort.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntime.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceActionPortTest.php`

**Interfaces:**
- `GovernanceActionPort` exposes `find(string $id): ?Proposal`, `submit(string $id): Proposal`, `approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal`, `reject(string $id, string $actor): Proposal`, `eligibility(string $id): EligibilityResult` and `apply(string $id): array`.
- `CanonicalGovernanceActionPort` delegates those methods to the existing `ProposalRepository`, `GovernanceService`, `ProposalEligibilityService` and `ControlledApplyService`; it contains no lifecycle decisions and no SQL.
- `GovernanceRuntime` is a readonly container for the four canonical services/repository needed by Admin and REST wiring.
- `GovernanceRuntimeFactory::fromWordPress(object $wpdb): GovernanceRuntime` extracts the current Plugin wiring for Authority, Graph, Media, Video, Knowledge, dependencies, executor, audit, transaction, eligibility and canonical read-back without changing operation semantics.

- [ ] **Step 1: Write failing port and wiring tests.**

Use a real `GovernanceService` with `InMemoryProposalRepository` for delegation tests, a fake eligibility reader and a controlled-apply test double behind the declared port seam. Assert that `approve`, `reject`, `eligibility` and `apply` reach the corresponding canonical dependency and return its result. Add a source architecture assertion that the Admin port contains no `$wpdb->query`, `INSERT`, `UPDATE`, `DELETE`, `save(` or `create(`.

```php
public function test_canonical_port_delegates_approval_and_rejection_to_governance_service(): void
{
    $port = $this->portWithDraftProposal();
    self::assertSame('approved', $port->approve($this->id, $this->content, $this->dependency, '7')->state->value);
    self::assertSame('rejected', $this->portWithDraftProposal()->reject($this->id, '7')->state->value);
}
```

- [ ] **Step 2: Run the tests and verify RED.**

Run `vendor/bin/phpunit --filter GovernanceActionPortTest`. Expected: missing class/interface failures.

- [ ] **Step 3: Implement the port and extract the shared runtime composition.**

Move only the existing dependency construction used by `GovernanceApi`/`ControlledApplyService` into `GovernanceRuntimeFactory`; preserve `collectorFacetExecutor`, `CanonicalApplyReadBackVerifier`, `WpdbTransactionManager`, dependency validation, audit and all current executors. Replace the duplicated local construction in the `rest_api_init` closure with the factory result, and make the Admin page request the same factory lazily. Do not add a new operation, entity type, predicate, REST route or MCP catalog entry.

- [ ] **Step 4: Run port tests, relevant Governance tests, lint and diff checks.**

```bash
vendor/bin/phpunit --filter 'GovernanceActionPortTest|GovernanceCoreTest|GovernanceApplyContractTest'
find public/wp-content/plugins/nhk-core/src/Application/Governance public/wp-content/plugins/nhk-core/src/Infrastructure/Governance -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
```

Expected: no new failures, no syntax errors and no whitespace errors.

- [ ] **Step 5: Commit the canonical port/wiring slice.**

```bash
git add public/wp-content/plugins/nhk-core/src/Contracts/Governance/GovernanceActionPort.php public/wp-content/plugins/nhk-core/src/Application/Governance/CanonicalGovernanceActionPort.php public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntime.php public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/GovernanceActionPortTest.php
git commit -m "refactor: share canonical governance runtime"
```

### Task 3: Implement secure single and bulk action orchestration

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueActionServiceTest.php`

**Interfaces:**
- `GovernanceQueueActionService::__construct(GovernanceActionPort $port, callable $can, callable $actor)`.
- `execute(string $action, string $id, array $snapshot = []): array` returns `ok`, `proposal_id`, `action`, `state`, `reason` and optional `result`.
- `bulk(string $action, array $items): array` returns `selected`, `succeeded`, `failed`, `failures` and `results`; each item includes its own `proposal_id` and reason.
- Allowed actions are `submit`, `approve`, `reject`, `apply`; unknown actions, invalid UUIDs, missing proposals, missing capabilities and stale revision/fingerprint snapshots fail closed before mutation.

- [ ] **Step 1: Write failing action tests.**

Create a `RecordingGovernanceActionPort` test double that stores calls and can return approved, rejected, applied, ineligible, stale and missing outcomes. Cover:

```php
public function test_bulk_approve_continues_after_one_missing_item(): void
{
    $result = $this->service->bulk('approve', [
        $this->snapshot($this->firstId),
        ['proposal_id' => '00000000-0000-4000-8000-000000000001', 'revision' => 1],
        $this->snapshot($this->secondId),
    ]);
    self::assertSame(3, $result['selected']);
    self::assertSame(2, $result['succeeded']);
    self::assertSame(1, $result['failed']);
    self::assertSame('00000000-0000-4000-8000-000000000001', $result['failures'][0]['proposal_id']);
}

public function test_apply_delegates_eligibility_and_preserves_partial_failure(): void
{
    $result = $this->service->bulk('apply', [$this->eligibleSnapshot, $this->ineligibleSnapshot]);
    self::assertSame(1, $result['succeeded']);
    self::assertSame('NOT_APPROVED', $result['failures'][0]['reason']);
    self::assertSame(['apply'], $this->port->callsFor($this->eligibleId));
}
```

Also cover review/read-only behavior through `find`, approve/reject/apply lifecycle restrictions, stale revision, stale content/dependency fingerprints, repeated idempotent apply result, invalid action, invalid identifier and capability denial. For `apply`, the service must call `GovernanceActionPort::eligibility()` for each item before `GovernanceActionPort::apply()` and report the returned reason codes per item; `ControlledApplyService::apply()` remains the final canonical recheck. Ensure one failed item never removes an independent success.

- [ ] **Step 2: Run the focused tests and verify RED.**

Run `vendor/bin/phpunit --filter GovernanceQueueActionServiceTest`. Expected: missing service failure.

- [ ] **Step 3: Implement minimal action orchestration.**

Reload each proposal through the port, compare `revision` and for approval/apply compare posted `content_fingerprint` and `dependency_fingerprint` with the canonical Proposal. Call the port only after the comparison. For `apply`, call `eligibility()` and stop that item with its exact reason codes unless `ready === true`, then call `apply()`; the canonical Controlled Apply service rechecks eligibility inside its transaction. Capability mapping is `submit → nhk_submit_proposals`, `approve/reject → nhk_approve_proposals`, `apply → nhk_apply_proposals`. Catch each item exception into a bounded reason/message result; do not catch around the whole bulk loop.

- [ ] **Step 4: Run focused action tests and static bypass scans.**

```bash
vendor/bin/phpunit --filter GovernanceQueueActionServiceTest
php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php
rg -n '\$wpdb->query|INSERT INTO|UPDATE .* SET|DELETE FROM|->save\(|->create\(' public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php
git diff --check
```

Expected: tests pass; the bypass scan has no matches; diff check is clean.

- [ ] **Step 5: Commit the action slice.**

```bash
git add public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueActionService.php public/wp-content/plugins/nhk-core/tests/Unit/GovernanceQueueActionServiceTest.php
git commit -m "feat: add governed admin queue actions"
```

### Task 4: Replace the placeholder Governance workspace with the Admin queue

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueAdminPage.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueRenderer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchPage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/GovernanceQueueAdminPageTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/GovernanceQueueRendererTest.php`

**Interfaces:**
- `GovernanceQueueAdminPage::register(): void` registers `admin_post_nhk_governance_queue_action` and `admin_post_nhk_governance_queue_bulk` plus the existing `nhk-v3-governance` renderer.
- `GovernanceQueueAdminPage::render(): void` requires `nhk_view_governance`, reads normalized GET filters, calls `GovernanceQueueQuery::page()` and renders the result.
- `GovernanceQueueAdminPage::handleAction(): void` and `handleBulk(): void` accept POST only, verify nonce `nhk_governance_queue_action`, check capability again, sanitize/validate input, call the action service, then redirect with encoded result data.
- `GovernanceQueueRenderer::render(array $page): void` emits escaped table markup, filters, bulk controls, pagination, row forms and notices; it never calls a domain writer.

- [ ] **Step 1: Write failing Admin rendering and handler tests.**

Use existing WordPress Admin test stubs/patterns from `tests/Unit/Admin` and inject a fake query/action service into the page. Assert:

```php
public function test_queue_renders_current_page_selection_and_required_columns(): void
{
    $html = $this->renderQueue($this->pageWithTwoItems());
    foreach (['Duyệt dữ liệu', 'select-all', 'proposal_id', 'Loại', 'Chủ thể / tên', 'Tóm tắt', 'Trạng thái', 'Nguồn', 'Ngày tạo', 'Cập nhật', 'name="bulk_action"'] as $needle) {
        self::assertStringContainsString($needle, $html);
    }
    self::assertStringContainsString('method="post"', $html);
}

public function test_bulk_result_reports_partial_success_and_failed_ids(): void
{
    $html = $this->renderNotice(['selected' => 3, 'succeeded' => 2, 'failed' => 1, 'failures' => [['proposal_id' => $this->id, 'reason' => 'TARGET_REVISION_CHANGED']]]);
    self::assertStringContainsString('2', $html);
    self::assertStringContainsString($this->id, $html);
    self::assertStringContainsString('TARGET_REVISION_CHANGED', $html);
}
```

Also test GET rendering never calls the action service, nonce failure/capability denial stop handlers, invalid action/status/sort/ID fails closed, per-item action visibility follows every `ProposalState`, and output escapes a payload name containing `<script>`.

- [ ] **Step 2: Run focused Admin tests and verify RED.**

Run:

```bash
vendor/bin/phpunit --filter 'GovernanceQueue(AdminPage|Renderer)Test'
```

Expected: missing class or missing queue markup failures.

- [ ] **Step 3: Implement renderer, page handlers and Workbench wiring.**

Change the existing submenu title from `Duyệt` to `Duyệt dữ liệu` while keeping slug `nhk-v3-governance`. Replace the placeholder `listRecent(50)` block with the queue page. Include a “Xem chi tiết / Review” link, `Gửi duyệt` for drafts, `Approve`/`Từ chối` for draft/submitted, `Apply` for approved, and no invalid mutations for terminal states. Keep raw fingerprints/revision in hidden POST snapshot fields only; do not display them in normal columns.

Render status filters from `ProposalState::cases()`, sort links/forms from the exact five query sort keys, pagination through `admin_url('admin.php')`, and a current-page-only select-all checkbox. Use `wp_nonce_field`, `check_admin_referer`, `wp_safe_redirect`, `admin_url`, `sanitize_text_field`, `sanitize_key`, `absint`, `esc_attr`, `esc_html` and `esc_url` according to the existing WordPress patterns. Results must distinguish success, partial failure, blocked/conflict and unavailable states.

Update `admin-workbench.js` only for current-page select-all, bulk action confirmation and focus/result announcement. It must not build semantic payloads or add a network writer.

- [ ] **Step 4: Run focused Admin tests, PHP lint and bypass/security scans.**

```bash
vendor/bin/phpunit --filter 'GovernanceQueue(AdminPage|Renderer)Test'
find public/wp-content/plugins/nhk-core/src/Infrastructure/Admin -name '*.php' -print0 | xargs -0 -n1 php -l
rg -n '\$wpdb->query|INSERT INTO|UPDATE .* SET|DELETE FROM|->save\(|->create\(' public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueue*.php
git diff --check
```

Expected: focused tests pass, all Admin files lint, the bypass scan has no matches and diff check is clean.

- [ ] **Step 5: Commit the Admin queue slice.**

```bash
git add public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueAdminPage.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceQueueRenderer.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchPage.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchRegistry.php public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js public/wp-content/plugins/nhk-core/tests/Unit/Admin/GovernanceQueueAdminPageTest.php public/wp-content/plugins/nhk-core/tests/Unit/Admin/GovernanceQueueRendererTest.php
git commit -m "feat: add Vietnamese governance admin queue"
```

### Task 5: Prove MCP invariants and record verification evidence

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/McpGovernanceQueueExposureTest.php`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

**Interfaces:**
- The MCP regression test reads `McpToolCatalog::tools()` and `SingleEntryPointPolicy` and proves `nhk.capture.ingest` remains canonical, no queue/Admin writer is present, and the existing internal proposal tools remain internal-only.
- The control-plane documentation records the Admin queue as a lifecycle workspace only; it does not document a new content entry point or MCP surface.
- The execution-state checkpoint records supported query/actions, security boundary, canonical-service use, partial-failure behavior, unchanged Capture/MCP exposure, exact verification commands/results and any environment-only blocker.

- [ ] **Step 1: Write the MCP exposure regression test before documentation changes.**

```php
public function test_admin_queue_does_not_change_operator_catalog_or_capture_entry_point(): void
{
    $names = array_column(McpToolCatalog::tools(), 'name');
    self::assertContains(SingleEntryPointPolicy::CANONICAL_TOOL, $names);
    self::assertNotContains('nhk.governance.queue', $names);
    self::assertNotContains('nhk.admin.governance', $names);
    self::assertTrue(SingleEntryPointPolicy::isInternalOnly('nhk.proposal.apply'));
    self::assertSame('canonical', SingleEntryPointPolicy::surface(SingleEntryPointPolicy::CANONICAL_TOOL));
}
```

Add source assertions that queue files do not import `McpToolCatalog`, `McpAbilityRegistration`, or generic WordPress create/update/delete/publish helpers.

- [ ] **Step 2: Run the MCP test and relevant existing suites.**

```bash
vendor/bin/phpunit --filter 'McpGovernanceQueueExposureTest|McpContractTest|McpCapabilityManifestTest|AdminWorkbenchArchitectureTest'
```

Expected: pass, with any pre-existing environment skips preserved and no new operator exposure.

- [ ] **Step 3: Update only the current control-plane documentation and execution state.**

Add one dated Admin Queue subsection to `NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` stating that `Duyệt dữ liệu` lists existing Proposals with server-side search/filter/sort/pagination and calls canonical lifecycle services; explicitly restate Capture-only new submissions and no MCP surface change. Append a dated checkpoint to `V3_EXECUTION_STATE.md` after fresh verification; preserve older historical checkpoints.

- [ ] **Step 4: Regenerate and verify the local canonical MCP documentation snapshot.**

Because an ACTIVE canonical MCP document changes, run:

```bash
composer generate:mcp-docs
composer generate:mcp-docs
git diff --check
```

Verify the second generation is deterministic, inspect the manifest/hash diff, and do not deploy the snapshot. Include generated files only if the project generator changes tracked canonical output.

- [ ] **Step 5: Run final required checks for the feature.**

```bash
vendor/bin/phpunit --filter 'GovernanceQueue|McpGovernanceQueueExposureTest|AdminWorkbenchArchitectureTest|GovernanceCoreTest|GovernanceApplyContractTest|McpContractTest'
vendor/bin/phpunit --testsuite 'NHK Unit'
vendor/bin/phpunit --testsuite 'NHK Contract'
composer lint
composer validate --no-check-publish
git diff --check
rg -n '(sk_live|AKIA[0-9A-Z]{16}|BEGIN .*PRIVATE KEY|password\s*=|secret\s*=|token\s*=)' public/wp-content/plugins/nhk-core/src/Infrastructure/Admin public/wp-content/plugins/nhk-core/src/Application/Governance public/wp-content/plugins/nhk-core/src/Infrastructure/Governance docs/superpowers/specs/2026-09-10-governance-admin-queue-design.md docs/superpowers/plans/2026-09-10-governance-admin-queue.md
```

Record exact exit codes and counts. If Integration is attempted, use only guarded `nhk_v3_test`; never compensate with a remote database.

- [ ] **Step 6: Commit documentation and verification evidence.**

```bash
git add docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md docs/architecture/V3_EXECUTION_STATE.md public/wp-content/plugins/nhk-core/tests/Unit/McpGovernanceQueueExposureTest.php public/wp-content/plugins/nhk-core/resources/canonical-docs
git commit -m "docs: record governance admin queue verification"
```

After this task, request the whole-branch code review, fix any Critical/Important findings through the documented review loop, rerun verification, fetch `origin`, confirm fast-forward compatibility, push `origin main`, verify the pushed commit, and stop immediately. No server-side action is part of this plan.
