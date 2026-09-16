# Public Clock Live Blockers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the three remaining Public Clock blockers locally and at contract level: mutation-free editorial CAS, callable publication-review Ability parity, and coherent documentation/runtime/catalog release verification.

**Architecture:** Preserve WordPress as the editorial owner, the existing MCP transport as the canonical dispatcher, and the existing SSH/rsync adapter as the only deployment transport. Add narrow reusable boundaries: a stable editorial snapshot CAS gate, a catalog-derived callable registration/parity check, and a release tuple/packaging verifier that treats generated docs and MCP resources as part of the same artifact.

**Tech Stack:** PHP 8.1+, PHPUnit 11, existing NHK V3 PSR-4 runtime, WordPress Abilities API, existing MCP Streamable HTTP transport, Composer-generated canonical documentation snapshot, SSH/rsync deployment adapter.

**Spec:** User-provided Public Clock blocker request in `/Users/imac24-2125d/.codex/attachments/65b72e14-96db-4cd6-aff6-672b3a5d5866/pasted-text.txt`; governing contracts `docs/architecture/ARTICLE_INGEST_CONTRACT.md`, `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, and `docs/superpowers/specs/2026-09-11-deploy-docs-verification-design.md`.

## Global Constraints

- Do not mutate staging/live data, create/reconcile Public Clock semantic records, or use generic WordPress/direct database writers.
- Keep `wp_posts` as the sole owner of editorial title/body/excerpt/status and keep semantic mutation behind Governance.
- A stale editorial token must return `EDITORIAL_STATE_CONFLICT` with zero native writes, revisions, metadata writes, reconciliation/composer callbacks, or modified timestamp changes.
- Every exposed `nhk.article.*` Easy MCP descriptor must have a registered callable Ability and canonical MCP dispatch target; missing capability returns a typed permission failure, never `Unknown tool`.
- Generated docs snapshot, manifest, runtime source, MCP catalog/resources and deployment fingerprint must be verified as one coherent release tuple; mismatch fails closed.
- Preserve the existing modified `docs/architecture/V3_EXECUTION_STATE.md` until its facts are merged into the final checkpoint update.

### Task 1: Lock the editorial CAS boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/WordPress/EditorialDraftGateway.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/WordPress/WpEditorialPostStore.php` only if the stable snapshot/write boundary requires it
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialDraftGatewayTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialDraftGatewayCasContractTest.php`

**Interfaces:**
- `EditorialDraftGateway::update(int $postId, array $fields, string $expectedStateToken, string $captureId = ''): array` remains the typed boundary.
- The test store exposes read/write/revision/modified-time counters and callback traces so the stale path proves zero mutation.

- [ ] Step 1: Add a failing fixture-driven test covering correct token success, returned token change, and title/content/excerpt read-back.
- [ ] Step 2: Add failing repeated-stale-token tests covering three conflicts with unchanged state, modified time, revision count and latest revision ID.
- [ ] Step 3: Add a failing no-hidden-mutation test proving managed-section validation, native store update and reconciliation callbacks do not execute before a successful CAS comparison.
- [ ] Step 4: Run only the new CAS test file and confirm it fails because the current implementation does not provide the required observable ordering/atomic snapshot behavior.
- [ ] Step 5: Implement the smallest CAS gate: read one stable `EditorialPostState`, calculate/compare its token immediately, return the current snapshot on mismatch, and only then validate fields/managed expectations and enter the guarded native write.
- [ ] Step 6: Run the new CAS file and existing `EditorialDraftGatewayTest.php`; confirm all pass.
- [ ] Step 7: Run the publication and Capture continuation tests to ensure the stricter update gate does not change governed publication behavior.

### Task 2: Make Ability exposure and dispatch generically parity-checked

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php` only if a canonical callable-target helper is required
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpPublicationContinuationTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpAbilityDispatchParityTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php` when WordPress integration is available

**Interfaces:**
- `McpAbilityRegistration::abilityNameForTool(string $tool): ?string` remains the authoritative tool-to-Ability alias map.
- Add a public diagnostic contract such as `McpAbilityRegistration::callableParity(): array` returning one bounded row per connector-exposed tool with `tool`, `ability`, `registered`, `descriptor`, `dispatch_target`, `schema_parity`, and `reason_code`.
- `McpTransport::dispatch()` remains the canonical callable execution path and must preserve typed permission errors.

- [ ] Step 1: Add failing assertions that the canonical registry contains `nhk.article.publish.review`, its surfaced alias is `nhk-v3/article-publish-review`, the descriptor schema equals the callable Ability input schema, and the callable path reaches the review service.
- [ ] Step 2: Add a failing unauthorized-actor assertion that returns a typed capability failure and never `Unknown tool`.
- [ ] Step 3: Add a generic failing parity assertion for every exposed `nhk.article.*` descriptor rather than a Public Clock exception.
- [ ] Step 4: Run focused catalog/Ability/transport tests and capture the expected failure.
- [ ] Step 5: Implement a single catalog-derived callable target map/validator used by Ability registration diagnostics and tests; remove any descriptor-only success path.
- [ ] Step 6: Ensure the publication-review Ability is registered whenever its governed catalog entry is exposed, and route it through `executeMcp()` to the existing typed MCP transport without broadening capability policy.
- [ ] Step 7: Run focused tests, then the Easy MCP projection tests and guarded integration tests if the runtime is available.

### Task 3: Make generated release artifacts and deployment verification coherent

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDocumentationRegistry.php` only if release identity needs a canonical generated-resource version
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Demo/RemoteDeploymentAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Demo/RemoteMcpDocumentationVerifier.php`
- Modify: `tools/nhk-deploy-verify.php`
- Modify: `scripts/nhk-deploy-verify` only if argument/entrypoint behavior needs coverage
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/RemoteDeploymentAdapterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/RemoteMcpDocumentationVerifierTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/NhkDeployVerifyCliContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ReleaseTupleContractTest.php`

