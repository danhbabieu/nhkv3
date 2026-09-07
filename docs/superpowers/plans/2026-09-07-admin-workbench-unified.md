# NHK V3 Admin Workbench thống nhất Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Xây một Admin Workbench thống nhất cho Nội dung/Video, Media, Tri thức, Duyệt và Hệ thống trên các service/query boundary hiện có.

**Architecture:** Một shell chung điều phối các shared presentation components; mỗi domain dùng adapter/view-model riêng. Mutation chỉ gọi REST/application boundary hiện có và luôn trình bày canonical/projection/frontend read-back độc lập.

**Tech Stack:** PHP 8.x, WordPress Admin, REST API, vanilla JavaScript, PHPUnit, existing NHK V3 repositories/services.

**Spec:** `docs/superpowers/specs/2026-09-07-admin-workbench-unified-design.md`

## Global Constraints

- Không thêm semantic type, endpoint type, predicate, operation, writer, migration hoặc seed.
- WordPress `wp_posts` giữ editorial truth; semantic mutation phải qua Governance.
- Không nhập UUID proposal/evidence/fingerprint/revision trong workflow thường ngày.
- Empty, unavailable, blocked, conflict và runtime failure phải phân biệt.
- Không thay đổi V2, staging, production hoặc dữ liệu semantic hiện có.

---

### Task 1: Shared Admin presentation models

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminListTable.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminStatusBadge.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminReadBackPanel.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminTechnicalDetails.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminDetailShell.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminSharedPresentationTest.php`

**Interfaces:**
- `AdminListTable::render(string $label, array $columns, array $rows, array $empty): void`
- `AdminStatusBadge::render(string $label, string $state, ?string $reason = null): void`
- `AdminReadBackPanel::render(array $layers): void`
- `AdminTechnicalDetails::render(array $details): void`
- `AdminDetailShell::render(string $title, string $description, callable $content): void`

- [ ] Write failing tests proving shared renderers escape values, preserve empty/error states, and put raw details in `<details>`.
- [ ] Run the focused PHPUnit test and verify it fails because the classes are absent.
- [ ] Implement the smallest presentation-only renderers with no repository or write calls.
- [ ] Run focused PHPUnit and verify it passes.
- [ ] Run the existing Admin architecture test.

### Task 2: Domain adapter view-models

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminContentAdapter.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminVideoAdapter.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminMediaAdapter.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminKnowledgeAdapter.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminGovernanceAdapter.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminDomainAdapterTest.php`

**Interfaces:**
- `AdminContentAdapter::tabs(): array`
- `AdminVideoAdapter::find(string $query): array`
- `AdminMediaAdapter::find(string $query): array`
- `AdminKnowledgeAdapter::find(string $query, string $tab): array`
- `AdminGovernanceAdapter::humanize(Proposal $proposal): array`

- [ ] Write failing tests for empty/unavailable results, video regression identity and Vietnamese governance summaries.
- [ ] Run focused tests and verify the expected missing-class failures.
- [ ] Implement adapters using existing repository `list`/`find` methods and existing service/query boundaries; never write SQL in adapters.
- [ ] Run focused tests and the static no-writer architecture test.

### Task 3: Unified menu and workspace routing

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchPage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminShell.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchArchitectureTest.php`

- [ ] Add failing assertions for exactly the user-facing menu labels and advanced-only raw tooling.
- [ ] Run the test and verify it fails against the current menu destinations.
- [ ] Register Tổng quan, Nội dung, Media, Tri thức, Duyệt, Hệ thống and Nâng cao with capability-aware links.
- [ ] Render the active workspace from `page`/tab query without exposing internal domain names as primary navigation.
- [ ] Run focused Admin tests and PHP lint.

### Task 4: Content and Video list/detail workspace

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/ContentVideoWorkspaceTest.php`

- [ ] Write failing static/UI tests for shared content tabs, title/external-ID/UUID search, player preview, target selection and read-back labels.
- [ ] Run the focused test and verify it fails against the advanced-only screen.
- [ ] Replace raw Video entry with list/detail markup and use the existing Video relation endpoint for guided relation creation.
- [ ] Add safe JS rendering for list/detail responses, blocker candidates and canonical/relation/projection/frontend outcomes.
- [ ] Verify the `truOChTNbwA` and canonical UUID constants are represented only as regression lookup/search fixtures, never seeded data.
- [ ] Run focused tests and JS syntax validation.

### Task 5: Media workspace

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/MediaWorkspaceTest.php`

- [ ] Write failing tests for thumbnail, asset/usage/provenance summaries, empty and unavailable states, and guided attachment boundary.
- [ ] Run the focused test and verify failure.
- [ ] Render Media list/detail using Media repository/service read boundaries and existing attachment bridge read-back.
- [ ] Add guided relation/attachment controls only when the existing capability and endpoint are present; otherwise show a Vietnamese blocker.
- [ ] Run focused tests, PHP lint and JS syntax validation.

### Task 6: Knowledge workspace

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/KnowledgeWorkspaceTest.php`

- [ ] Write failing tests for the shared search tabs Entity/Claim/Source/Evidence/Relation and technical-detail disclosure.
- [ ] Run the test and verify failure.
- [ ] Render read-only knowledge detail using Authority/Knowledge/Source/Evidence/Graph query boundaries; preserve direct-vs-derived labels.
- [ ] Add candidate selection and blocker copy without guessing ambiguous identities.
- [ ] Run focused tests and PHP lint.

### Task 7: Governance queue

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/GovernanceQueueTest.php`

- [ ] Write failing tests for user-facing queue states, human-readable diff, dependencies/blockers/evidence readiness and raw technical disclosure.
- [ ] Run the test and verify failure.
- [ ] Replace composer-first UI with queue-first UI; keep composer reachable only in Nâng cao.
- [ ] Wire existing Submit/Approve/Reject/Eligibility/Apply endpoints and refresh read-back after successful actions.
- [ ] Run focused tests and verify no new mutation path exists.

### Task 8: End-to-end verification and execution state

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchArchitectureTest.php`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Run complete Unit suite, PHP lint, Composer validation, JS syntax checks and `git diff --check`.
- [ ] Run guarded Integration if the exact permitted test database is available; classify infrastructure failures without converting them to empty results.
- [ ] Run secret review over the diff and verify no credentials, payload secrets or local env files are included.
- [ ] Update execution state with actual counts, failures, environment blockers and explicit no-mutation evidence.
- [ ] Re-read `docs/architecture/V2_V3_PARITY_MATRIX.md` before making any parity statement.
