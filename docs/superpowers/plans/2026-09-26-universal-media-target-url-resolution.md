# Universal Media Target URL Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Resolve first-party Media and target URLs into canonical MediaUsage operations for Articles and every registered Media-capable Authority owner, including strict natural-language representative intent and final canonical read-back.

**Architecture:** Add one application-facing first-party target URL resolver that delegates native WordPress and registered public-route/identity owners, then re-normalizes through `MediaTargetNormalizer`. Keep `MediaBindingService`, Governance, WordPress featured projection, Authority projection and MediaUsage reverse lookup as the existing owners; the compiler only translates locator intent into those boundaries.

**Tech Stack:** PHP 8+, PHPUnit 11, WordPress adapter/runtime, existing NHK V3 endpoint/entity/capability registries, Composer scripts.

**Spec:** `docs/superpowers/specs/2026-09-26-universal-media-target-url-design.md`

## Global Constraints

- URL is a locator only; never persist it as canonical identity.
- Use runtime endpoint/entity/Media capability registries; do not add a hard-coded Authority list.
- `wp_post` uses `featured_primary`; registered Authority targets use `representative`.
- MediaUsage is the sole Media-to-consumer relation owner; shared Media never creates a Graph edge.
- All writes remain Capture → exact operation/scope → Governance/Controlled Apply → canonical read-back.
- No schema migration, seed, backfill, live/staging mutation, deployment or PR merge.
- Fail closed on ambiguity, route drift, inactive/missing targets, stale revisions, idempotency conflict and read-back drift.

## Review Focus

- **Canonical route drift and compatibility identity:** a URL must resolve only when the route owner returns one active target and exact canonical path evidence; test in `WordPressMediaTargetUrlResolverTest`.
- **Nested route ownership:** Model and Variant paths must not resolve to Brand or parent; test in the resolver contract suite.
- **Shared Media replacement scope:** replacing Model usage must preserve Brand, Classification and Article usages; test in binding/readback tests.
- **Natural-language parsing boundaries:** only the registered “use image as representative” form is accepted; malformed or ambiguous URL pairs fail closed; test in compiler/router tests.
- **Completion drift:** usage-only or attachment-only success is not complete; test Post and Authority projection read-back separately.

---

### Task 1: Add the first-party Media target URL resolver contract and adapter

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Media/FirstPartyMediaTargetUrlResolver.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/WordPress/WordPressMediaTargetUrlResolver.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/WordPressMediaTargetUrlResolverTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FirstPartyMediaTargetUrlResolverContractTest.php`

**Interfaces:**
- Consumes: `WordPressPostUrlResolver`, `PublicRouteResolver`, `HistoricPublicRouteService` where authorized, registered route-capable endpoint definitions and first-party origin provider.
- Produces: `FirstPartyMediaTargetUrlResolver::resolve(string $url): array{type:string,id:string}` with typed `MediaException` diagnostics and canonical route evidence for internal verification.

- [ ] **Step 1: Write failing resolver tests**

  Cover HTTPS/host/port/credential/query/fragment/double-slash/traversal rejection; exact Post resolution; Brand, nested Model, nested Variant and Clock Type/Classification resolution; inactive/unknown/ambiguous/mismatched/route-drift failures; historic route acceptance only through an explicit resolver result; and rejection of URL-derived identity.

- [ ] **Step 2: Run the focused tests and verify they fail for the missing contract/adapter**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/WordPressMediaTargetUrlResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/FirstPartyMediaTargetUrlResolverContractTest.php`

  Expected: FAIL because the resolver contract/implementation is not present.

- [ ] **Step 3: Implement the contract and adapter**

  Parse and validate the absolute URL, resolve through injected route owners and registered route-capable definitions, require one active canonical target, compare the normalized requested path to the owner’s exact canonical path, and map resolver failures to typed diagnostics. Do not scan a new hard-coded type list, fetch the URL, or infer identity from slug/name.

- [ ] **Step 4: Run the focused resolver tests**

  Run the command from Step 2.

  Expected: PASS for all resolver cases with no warnings converted into success.

- [ ] **Step 5: Run PHP lint for the new files**

  Run: `php -l public/wp-content/plugins/nhk-core/src/Contracts/Media/FirstPartyMediaTargetUrlResolver.php && php -l public/wp-content/plugins/nhk-core/src/Infrastructure/WordPress/WordPressMediaTargetUrlResolver.php`

  Expected: No syntax errors.

### Task 2: Compile typed and natural-language target intent through the resolver

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentIntentCompiler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/ContentIntentRouter.php` or the existing Capture input-normalization seam selected by the current flow
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentIntentCompilerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

**Interfaces:**
- Consumes: Task 1 `FirstPartyMediaTargetUrlResolver`; existing `MediaBindingService::resolveMediaReference`; existing `MediaTargetNormalizer`.
- Produces: canonical `MEDIA_ENRICHMENT.media_operations[]` with `add`, `replace`, `keep` or `representative_bind`/`set_featured`, exact target, role, placement, idempotency key and usage CAS snapshot.

- [ ] **Step 1: Write failing compiler/router/contract tests**

  Pin the exact user command, Post mapping to `set_featured`/`featured_primary`, Authority mapping to `representative_bind`/`representative`, multi-target operation independence, explicit type mismatch, malformed natural command, and schema support without a second operation vocabulary.

- [ ] **Step 2: Run focused tests to verify the expected failures**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentIntentCompilerTest.php public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

  Expected: FAIL on the newly pinned behavior.

- [ ] **Step 3: Implement the minimal normalization path**

  Inject the resolver into the compiler/runtime composition. Normalize the strict natural-language URL pair into the existing typed operation packet, resolve every target through Task 1 then `MediaTargetNormalizer`, derive target-local idempotency/CAS, and preserve existing typed callers and compatibility behavior.

- [ ] **Step 4: Run the focused tests to verify green**

  Run the command from Step 2.

  Expected: PASS with all existing compiler/router contract cases preserved.

### Task 3: Wire the resolver through production composition without changing owners

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php` only where constructor/dispatch injection is required
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpTransportBoundaryTest.php`

**Interfaces:**
- Consumes: Task 1 resolver and Task 2 compiler output.
- Produces: production composition in which `nhk.capture.ingest` is the only normal entrypoint and direct/internal compatibility tools retain their capability guards.

- [ ] **Step 1: Write failing wiring/boundary tests**

  Assert the production compiler receives the first-party target resolver, exact Capture execution still skips the default semantic planner when explicit operations are authoritative, and no generic WordPress writer or direct MediaUsage writer is introduced.

- [ ] **Step 2: Run the focused wiring tests and verify failure**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpTransportBoundaryTest.php`

  Expected: FAIL on the new dependency/dispatch assertions.

