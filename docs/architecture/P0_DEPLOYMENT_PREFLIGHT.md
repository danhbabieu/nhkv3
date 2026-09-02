# P0 Deployment Preflight

> **NON-NORMATIVE.** This is deployment evidence and operating guidance. If it
> conflicts with `docs/constitution/NHK_V3_CONSTITUTION.md`, the Constitution
> controls.

Run from the repository root:

```bash
composer preflight -- --expected-head=$(git rev-parse HEAD)
```

The command is read-only and exits non-zero if any release gate fails. It
reports JSON lines for the intended Git HEAD (and rejects a supplied
`--expected-head` mismatch), `composer.lock`, root Composer
autoload, Symfony UID, NHK runtime classes, WordPress bootstrap, nhk-core
bootstrap, schema/migration state, Authority hydration capability and REST
bootstrap.

The deployment sequence is one coherent release: synchronize the server to
the intended local/origin HEAD after inspecting `git status --short --branch`,
run the root Composer install from the committed lock file, run this preflight,
then run the registry-wide parity, REST and visitor-facing read smokes. Never
reset, clean, overwrite, delete or commit the unrelated server
`public/error_log`.

The preflight does not import SQL, restore data, seed entities, repair Graph
edges or change database state. A successful `git pull` alone is not release
evidence.
