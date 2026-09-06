# NHK V3 Admin Governance Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the monolithic NHK V3 WordPress Admin operational page with an accessible, Vietnamese-first Governance Workspace that consumes existing capability, repository and application boundaries and proves truthful proposal lifecycle/read-back behavior.

**Architecture:** Keep WordPress Admin as the host and extract presentation/controller responsibilities into focused classes. Read and mutation requests continue through existing application services, REST routes and repositories; the browser layer only renders capability/diagnostic projections and dispatches already-authorized actions. Use progressive enhancement with small plugin-owned CSS/JS assets and server-rendered fallback states.

**Tech Stack:** PHP 8.1+ strict classes, WordPress Admin hooks/nonces/capabilities, existing NHK V3 repositories/application services, PHPUnit, vanilla JavaScript, CSS custom properties and semantic HTML.

**Spec:** `docs/superpowers/specs/2026-09-06-admin-control-plane-design.md`

## Global Constraints

- `Admin` is an orchestration/diagnostic surface, not a canonical owner.
- Semantic mutation remains Proposal → Human Approval → Eligibility → Controlled Apply → repository → audit.
- Unknown registry values, malformed UUIDs, ambiguous resolution, unavailable dependencies and infrastructure uncertainty fail closed.
- WordPress `wp_posts` remains the sole source of truth for editorial title, body, author, dates, categories, archives, homepage, search, RSS, sitemap and editorial URLs.
- Do not migrate/import legacy article bodies, mutate V2/staging/production, seed/backfill Graph/semantic data, merge identities or introduce new semantic vocabulary.
- Vietnamese-first copy; technical identifiers are secondary operator diagnostics.
- Preserve existing working-tree changes and do not use destructive git restore/reset commands.
- Each task runs focused tests, PHP lint where applicable, `git diff --check` and a secret review before its checkpoint.

---

### Task 1: Characterize the current Admin contract and extract stable view models

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkspaceViewModel.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminDiagnosticPresenter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AdminWorkspaceViewModelTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AdminDiagnosticPresenterTest.php`

**Interfaces:**
- `AdminWorkspaceViewModel::fromHealth(array $health, array $capabilities, array $counts): array` returns only presentation data and preserves unavailable values as explicit state objects.
- `AdminDiagnosticPresenter::present(string $code, array $context = []): array` returns `code`, `severity`, `title`, `message`, `remediation` and `overridable`; unknown codes return `severity=system_blocked` and `overridable=false`.
- `AdminPage` consumes these projections and does not decide lifecycle eligibility itself.

- [ ] **Step 1: Write failing tests for diagnostic classification and state preservation.**

```php
public function test_unknown_diagnostic_is_system_blocked_and_not_overridable(): void
{
    $result = AdminDiagnosticPresenter::present('UNREGISTERED_DIAGNOSTIC');
    self::assertSame('system_blocked', $result['severity']);
    self::assertFalse($result['overridable']);
}