- [ ] **Step 3: Wire the existing application services**

  Construct the resolver from current Public Route/Identity, Post URL, endpoint and Media capability owners. Keep internal/admin capability checks and the Capture-only normal entrypoint unchanged.

- [ ] **Step 4: Run the focused wiring tests**

  Run the command from Step 2.

  Expected: PASS.

### Task 4: Complete target-specific canonical read-back and reverse usage proof

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaEnrichmentExactReadbackService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php` only if the registered projection reader lacks the required exact representative proof
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/MediaUsageRelationshipAdapter.php` only if reverse read lacks the required bounded active/history result
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentExactReadbackServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingReadbackContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceGenericOwnerTest.php`

**Interfaces:**
- Consumes: canonical operations and Governance results from Tasks 2–3; existing MediaUsage, attachment bridge and projection readers.
- Produces: a non-success diagnostic unless fresh read-back proves the exact target/role/placement/Media/revision and the owner-specific projection.

- [ ] **Step 1: Write failing read-back tests**

  Cover Post usage/attachment/native featured convergence, Authority representative projection, KEEP UUID/revision verification, REPLACE retirement, stale usage, projection drift, shared Media reverse read and preservation of unrelated target usages. Assert no Graph edge is created.

- [ ] **Step 2: Run the focused read-back tests and verify failure**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentExactReadbackServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingReadbackContractTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceGenericOwnerTest.php`

  Expected: FAIL on missing Authority/reverse/projection proof.

- [ ] **Step 3: Implement minimal fresh-read verification**

  Re-read usage and registered owner projection after Governance Apply. Verify Post attachment/native featured identity through the existing bridge and Authority representative through the existing projection. Preserve history and target scope; never use a broad catch to report completion.

- [ ] **Step 4: Run the focused read-back tests**

  Run the command from Step 2.

  Expected: PASS.

### Task 5: Synchronize active contracts and execution evidence

**Files:**
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md` only where the active behavior contract changes
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` only where the entrypoint/read-back policy changes
- Modify: `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md` only where operator-facing natural URL input changes
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with an evidence-based checkpoint
- Test: relevant documentation-registry/contract tests identified by the changed docs

**Interfaces:**
- Consumes: verified runtime behavior from Tasks 1–4.
- Produces: synchronized active documentation stating URL-as-locator, MediaUsage ownership, role split, shared Media/no-Graph, final read-back and no schema migration.

- [ ] **Step 1: Update only the active normative/operational contracts**

  Keep historical PR #15 references subordinate and record no deployment/live acceptance.

- [ ] **Step 2: Run documentation and contract checks**

  Run the relevant PHPUnit contract tests plus `git diff --check`.

  Expected: active documentation identity/tests remain consistent.

### Task 6: Full verification and checkpoint closure

**Files:**
- Modify: only files required by verification findings; do not broaden scope.

- [ ] **Step 1: Run the focused Media/Capture/MCP suites**

  Run: `vendor/bin/phpunit -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentIntentCompilerTest.php public/wp-content/plugins/nhk-core/tests/Unit/WordPressMediaTargetUrlResolverTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaEnrichmentExactReadbackServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaTargetNormalizerTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceGenericOwnerTest.php public/wp-content/plugins/nhk-core/tests/Unit/ContentIntentRouterTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

  Expected: all focused tests pass; report warnings/deprecations separately.

- [ ] **Step 2: Run the full NHK Unit suite**

  Run: `vendor/bin/phpunit -d memory_limit=512M -c phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit`

  Expected: record exact pass/failure counts, including pre-existing baseline failures.

- [ ] **Step 3: Run PHP lint, Composer validation, diff and secret review**

  Run: `composer validate --no-interaction`; `composer lint`; `git diff --check`; and the repository-approved secret review command.

  Expected: report each command’s exit status and any environmental blocker without relabeling it as pass.

- [ ] **Step 4: Run guarded integration only if the exact environment is available**

  Run the relevant guarded integration command with the documented `NHK_WP_TEST_PATH`/`NHK_WP_TEST_DB` setup; otherwise record `NOT_RUN`/`BLOCKED` and do not substitute another database.

- [ ] **Step 5: Update the execution checkpoint and inspect final diff/status**

  Run: `git diff --check`; `git status --short --branch`; `git diff --stat`; and a targeted secret review.

  Expected: only planned files changed, no credentials/secrets, no schema/data mutation, no deployment/live acceptance.
