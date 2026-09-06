# Admin Hybrid Workbench Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Use test-first development and verification-before-completion for every checkpoint.

**Goal:** Replace the NHK V3 Admin entry experience with a maintainable task-oriented Workbench while preserving existing canonical owners, Governance writers and technical tools.

**Architecture:** Add a pure registry/state presentation layer, a server-rendered dashboard shell and dedicated Admin assets. Keep the existing `AdminPage` as an advanced technical surface. Wire existing Dictionary and dossier diagnostics through the shared parent menu without creating new semantic writers.

**Tech Stack:** PHP 8.2+, WordPress Admin APIs, existing NHK V3 application/domain services, PHPUnit 11, CSS, small vanilla JavaScript.

---

## Task 1: Lock Admin navigation and state contracts with failing tests

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchRegistryTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchStateTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchArchitectureTest.php`
- Create: `.github/workflows/admin-workbench-verify.yml`

**Step 1: Write failing registry test**

Test the wished-for `NHK\Core\Infrastructure\Admin\AdminWorkbenchRegistry` API:

```php
$registry = new AdminWorkbenchRegistry();
$sections = $registry->sections();
self::assertSame(
    ['overview','content','media','video','knowledge','governance','dictionary','system','advanced'],
    array_column($sections, 'id')
);
self::assertSame('WordPress', $registry->section('content')['owner']);
self::assertSame('edit.php', $registry->section('content')['href']);
self::assertSame('upload.php', $registry->section('media')['href']);
self::assertSame('nhk_view_governance', $registry->section('governance')['capability']);
self::assertSame('nhk_curate_dictionary', $registry->section('dictionary')['capability']);
```

Also assert every section has unique `id`, non-empty Vietnamese label/description, owner and a safe destination, and that no section advertises a new generic semantic writer.

**Step 2: Write failing state test**

Test wished-for `AdminWorkbenchState`:

```php
$state = new AdminWorkbenchState([
    ['label' => 'Governance', 'value' => 'Approved', 'tone' => 'attention'],
    ['label' => 'Verification', 'value' => 'Unavailable', 'tone' => 'blocked'],
]);
self::assertSame(2, $state->count());
self::assertSame(1, $state->blockerCount());
self::assertSame('Approved', $state->rows()[0]['value']);
```

Verify invalid/unknown tones normalize to `neutral`, rows remain independent and `Unavailable` is not converted to an empty-success state.

**Step 3: Write failing architecture test**

Source-inspect the planned Admin classes and plugin wiring. Initially the test must fail because the production classes do not exist. Final assertions:

- `AdminWorkbenchPage.php`, `AdminWorkbenchRegistry.php`, `AdminWorkbenchState.php`, `AdminAssets.php` exist.
- They contain no `INSERT`, `UPDATE`, `DELETE`, `$wpdb->query`, repository `save(` or `create(` semantic shortcut.
- `Plugin.php` wires `AdminWorkbenchPage` as the primary Admin menu.
- New presentation code does not embed a `<style>` or large inline `<script>` block.
- `assets/admin/admin-workbench.css` and `assets/admin/admin-workbench.js` exist.

**Step 4: Add CI workflow**

Create `.github/workflows/admin-workbench-verify.yml` with:

- `pull_request` to `main` when Admin/plugin/test/spec files change;
- `push` to `feature/admin-hybrid-workbench` for the red/green implementation cycle;
- PHP 8.2 + Composer;
- `git diff --check origin/main...HEAD`;
- `composer lint`;
- `vendor/bin/phpunit --testsuite 'NHK Unit'`.

**Step 5: Verify RED**

Push the test-only checkpoint and inspect the Actions run. The expected failure must be caused by missing `AdminWorkbenchRegistry` / `AdminWorkbenchState` production classes, not syntax or infrastructure failure.

---

## Task 2: Implement the pure registry and state presentation model

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchRegistry.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchState.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchRegistryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchStateTest.php`

**Step 1: Implement `AdminWorkbenchRegistry` minimally**

Provide:

```php
public function sections(): array;
public function section(string $id): ?array;
```

Each section row contains:

```php
[
    'id' => 'content',
    'label' => 'Nội dung',
    'description' => 'Bài viết và biên tập do WordPress sở hữu.',
    'owner' => 'WordPress',
    'capability' => 'edit_posts',
    'href' => 'edit.php',
    'kind' => 'native',
]
```

Use exactly one registry for ordering and metadata.

Safe route mapping:

- overview → `admin.php?page=nhk-v3`
- content → `edit.php`
- media → `upload.php`
- video → `admin.php?page=nhk-v3-advanced#video`
- knowledge → `admin.php?page=nhk-v3-advanced#semantic-read`
- governance → `admin.php?page=nhk-v3-advanced#governance`
- dictionary → `admin.php?page=nhk-v3-dictionary`
- system → `admin.php?page=nhk-v3-advanced#system`
- advanced → `admin.php?page=nhk-v3-advanced`

The registry describes destinations only; it does not perform writes.

**Step 2: Implement `AdminWorkbenchState` minimally**

Constructor accepts presentation rows. Normalize each row to:

```php
['label' => string, 'value' => string, 'tone' => 'ready'|'attention'|'blocked'|'neutral']
```

Expose:

```php
public function rows(): array;
public function count(): int;
public function blockerCount(): int;
```

No domain enum or lifecycle is introduced.

**Step 3: Verify GREEN for focused tests**

Run Admin registry/state tests through CI or the available test runner. Confirm both pass while the architecture test still fails until Task 3.

---

## Task 3: Build the Workbench dashboard shell and external assets

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminAssets.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchPage.php`
- Create: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.css`
- Create: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchArchitectureTest.php`

**Step 1: Implement `AdminAssets`**

Register an `admin_enqueue_scripts` callback. Enqueue only when `$hookSuffix` or current page indicates an NHK V3 Admin screen. Use plugin-relative URLs and a stable asset version derived from plugin version/file modification fallback where possible.

No semantic access happens here.

**Step 2: Implement `AdminWorkbenchPage::register()`**

Add the top-level menu:

```php
add_menu_page('NHK V3', 'NHK V3', 'manage_options', 'nhk-v3', [self::class, 'render'], 'dashicons-book-alt', 26);
```

Add a submenu for the existing technical surface:

```php
add_submenu_page('nhk-v3', 'Nâng cao', 'Nâng cao', 'manage_options', 'nhk-v3-advanced', [AdminPage::class, 'render']);
```

Do not call `AdminPage::register()` because that would duplicate the parent menu.

**Step 3: Render task-first dashboard**

Dashboard structure:

- `<header class="nhk-admin-hero">` with title and short explanation;
- `<nav aria-label="Khu vực quản trị NHK V3">` for visible registry sections;
- task card grid;
- ownership boundary callout;
- state legend explaining `Sẵn sàng`, `Cần chú ý`, `Bị chặn`, `Thông tin` as presentation meanings only;
- no invented numeric metrics.

Visibility rules:

- Always show overview to `manage_options` users.
- Show section/action when `current_user_can($section['capability'])`.
- If a daily-work section is not authorized, omit its action rather than rendering an enabled-looking control.
- The dashboard itself remains readable if optional semantic storage is unavailable.

Use `admin_url()` to resolve registered safe destinations.

**Step 4: Add CSS**

Implement reusable tokens/classes:

- `.nhk-admin-workbench`
- `.nhk-admin-hero`
- `.nhk-admin-nav`
- `.nhk-admin-grid`
- `.nhk-admin-card`
- `.nhk-admin-card__meta`
- `.nhk-admin-state`
- tone modifiers
- responsive breakpoints
- focus-visible styles
- reduced-motion rule.

Do not set arbitrary theme colors for domain meaning without text labels.

**Step 5: Add small JS**

Use only progressive enhancement:

- preserve focus when navigating to a hash target in the advanced page;
- make no mutation request;
- no dependency on jQuery.

**Step 6: Change plugin boot**

Import and register `AdminWorkbenchPage` and `AdminAssets` instead of `AdminPage::register()` as the primary menu.

The existing `AdminPage::render()` remains the advanced callback and retains its writer boundaries/capability checks.

**Step 7: Verify architecture test GREEN**

Confirm the new presentation classes/assets pass the no-shortcut architecture test.

---

## Task 4: Integrate existing Dictionary and dossier diagnostics into the Workbench

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/DictionaryAdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/DictionaryBackfillAdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/SemanticDossierCoverageAdminPage.php`
- Add/modify tests under: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/`

**Step 1: Wire Dictionary runtime when `$wpdb` is available**

Construct `DictionaryRuntime($wpdb)` only in the existing boot context where the database object exists. Register `DictionaryAdminPage` and `DictionaryBackfillAdminPage` through their current methods.

Do not run migration 015 from the Admin boot path. `DictionaryRuntime::available()` remains the fail-closed schema check.

**Step 2: Preserve Dictionary human gates**

Do not alter curation semantics. Improve presentation only where needed:

- add shared Workbench heading class;
- add human-readable helper copy;
- remove per-element inline style from newly touched markup where practical;
- keep nonces, revisions and `nhk_curate_dictionary` checks unchanged.

**Step 3: Wire dossier coverage only if its dependencies are already available**

Reuse the existing read-only semantic dossier query/audit objects from plugin boot. If constructing a valid `SemanticDossierCoverageAudit` would require inventing a dependency or bypassing a current application service, do not wire it in this slice; keep the dashboard card marked as advanced/read-only unavailable and document the code gap.

No fake empty-success page is allowed.

**Step 4: Test fail-closed behavior structurally**

Assert Dictionary page registration still requires `nhk_curate_dictionary` and that no Admin change invokes `DictionaryMigration015::up()`.

---

## Task 5: Improve the advanced technical page without changing behavior

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchArchitectureTest.php`

**Step 1: Reframe the page as advanced operations**

Change only presentation copy/headings and IDs where needed:

- title → `NHK V3 — Nâng cao`;
- add warning that this is for technical/Governance operations;
- add stable anchor IDs: `system`, `semantic-read`, `governance`, `video` where applicable;
- preserve the existing renderer order and behavior unless a section can be safely grouped with headings.

**Step 2: Reduce inline presentation debt**

Move reusable visual rules from the existing `scripts()` inline `<style>` block into `admin-workbench.css`.

Do not rewrite the mutation JavaScript in this slice unless a test demonstrates a concrete need; behavior preservation is higher priority than cosmetic refactoring.

If moving the JS would require risky large changes, keep the existing mutation script and document that it remains legacy technical debt. The new Workbench itself must still use external assets.

**Step 3: Ensure action capability guards remain intact**

Do not weaken:

- proposal create capability;
- submit capability;
- approve/reject capability;
- apply capability;
- `manage_options` page access.

**Step 4: Verify focused architecture tests**

Confirm no new semantic shortcut was introduced.

---

## Task 6: Documentation checkpoint and full verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Optionally modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` only if needed to route future agents to the new Admin implementation.

**Step 1: Record execution checkpoint**

Prepend a dated Admin checkpoint stating:

- Hybrid Workbench implemented;
- task-first dashboard + central registry + state presentation + external assets;
- existing technical tools retained under advanced surface;
- Dictionary wiring status;
- dossier wiring status;
- no migration/semantic/live-data mutation;
- exact test/lint/CI evidence;
- target WordPress runtime acceptance remains separate from code-ready evidence unless actually verified.

**Step 2: Run fresh full verification**

Required evidence on the final feature branch:

```bash
composer validate --no-interaction
composer lint
vendor/bin/phpunit --testsuite 'NHK Unit'
git diff --check origin/main...HEAD
```

Through GitHub Actions, read the run conclusion and failed-step logs if any. Do not infer success from workflow creation alone.

**Step 3: Secret review**

Review the feature diff for:

- credentials;
- tokens;
- private keys;
- host-specific secrets;
- `.env` content;
- accidental database dumps.

**Step 4: Requirements review**

Compare final diff against all 15 acceptance criteria from the design spec. Record any unmet item as a blocker rather than silently narrowing scope.

---

## Task 7: Code review, PR, merge and post-merge verification

**Files:** No planned production changes unless review finds issues.

**Step 1: Request code review**

Use the feature-branch diff `main...feature/admin-hybrid-workbench` and review specifically for:

- canonical ownership violations;
- capability bypass;
- direct semantic writes;
- unavailable-state dishonesty;
- duplicate menu registrations;
- WordPress escaping/accessibility issues;
- maintainability/regression risks.

Fix all Critical/Important findings and re-run verification.

**Step 2: Create PR to `main`**

PR title:

`feat: add NHK V3 admin hybrid workbench`

PR body must summarize architecture, safety boundaries, verification evidence and explicitly state that no production/staging semantic data was modified.

**Step 3: Merge only after green evidence**

Use the repository-supported merge method. Do not force-update `main`. If `main` moved, compare/rebase/merge safely and rerun the relevant checks before integration.

**Step 4: Verify merged `main`**

Fetch the merged commit/status and confirm:

- `main` points to a commit containing the feature diff;
- CI/check status is green or, if the repo has no post-merge workflow, the PR/head green evidence is preserved;
- no unexpected files were included.

Do not deploy to production or run a cutover as part of this plan.
