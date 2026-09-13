# ChatGPT MU-Plugin Deployment Package Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:test-driven-development for the failing-test-first cycle. No commit, push, pull, merge, rebase, deployment, Capture call, or live mutation is authorized by this task.

**Goal:** Extend the existing allowlisted DEMO deployment adapter so its current `nhk-core` transfer also carries exactly `public/wp-content/mu-plugins/nhk-chatgpt-file-transport.php` to the canonical WordPress MU-plugin directory.

**Architecture:** Preserve the existing `nhk-core` source, destination, checksum rsync, target allowlist, and remote verification. Add one exact MU-plugin source/destination entry to the same adapter and include that file in the deterministic deployment fingerprint; do not create another deployer or alter runtime/domain code.

**Tech Stack:** PHP 8.x, PHPUnit 11, existing `RemoteDeploymentAdapter`, SSH/rsync, Composer preflight.

**Spec:** User request `@v3-15` — bounded deployment packaging fix for ChatGPT file transport MU-plugin.

## Global Constraints

- Do not run deployment while `NHK_DEMO_DEPLOY_CONFIG` is missing.
- Do not run `git pull`, `git push`, commit, merge, or rebase.
- Do not change Capture, Media, semantic runtime, database, schema, migration, or public routes.
- Keep the existing `nhk-core` deployment source and remote destination unchanged.
- Transfer only `public/wp-content/mu-plugins/nhk-chatgpt-file-transport.php` as the additional MU-plugin.
- Do not create a second deployer or use a manual SSH/rsync bypass.

---

### Task 1: Add the deployment-package regression test

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/RemoteDeploymentAdapterTest.php`
- Test: the existing `test_transfer_is_deterministic_and_verified_without_semantic_transport` test

**Interfaces:**
- Consumes: current `RemoteDeploymentAdapter::deploy()` command capture.
- Produces: assertions that the existing `nhk-core` source/destination remain present and that the exact MU-plugin source is transferred to the sibling `wp-content/mu-plugins` destination.

- [ ] **Step 1: Write the failing assertions**

  Require the existing `nhk-core` rsync command to remain unchanged, require the command sequence to include one exact MU-plugin transfer, and require the MU-plugin destination to end at `mu-plugins/nhk-chatgpt-file-transport.php`. Keep the existing remote `nhk-core.php` verification assertion.

- [ ] **Step 2: Run the focused test to verify RED**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/RemoteDeploymentAdapterTest.php`

  Expected: the transfer test fails because the current adapter emits only the `nhk-core` transfer and remote `nhk-core.php` verification; it does not emit the exact MU-plugin transfer.

### Task 2: Extend the existing adapter minimally

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Demo/RemoteDeploymentAdapter.php`

**Interfaces:**
- Consumes: existing `NHK_DEMO_DEPLOY_CONFIG`, target allowlist, plugin `remote_path`, and rsync/SSH executor.
- Produces: the existing plugin deployment plus one exact MU-plugin transfer to the canonical sibling `wp-content/mu-plugins` location, with a deterministic fingerprint covering both deployed components.

- [ ] **Step 1: Add the exact MU-plugin source and canonical destination**

  Keep the existing plugin rsync command and verification. Resolve the MU destination from the configured plugin destination as `dirname(dirname(rtrim($remote_path, '/'))) . '/mu-plugins'`, which maps `/.../wp-content/plugins/nhk-core` to `/.../wp-content/mu-plugins`. Reject a missing MU-plugin source before transport.

- [ ] **Step 2: Transfer only the required MU-plugin through the same adapter**

  Use the existing SSH command options and rsync safety flags for the single source file, targeting the derived MU-plugin directory. Do not add a wildcard or copy the entire MU-plugin directory.

- [ ] **Step 3: Include the exact MU-plugin in the deployment fingerprint**

  Preserve all existing plugin fingerprint entries and add one stable relative entry for the MU-plugin. Continue excluding tests and secret-like extensions. Do not alter documentation, runtime, or semantic identities.

- [ ] **Step 4: Run the focused deployment test to verify GREEN**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/RemoteDeploymentAdapterTest.php`

  Expected: PASS with the old `nhk-core` transfer preserved and the exact MU-plugin transfer asserted.

### Task 3: Verify bounded local package and regressions

**Files:**
- Read-only verification of the two modified implementation/test files and the generated deployment command plan.

- [ ] **Step 1: Run deployment/preflight checks**

  Run: `composer preflight -- --expected-head=$(git rev-parse HEAD)`

- [ ] **Step 2: Run transport and deployment focused tests**

  Run the existing ChatGPT transport tests together with `RemoteDeploymentAdapterTest.php`; do not call Capture or any live writer.

- [ ] **Step 3: Verify package scope and integrity**

  Check `git diff --check`, PHP lint for the changed PHP files, and a read-only diff/scope review. Confirm the package plan includes the existing `nhk-core` component plus exactly one MU-plugin file at the canonical relative path, with no schema or semantic files changed.

- [ ] **Step 4: Confirm deployment config gate without deploying**

  Read only whether `NHK_DEMO_DEPLOY_CONFIG` is set. If absent, report `DEPLOY_RUNTIME_CONFIG=BLOCKED` and `REASON=NHK_DEMO_DEPLOY_CONFIG_MISSING`; do not invoke the deployment wrapper or any manual transfer.
