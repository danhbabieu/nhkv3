# NHK V3 Deploy Documentation Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build one fail-closed command that generates canonical docs, deploys the exact NHK Core artifact, and verifies direct target MCP documentation/runtime identity.

**Architecture:** A thin POSIX wrapper delegates to a PHP CLI. The CLI performs clean-checkout and optional fast-forward-pull checks, invokes the existing Composer generator and `RemoteDeploymentAdapter`, then calls the target Streamable HTTP MCP endpoint through an injectable verifier. It compares the generated manifest, package fingerprint, and every target document hash without performing cache or service mutations.

**Tech Stack:** PHP 8.1+, Composer, PHPUnit 11, existing NHK Core PSR-4 runtime, cURL, SSH/rsync through the existing adapter, POSIX shell.

**Spec:** `docs/superpowers/specs/2026-09-11-deploy-docs-verification-design.md`

## Global Constraints

- Target allowlist remains exactly `demo.1945.vn`.
- Repository docs remain the canonical source; generated `resources/canonical-docs/` is immutable release output.
- Existing `RemoteDeploymentAdapter` is the only transfer boundary.
- No database, semantic record, WordPress content, cache option, transient, PHP-FPM service or OPcache configuration is mutated.
- Missing configuration, unavailable MCP, malformed JSON-RPC, stale docs and package identity mismatch fail non-zero.
- Credentials, SSH command arguments, request headers and response bodies are never printed.
- Existing user worktree changes are preserved and must make deployment fail closed until committed or stashed by the user.

---

### Task 1: Add direct MCP documentation verifier

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Demo/RemoteMcpDocumentationVerifier.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/RemoteMcpDocumentationVerifierTest.php`

**Interfaces:**
- `RemoteMcpDocumentationVerifier::__construct(Closure $request)` where the closure accepts `(string $url, string $method, array $headers, string $body)` and returns `array{status:int,body:string}`.
- `verify(string $baseUrl, array $expectedBootstrap, string $expectedBuildIdentity): StageResult`.

- [x] **Step 1: Write failing tests** for bootstrap identity equality, per-file hash mismatch returning `DOC_MANIFEST_MISMATCH`, build identity mismatch returning `DEPLOYMENT_NOT_ACTIVE`, and malformed/unavailable responses returning `MCP_BOOTSTRAP_UNAVAILABLE`.
- [x] **Step 2: Run the focused verifier test** with `vendor/bin/phpunit public/wp-content/plugins/nhk-core/tests/Unit/RemoteMcpDocumentationVerifierTest.php`; confirm it fails because the verifier class does not exist.
- [x] **Step 3: Implement the minimal verifier** with `tools/list` plus `tools/call` for `nhk.documentation.bootstrap` and `nhk.documentation.list`; validate HTTP 2xx, JSON-RPC `result`, `isError=false`, required identity fields, file path/hash maps, and the expected package identity.
- [x] **Step 4: Run the focused test** and confirm all verifier cases pass.
- [x] **Step 5: Refactor only after green** to centralize bounded JSON-RPC extraction and safe identity comparison; rerun the focused test.

### Task 2: Add deployment verification CLI and shell entrypoint

**Files:**
- Create: `tools/nhk-deploy-verify.php`
- Create: `scripts/nhk-deploy-verify`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/NhkDeployVerifyCliContractTest.php`
- Modify: `composer.json` only if a new named script is needed; prefer direct `php tools/nhk-deploy-verify.php` composition so existing script names remain unchanged.

**Interfaces:**
- Supported CLI options: `--target=demo.1945.vn`, `--base-url=https://demo.1945.vn`, `--expected-head=<40-hex>`, `--pull`, and `--json`.
- Default URL is derived only for the allowlisted target as `https://demo.1945.vn`; arbitrary targets and URLs fail closed.
- The command requires `NHK_DEMO_DEPLOY_CONFIG` for transfer and delegates transfer to `RemoteDeploymentAdapter`.

- [x] **Step 1: Write failing CLI contract tests** for the thin executable shell, rejected dirty worktree, rejected non-allowlisted target, and the fact that no success is emitted when the MCP probe returns an old build identity.
- [x] **Step 2: Run the CLI contract test** and confirm failure because the new entrypoint is absent.
- [x] **Step 3: Implement the shell entrypoint** with `set -eu`, safe repository-root resolution, and `exec php tools/nhk-deploy-verify.php "$@"`; do not put deployment logic in the shell.
- [x] **Step 4: Implement the PHP CLI** to parse only the supported options, reject unknown arguments, require a clean worktree, optionally run `git pull --ff-only origin main` only when `--pull` is present, verify `--expected-head` after pull, run `composer install --no-interaction --prefer-dist --no-progress`, run `composer generate:mcp-docs`, validate the generated snapshot with `McpDocumentationRegistry`, call `RemoteDeploymentAdapter`, and then invoke `RemoteMcpDocumentationVerifier` against the base URL.
- [x] **Step 5: Add the default cURL request closure** with a 15-second timeout, `Content-Type: application/json`, `Accept: application/json, text/event-stream`, and `MCP-Protocol-Version: 2026-07-28`; return only status/body internally and never print raw response data.
- [x] **Step 6: Run the CLI contract test** and confirm configuration/MCP failures are non-zero and no false success is printed.

### Task 3: Document the operator command and runtime caveats

**Files:**
- Modify: `docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [x] **Step 1: Add the exact post-push command** using `NHK_DEMO_DEPLOY_CONFIG`, `--target`, `--base-url`, `--expected-head` and optional `--pull`.
- [x] **Step 2: Record that docs generation precedes rsync**, that the deployment path is SSH/rsync rather than server `git pull` or CI/CD, and that cache/FPM restart commands are intentionally host-specific and not automated.
- [x] **Step 3: Record the wrapper's fail-closed reason codes and the direct MCP fields it verifies.**
- [x] **Step 4: Read the updated execution state and verify no claim of live deployment is made by local tests.

### Task 4: Verification checkpoint

**Files:** changed files from Tasks 1–3 only.

- [x] **Step 1:** Run focused verifier and CLI tests.
- [x] **Step 2:** Run `composer validate --no-check-publish`.
- [x] **Step 3:** Run `composer lint` and PHP lint for each changed PHP file.
- [x] **Step 4:** Run `git diff --check`.
- [x] **Step 5:** Run the full `composer test` suite and record exact environment-gated failures without downgrading them.
- [x] **Step 6:** Run a changed-scope secret review excluding credential/config paths and confirm no secrets are present.
- [x] **Step 7:** Run the wrapper in the current checkout; it stops earlier at `WORKTREE_NOT_CLEAN` because concurrent user changes are present, and it does not claim success.
- [ ] **Step 8:** Update `docs/architecture/V3_EXECUTION_STATE.md` with verification evidence, then commit only the wrapper/spec/plan/documentation files belonging to this task. Commit is intentionally deferred because the shared worktree contains unrelated user changes.

## Plan self-review

- Spec coverage: local generation, immutable snapshot, existing rsync adapter, direct MCP bootstrap/list, top-level identities, per-file hashes, fail-closed codes and no cache/restart guessing are covered by Tasks 1–4.
- Placeholder scan: no `TBD`, `TODO`, or unspecified commands are used; host-specific cache/FPM actions are explicitly out of scope.
- Type consistency: the verifier consumes `StageResult`, the CLI supplies the expected manifest and adapter fingerprint, and the shell preserves the PHP exit code.