public function test_unavailable_health_is_not_rendered_as_zero(): void
{
    $view = AdminWorkspaceViewModel::fromHealth(['database' => null], [], []);
    self::assertSame('unavailable', $view['health']['database']['state']);
}
```

- [ ] **Step 2: Run the focused tests and verify they fail because the projections do not exist.**

Run: `vendor/bin/phpunit --filter 'Admin(WorkspaceViewModel|DiagnosticPresenter)Test'`

Expected: FAIL with missing class/method errors.

- [ ] **Step 3: Implement the two small projection classes.**

Use immutable array output, explicit state strings (`ready`, `empty`, `unavailable`, `blocked`, `conflict`, `uncertain`, `success`) and the existing diagnostic/registry definitions where available. Do not convert `null`, missing keys or exceptions into successful numeric values.

- [ ] **Step 4: Update `AdminPage` to consume projections without changing persistence behavior.**

Keep existing hooks, nonce creation, capability checks and repository calls. Move only labels/state rendering into the projections; leave domain operations in existing services.

- [ ] **Step 5: Run the focused tests and PHP lint.**

Run: `vendor/bin/phpunit --filter 'Admin(WorkspaceViewModel|DiagnosticPresenter)Test'` and `find public/wp-content/plugins/nhk-core/src/Infrastructure/Admin -name '*.php' -print0 | xargs -0 -n1 php -l`

Expected: PASS and no PHP syntax errors.

- [ ] **Step 6: Commit the extracted view-model boundary.**

```bash
git add public/wp-content/plugins/nhk-core/src/Infrastructure/Admin public/wp-content/plugins/nhk-core/tests/Unit/AdminWorkspaceViewModelTest.php public/wp-content/plugins/nhk-core/tests/Unit/AdminDiagnosticPresenterTest.php
git commit -m "refactor: extract admin presentation state"
```

### Task 2: Build the Admin shell and capability-aware navigation

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminShell.php`
- Create: `public/wp-content/plugins/nhk-core/assets/admin.css`
- Create: `public/wp-content/plugins/nhk-core/assets/admin.js`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/AdminShellTest.php`

**Interfaces:**
- `AdminShell::render(string $activeWorkspace, array $workspaceDefinitions, callable $content): void` renders semantic `nav`, `main`, breadcrumbs and a skip link.
- A workspace definition contains `slug`, `label`, `description`, `read_capability`, `write_capability`, `available` and `reason`; the shell never invents capability availability.
- `admin.js` handles disclosure, fetch status and focus restoration only; it never constructs semantic payloads.

- [ ] **Step 1: Write failing tests for navigation visibility and accessible landmarks.**

```php
public function test_workspace_without_capability_is_read_only_with_reason(): void
{
    $definitions = AdminShell::workspaceDefinitions(['manage_options' => true]);
    self::assertSame('read_only', $definitions['governance']['mode']);
    self::assertNotSame('', $definitions['governance']['reason']);
}

public function test_shell_contains_skip_link_navigation_and_main_landmarks(): void
{
    ob_start();
    AdminShell::render('governance', ['governance' => ['label' => 'Governance', 'mode' => 'read_only']], static function (): void { echo '<p>Nội dung</p>'; });
    $html = (string) ob_get_clean();
    self::assertStringContainsString('href="#nhk-admin-main"', $html);
    self::assertStringContainsString('<nav', $html);
    self::assertStringContainsString('<main id="nhk-admin-main"', $html);
}
```

- [ ] **Step 2: Run the focused test and verify failure.**

Run: `vendor/bin/phpunit --filter AdminShellTest`

Expected: FAIL because the shell has not been implemented.

- [ ] **Step 3: Implement the shell and enqueue assets only on NHK V3 screens.**

Use `admin_enqueue_scripts`, `wp_register_style`, `wp_enqueue_style`, `wp_register_script` and a localized nonce/rest URL object. Keep keyboard focus visible, use a non-color-only active state, respect `prefers-reduced-motion`, and keep mobile navigation usable without hover.

- [ ] **Step 4: Replace the current one-page heading structure with shell routing while retaining current panels as temporary content.**

The change must preserve existing URLs and form/action names. A user lacking a write capability sees an explanation and read-only controls; no action is silently removed from the audit model.

- [ ] **Step 5: Run tests, lint and diff checks.**

Run: `vendor/bin/phpunit --filter AdminShellTest`, `find public/wp-content/plugins/nhk-core/src/Infrastructure/Admin -name '*.php' -print0 | xargs -0 -n1 php -l`, `git diff --check`

Expected: PASS, no syntax errors and no whitespace errors.

- [ ] **Step 6: Commit the shell.**

```bash
git add public/wp-content/plugins/nhk-core/src/Infrastructure/Admin public/wp-content/plugins/nhk-core/assets public/wp-content/plugins/nhk-core/tests/Unit/AdminShellTest.php
git commit -m "feat: add accessible NHK admin shell"
```

### Task 3: Implement the Governance proposal inbox and detail read model

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceWorkspacePage.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceWorkspaceQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceWorkspaceQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceWorkspacePageTest.php`

