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

### Fail-closed documentation deployment wrapper

The repository wrapper for the allowlisted DEMO target is:

```bash
NHK_DEMO_DEPLOY_CONFIG=/absolute/path/to/deploy.ini \
./scripts/nhk-deploy-verify \
  --target=demo.1945.vn \
  --base-url=https://demo.1945.vn \
  --expected-head="$(git rev-parse HEAD)" \
  --json
```

When the checkout is clean and the operator explicitly wants a fast-forward
from `origin/main`, use `--pull` and omit `--expected-head`:

```bash
NHK_DEMO_DEPLOY_CONFIG=/absolute/path/to/deploy.ini \
./scripts/nhk-deploy-verify \
  --target=demo.1945.vn \
  --base-url=https://demo.1945.vn \
  --pull \
  --json
```

The wrapper runs `composer install`, then `composer generate:mcp-docs`,
validates the generated `resources/canonical-docs/manifest.json`, transfers
the plugin through `RemoteDeploymentAdapter`, and probes the direct target
endpoint `/wp-json/nhk/v1/mcp`. The probe checks MCP initialization,
`tools/list`, `nhk.documentation.bootstrap` and `nhk.documentation.list`, then
compares `documentation_version`, `manifest_hash`, `build_identity` and every
manifest file SHA-256. It exits non-zero on `DOC_BUILD_FAILED`,
`DOC_MANIFEST_MISMATCH`, `MCP_BOOTSTRAP_UNAVAILABLE` or
`DEPLOYMENT_NOT_ACTIVE`; an rsync success alone is never a release success.

The wrapper does not guess a cache flush, PHP-FPM reload or OPcache command.
Those actions are hosting-specific and are not required by the canonical file
reader. If the target still returns an old identity, the wrapper reports
`DEPLOYMENT_NOT_ACTIVE` and stops for an operator to inspect the active host
configuration before rerunning the verification.

The preflight does not import SQL, restore data, seed entities, repair Graph
edges or change database state. A successful `git pull` alone is not release
evidence.

The release package must also pass the canonical documentation gate. It must
carry either repository `docs/` or the generated immutable
`nhk-core/resources/canonical-docs/manifest.json`; the generator fails when
`AGENTS.md`, `READ_FIRST.md`, the Constitution, Status Index or Execution State
is missing. Runtime documentation is checked by the same manifest reader used
by MCP, so the deployed runtime version and documentation snapshot cannot drift
silently.

### Odo runtime deployment evidence — 2026-09-03

A human-controlled rsync deployment was executed to the configured remote
project and plugin destination, followed by a successful WordPress cache
flush. Perl locale warnings were non-fatal. Credentials, host secrets and
private key material are intentionally not recorded here. This evidence does
not authorize semantic apply or final production cutover.
