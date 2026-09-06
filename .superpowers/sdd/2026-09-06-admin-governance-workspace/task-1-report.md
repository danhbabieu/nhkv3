# Task 1 implementation report

Status: `DONE_WITH_CONCERNS`

## Scope delivered

Task 1 now has presentation-only Admin projections that preserve unavailable,
blocked, conflict, uncertain, empty, ready and success states without creating a
new writer or changing Governance persistence. `AdminPage` consumes the health
projection and delegates proposal-state copy to the diagnostic presenter. The
existing hooks, nonce creation, capability checks, repository reads and REST
action dispatch remain in place.

## Changed files

- `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkspaceViewModel.php`
- `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminDiagnosticPresenter.php`
- `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminPage.php`
- `public/wp-content/plugins/nhk-core/tests/Unit/AdminWorkspaceViewModelTest.php`
- `public/wp-content/plugins/nhk-core/tests/Unit/AdminDiagnosticPresenterTest.php`
- `.superpowers/sdd/2026-09-06-admin-governance-workspace/task-1-report.md`

No changes were made to `PublicSlugMigrationService.php` or
`PublicSlugMigrationServiceTest.php`.

## Commits

- `87d4aeba96c0da3f8c6045df6584750b12ad5cf9` — a concurrent process committed
  the initial Task 1 implementation while this implementer was still running
  the TDD cycle. That commit also contains unrelated execution-state/frontend
  changes and was not rewritten or disturbed.
- `6f71d08d9bac8b992ef27a13f101f4005be88b3e` — `refactor: harden admin presentation state`; contains only the
  remaining Task 1 exception-state, presentation-label and compatibility-test
  refinements.

The report itself is committed separately after the implementation hash became
available.

## TDD evidence

Initial RED command:

```text
vendor/bin/phpunit --filter 'Admin(WorkspaceViewModel|DiagnosticPresenter)Test'
```

Initial output:

```text
EEEEEE  6 / 6
Errors: 6
Class "NHK\Core\Infrastructure\Admin\AdminDiagnosticPresenter" not found
Class "NHK\Core\Infrastructure\Admin\AdminWorkspaceViewModel" not found
exit code 2
```

Exception-state RED command:

```text
vendor/bin/phpunit --filter AdminWorkspaceViewModelTest
```

Output before the exception handling refinement:

```text
..F.  4 / 4
Expected state: blocked
Actual state: ready
Tests: 4, Assertions: 13, Failures: 1
exit code 1
```

Final focused command:

```text
vendor/bin/phpunit --filter 'Admin(WorkspaceViewModel|DiagnosticPresenter)Test'
```

Final output:

```text
.......  7 / 7 (100%)
Tests: 7, Assertions: 25
OK, but there were issues: PHPUnit Deprecations: 5
exit code 0
```

Existing Admin source-contract compatibility command:

```text
vendor/bin/phpunit --filter 'FrontendContractTest::test_admin_contract_associates_labels_with_operational_controls'
```

Output:

```text
.  1 / 1 (100%)
Tests: 1, Assertions: 45
OK, but there were issues: PHPUnit Deprecations: 5
exit code 0
```

Required PHP lint command:

```text
find public/wp-content/plugins/nhk-core/src/Infrastructure/Admin -name '*.php' -print0 | xargs -0 -n1 php -l
```

Output: all 10 Admin PHP files reported `No syntax errors detected`; exit code
`0`.

Additional checks:

```text
git diff --cached --check
```

Output: empty; exit code `0`.

A targeted secret-pattern review of the five implementation/test files returned
no matches.

## Self-review

- Confirmed the view model returns only detached presentation arrays and does
  not query repositories or mutate data.
- Confirmed `null` remains `value=null` with `state=unavailable` and is never
  converted to zero or false.
- Confirmed exception-valued health is `blocked`, redacts the exception message,
  and cannot become a successful value.
- Confirmed zero counts are `empty`, positive counts are `success`, denied
  capabilities are `blocked`, and negative counts fail as `conflict`.
- Confirmed known Article publication diagnostics reuse
  `PublicationDiagnosticRegistry`; only owner-review diagnostics are marked
  overridable. Unknown codes fail closed as `system_blocked` and are never
  overridable.
- Confirmed `AdminPage` no longer performs the proposal-state presentation
  mapping and retains all existing repositories, capabilities, nonce generation
  and action URLs.
- Mutation check: removing null handling breaks the unavailable test; treating a
  failed health layer as ready breaks the reason-code test; permitting unknown
  diagnostics breaks the fail-closed test; treating exceptions as ready breaks
  the exception regression test.

## Concerns

- Shared-workspace concurrency produced commit `87d4aeb`, which combines the
  initial Task 1 files with unrelated frontend/execution-state changes. This
  implementer did not rewrite that commit because doing so would risk destroying
  another task's work. The follow-up implementation commit and report commit are
  Task 1-only.
- PHPUnit reports five pre-existing deprecations even though all focused tests
  pass. No deprecation was introduced or suppressed in Task 1.
- Per the Task 1 file allowlist, the already modified
  `docs/architecture/V3_EXECUTION_STATE.md` was read but not edited.
