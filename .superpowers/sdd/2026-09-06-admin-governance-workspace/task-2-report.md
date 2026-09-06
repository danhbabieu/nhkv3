# Task 2 implementation report

Status: `DONE_WITH_CONCERNS`

## Scope delivered

Task 2 adds a presentation-only `AdminShell` with a skip link, breadcrumb and
workspace navigation landmarks, an explicitly focusable main landmark and
capability-projected `full`, `read_only` and `unavailable` modes. `AdminPage`
renders its existing health, lookup, composer and Governance panels inside the
shell without changing repositories, URLs, form/action names, nonce creation or
domain persistence behavior.

The new CSS provides visible keyboard focus, text-backed active/read-only
states, touch-sized navigation, responsive layout and reduced-motion handling.
The new JavaScript is limited to disclosure, GET status feedback and focus
restoration; it does not construct semantic payloads. `Plugin` registers and
enqueues these assets only for NHK V3 Admin screen hook/page identifiers and
localizes the existing REST base URL and WordPress REST nonce.

## Changed files

- `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminShell.php`
- `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- `public/wp-content/plugins/nhk-core/src/Plugin.php`
- `public/wp-content/plugins/nhk-core/assets/admin.css`
- `public/wp-content/plugins/nhk-core/assets/admin.js`
- `public/wp-content/plugins/nhk-core/tests/Unit/AdminShellTest.php`
- `.superpowers/sdd/2026-09-06-admin-governance-workspace/task-2-report.md`

The pre-existing `PublicSlugMigrationService.php`,
`PublicSlugMigrationServiceTest.php`, untracked
`PublicSlugMigrationIntegrationTest.php` and modified
`docs/architecture/V3_EXECUTION_STATE.md` were preserved and not staged.

## Commits

- `b73f389` — `feat: add accessible NHK admin shell`; contains only the six
  Task 2 implementation/test files listed above.
- The report is committed separately after the implementation hash is known.

## TDD evidence

Initial RED command:

```text
vendor/bin/phpunit --filter AdminShellTest
```

Initial output:

```text
EE  2 / 2
Class "NHK\Core\Infrastructure\Admin\AdminShell" not found
Tests: 2, Assertions: 0, Errors: 2, PHPUnit Deprecations: 5, Risky: 1
exit code 2
```

Final focused command:

```text
vendor/bin/phpunit --filter AdminShellTest
```

Final output:

```text
..  2 / 2 (100%)
Tests: 2, Assertions: 5, PHPUnit Deprecations: 5
exit code 0
```

## Lint and quality checks

Changed-PHP lint command:

```text
php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminShell.php
php -l public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php
php -l public/wp-content/plugins/nhk-core/src/Plugin.php
php -l public/wp-content/plugins/nhk-core/tests/Unit/AdminShellTest.php
```

Output: all four files reported `No syntax errors detected`; exit code `0`.

Additional checks:

```text
git diff --check
git diff --cached --check
```

Both produced no output and exited `0`. A targeted secret-pattern review of all
six implementation/test files returned no matches. The staged diff before the
implementation commit contained exactly six Task 2 files, 412 insertions and
3 deletions.

## Self-review

- Confirmed workspace availability and mode are derived only from the supplied
  capability map; missing capabilities are not inferred as granted.
- Confirmed `manage_options=true` without `nhk_apply_proposals=true` produces
  Governance `mode=read_only` with a non-empty explanation.
- Confirmed unavailable workspaces render as disabled text rather than a false
  operational link, while the active state uses `aria-current` and visible
  “Đang mở” text instead of color alone.
- Confirmed shell output includes a skip link, semantic navigation, breadcrumb,
  heading hierarchy and `<main id="nhk-admin-main">`.
- Confirmed the existing Admin capability gate, repository calls, REST URLs,
  form/action names, nonce generation and inline legacy panel behavior remain
  unchanged.
- Confirmed `admin.js` performs no POST, payload construction or semantic
  mutation; status fetch failure is reported as uncertain rather than success.
- Mutation check: granting the missing Governance write capability changes the
  mode to `full`; removing the read capability changes it to `unavailable`;
  removing any required landmark breaks `AdminShellTest`.

## Concerns

- PHPUnit reports five pre-existing deprecations even though the focused tests
  pass; Task 2 does not suppress or alter them.
- The repository already contains a separate pre-existing Admin Workbench
  asset loader and asset pair. Task 2 did not modify those out-of-scope files;
  the new required shell assets use distinct handles/selectors and are scoped
  to the same NHK V3 screens.
- Per the Task 2 allowlist, the already modified execution-state ledger was read
  before the checkpoint but not edited.