**Interfaces:**
- `GovernanceWorkspaceQuery::proposalDetail(string $proposalUuid): array` returns operator-safe `identity`, `lifecycle`, `binding`, `dependencies`, `eligibility`, `attempts`, `audit` and `availability` groups.
- `GovernanceWorkspaceQuery::inbox(array $filters = []): array` returns bounded rows with `items`, `total`, `filters` and explicit availability.
- `GovernanceWorkspacePage::renderInbox(array $query): void` and `renderDetail(array $detail): void` render HTML only; action URLs are generated from existing REST boundaries.

- [ ] **Step 1: Write failing tests for bounded inbox rows and complete detail groups.**

```php
public function test_detail_preserves_binding_and_apply_attempt_state(): void
{
    $detail = $this->query->proposalDetail($this->proposalUuid);
    self::assertArrayHasKey('content_fingerprint', $detail['binding']);
    self::assertArrayHasKey('dependency_fingerprint', $detail['binding']);
    self::assertSame('not_started', $detail['attempts']['last_state']);
}

public function test_unknown_proposal_is_empty_not_success(): void
{
    $detail = $this->query->proposalDetail('00000000-0000-4000-8000-000000000001');
    self::assertSame('empty', $detail['availability']['state']);
}
```

- [ ] **Step 2: Run the focused tests and verify failure.**

Run: `vendor/bin/phpunit --filter 'GovernanceWorkspace(Query|Page)Test'`

Expected: FAIL because the query/page classes are absent.

- [ ] **Step 3: Implement the query using existing `WpdbProposalRepository`, `WpdbApplyAttemptRepository`, `WpdbDependencyRepository`, `MigrationStatus` and existing governance/application read boundaries.**

Use strict UUID validation before repository access. Keep operational IDs in the technical disclosure group. Do not query raw Graph tables or infer eligibility from proposal state; eligibility remains a service/read boundary.

- [ ] **Step 4: Render the inbox and detail view.**

Use real table headers, row actions, `aria-current`, `aria-live` result region, semantic headings, disclosure for technical details, and exact Vietnamese labels. Render `PASS`, `OWNER_REVIEW_REQUIRED`, `SYSTEM_BLOCKED`, `CONFLICT`, `UNCERTAIN` and unavailable states distinctly. Do not show a generic “success” badge before read-back.

- [ ] **Step 5: Run focused tests and inspect generated HTML assertions.**

Run: `vendor/bin/phpunit --filter 'GovernanceWorkspace(Query|Page)Test'`

Expected: PASS; detail output contains binding, dependency, eligibility, attempts and audit sections.

- [ ] **Step 6: Commit the read-only Governance workspace.**

```bash
git add public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceWorkspacePage.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceWorkspaceQuery.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php public/wp-content/plugins/nhk-core/tests/Unit/GovernanceWorkspaceQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/GovernanceWorkspacePageTest.php
git commit -m "feat: add governance proposal workspace"
```

### Task 4: Replace raw action buttons with governed action state machine

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceActionPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceWorkspacePage.php`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin.js`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceActionPolicyTest.php`

**Interfaces:**
- `GovernanceActionPolicy::available(array $proposal, array $capabilities): array` returns each action with `visible`, `enabled`, `reason`, `method`, `url`, `requires_confirmation`.
- Browser actions submit the existing nonce plus server-returned binding; client failures are never converted to success.

- [ ] **Step 1: Write failing tests for lifecycle/capability matrix.**

```php
public function test_apply_is_disabled_without_approved_and_eligible_state(): void
{
    $actions = GovernanceActionPolicy::available(['state' => 'submitted', 'eligibility' => ['ready' => false]], ['nhk_apply_proposals' => true]);
    self::assertFalse($actions['apply']['enabled']);
    self::assertStringContainsString('approval', strtolower($actions['apply']['reason']));
}