**Interfaces:**
- The release tuple contains `source_revision`/`build_identity`, `runtime_version`, `documentation_version`, `manifest_hash`, and generated MCP catalog/resource version.
- `RemoteDeploymentAdapter::deploy()` continues to return `StageResult` and the existing artifact fingerprint; the fingerprint must cover runtime code, immutable canonical docs snapshot, generated MCP resources, MU plugin and theme files that are transferred.
- `RemoteMcpDocumentationVerifier::verify()` remains read-only and returns typed `DOC_MANIFEST_MISMATCH`, `DEPLOYMENT_NOT_ACTIVE`, or `MCP_BOOTSTRAP_UNAVAILABLE` failures.

- [ ] Step 1: Add failing deterministic-generation and stale-artifact tests proving a source/docs/catalog change changes the expected release identity and an old snapshot cannot pass.
- [ ] Step 2: Add failing packaging assertions proving the transferred plugin artifact includes `resources/canonical-docs/manifest.json`, all manifest files, and generated MCP resources/catalogs together.
- [ ] Step 3: Add failing verifier assertions comparing the full local/remote tuple, including catalog/resource version and per-file hashes; mismatch must not return PASS.
- [ ] Step 4: Run the focused release tests and capture the expected failure.
- [ ] Step 5: Implement the minimal authoritative release tuple derivation and package inventory check using existing `McpDocumentationRegistry`, `McpToolCatalog`, and `RemoteDeploymentAdapter` boundaries.
- [ ] Step 6: Make deploy verification compute expected identity only after `composer install` and `composer generate:mcp-docs`, reject dirty/stale generated output, and compare the same tuple after remote MCP read-back.
- [ ] Step 7: Add cache/version fields to the verifier contract so a newer release cannot be mistaken for a stale connector resource; do not automate host-specific OPcache/FPM restarts.
- [ ] Step 8: Run focused docs/deployment tests and the wrapper in the current dirty checkout; verify it fails closed without transfer and without reporting success.

### Task 4: Add the end-to-end local acceptance scenario

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/PublicClockLiveBlockersAcceptanceTest.php`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Read: `docs/architecture/V2_V3_PARITY_MATRIX.md`

**Interfaces:**
- The scenario composes an in-memory editorial store, `EditorialDraftGateway`, `McpTransport`, catalog/Ability parity diagnostics, and a deterministic release verifier fixture. It does not use Public Clock IDs or mutate real WordPress data.

- [ ] Step 1: Add the failing five-phase acceptance scenario: bootstrap tuple, descriptor/dispatch parity, fresh draft update then stale retry, publication review outcome, and generic exposed-tool parity.
- [ ] Step 2: Run the scenario and confirm each failure is localized to the missing blocker contract.
- [ ] Step 3: Wire the fixed boundaries into the scenario and run it green.
- [ ] Step 4: Update execution state with actual local evidence, explicitly retaining `LIVE_PUBLIC_CLOCK_COMPLETE=NO` until the user deploys and performs fresh remote read-back.

### Task 5: Verification checkpoint and commit

**Files:**
- All implementation/test/docs files changed by Tasks 1–4 only.

- [ ] Step 1: Run focused CAS, Article publication, Easy MCP, MCP dispatch, generated catalog, documentation, deployment and Capture continuation suites.
- [ ] Step 2: Run the full relevant Composer/PHPUnit suite and record exact environment-gated failures without downgrading them.
- [ ] Step 3: Run PHP lint, `composer validate --no-check-publish`, `git diff --check`, and a changed-scope secret review.
- [ ] Step 4: Re-read `docs/architecture/V3_EXECUTION_STATE.md` and `docs/architecture/V2_V3_PARITY_MATRIX.md`; ensure no live deployment or Public Clock completion is claimed.
- [ ] Step 5: Stage only bounded implementation/tests/plan/execution-state changes and commit with a logical message.

## Plan self-review

- Spec coverage: Tasks 1–2 cover the five-layer CAS and Ability/dispatcher requirements; Task 3 covers deterministic docs generation, package transfer, tuple read-back and cache/version invalidation; Task 4 covers the required local live-flow scenario; Task 5 covers all gates and the commit.
- Placeholder scan: every step names the concrete test or implementation boundary and uses no `TBD`/`TODO` placeholders.
- Type consistency: the existing `StageResult`, `EditorialDraftGateway`, `McpAbilityRegistration`, `McpTransport`, `McpDocumentationRegistry`, and `RemoteMcpDocumentationVerifier` signatures are preserved unless a new diagnostic helper is explicitly named in its task.