public function test_system_blocked_has_no_override_action(): void
{
    $actions = GovernanceActionPolicy::available(['publication_outcome' => 'SYSTEM_BLOCKED'], ['nhk_apply_proposals' => true]);
    self::assertFalse($actions['override']['visible']);
}
```

- [ ] **Step 2: Run focused tests and verify failure.**

Run: `vendor/bin/phpunit --filter GovernanceActionPolicyTest`

Expected: FAIL because the policy class is absent.

- [ ] **Step 3: Implement the action policy from capability, lifecycle, eligibility and diagnostic state.**

Use the existing capability names and REST routes. Do not add a generic override action. Any unknown state or diagnostic returns disabled/system-blocked.

- [ ] **Step 4: Implement progressive-enhancement interactions.**

On click: disable duplicate submits, announce progress, parse JSON safely, render exact diagnostics, refresh detail only after a confirmed read-back result, restore focus to the action or result heading, and show an explicit uncertain state when the response cannot prove completion.

- [ ] **Step 5: Run tests and static checks.**

Run: `vendor/bin/phpunit --filter GovernanceActionPolicyTest`, `php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceActionPolicy.php`, `git diff --check`

Expected: PASS, no syntax or whitespace errors.

- [ ] **Step 6: Commit governed action behavior.**

```bash
git add public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceActionPolicy.php public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/GovernanceWorkspacePage.php public/wp-content/plugins/nhk-core/assets/admin.js public/wp-content/plugins/nhk-core/tests/Unit/GovernanceActionPolicyTest.php
git commit -m "feat: gate admin governance actions"
```

### Task 5: Verify Admin lifecycle parity and update execution evidence

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/V2_V3_PARITY_MATRIX.md` only if fresh evidence changes a dated status
- Test: existing `public/wp-content/plugins/nhk-core/tests/Integration/GovernedSemanticIngestIntegrationTest.php`
- Test: existing `public/wp-content/plugins/nhk-core/tests/Integration/OwnerPublicationOverrideIntegrationTest.php`
- Test: existing `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`

**Interfaces:**
- Admin and MCP must report the same capability/action eligibility and governed outcome vocabulary for the same proposal.
- Documentation records evidence and remaining environment gates; it does not upgrade a partial runtime to ready without read-back.

- [ ] **Step 1: Run the focused Admin unit suite and existing governance integration tests against the configured safe test environment.**

Run: `vendor/bin/phpunit --filter 'Admin|GovernedSemanticIngestIntegrationTest|OwnerPublicationOverrideIntegrationTest|McpTransportIntegrationTest'`

Expected: Admin unit tests pass; integration tests either pass with evidence or retain their existing explicit environment-gated failure classification.

- [ ] **Step 2: Run PHP lint over changed plugin files.**

Run: `find public/wp-content/plugins/nhk-core/src/Infrastructure/Admin public/wp-content/plugins/nhk-core/tests/Unit -name '*.php' -print0 | xargs -0 -n1 php -l`

Expected: no syntax errors.

- [ ] **Step 3: Run repository quality checks.**

Run: `git diff --check`, `rg -n '(sk_live|AKIA[0-9A-Z]{16}|BEGIN .*PRIVATE KEY|password\s*=)' public/wp-content/plugins/nhk-core/src/Infrastructure/Admin public/wp-content/plugins/nhk-core/assets docs/superpowers/specs/2026-09-06-admin-control-plane-design.md`

Expected: no whitespace errors and no secret matches.

- [ ] **Step 4: Record only fresh evidence in `V3_EXECUTION_STATE.md`.**

Add the Admin workspace status, exact test commands/results, unresolved runtime gates, and the fact that no semantic/public/live data was mutated. Preserve historical wording and do not rewrite unrelated rows.

- [ ] **Step 5: Review the diff and commit the checkpoint.**

```bash
git diff --stat
git diff --check
git status --short
git add docs/architecture/V3_EXECUTION_STATE.md
git commit -m "docs: record admin governance verification"
```
