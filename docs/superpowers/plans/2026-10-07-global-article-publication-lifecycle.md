# Global Article Publication Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (native) or superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every Capture-owned Article follow a native-route-first, governed publish-then-verify lifecycle with optional TEXT_ARTICLE media and idempotent stale MediaUsage cleanup.

**Architecture:** Keep `ArticlePublicationGate` as the pre-publication readiness boundary and keep native WordPress mutation inside `OwnerPublicationApplicationService`/`EditorialDraftGateway`. Move frontend verification semantics to the post-publish continuation boundary, add structured native route validation evidence, and preserve existing Capture subject resolution, Governance, CAS, receipt, and idempotency owners.

**Tech Stack:** PHP 8+, WordPress plugin runtime, PHPUnit, existing NHK Core domain/application/infrastructure boundaries, WPDB repositories.

**Spec:** `docs/superpowers/specs/2026-10-07-article-publication-lifecycle-design.md`

## Global Constraints

- `wp_posts` remains the sole owner of Article title, body, dates, editorial status, and native editorial URL.
- `ArticlePublicationGate` remains the only readiness gate; all publication writes continue through the typed owner publication service.
- Governance, Capture ownership, canonical subject binding, CAS, and idempotency remain mandatory.
- `TEXT_ARTICLE` may publish with zero images; no placeholder or unrelated Media may be created.
- Route defects remain system blockers; draft frontend unavailability is not a route defect by itself.
- A successful native publish followed by failed frontend verification is bounded verification-required/PARTIAL, never `COMPLETE`, and never a second publish.
- MediaUsage removal is logical retirement; the usage must disappear from active read-back while historical state remains auditable.
- No migration, staging/production mutation, V2 mutation, legacy Article-body import, direct SQL writer, or Post-specific branch is allowed.

## Review Focus

- A draft with no anonymous frontend response must still pass native route preflight: test in `ArticlePublicationGateTest` and `ArticlePublicationContinuationCommandTest`.
- A native permalink collision or resolver disagreement must remain a hard blocker: test in the route-preflight/gate suite.
- A publish transport exception after the native transition must not duplicate the Post: test in `OwnerPublicationApplicationServiceTest`.
- A stale usage whose Media row is missing must be removable without resolving/fabricating Media: test in `MediaBindingServiceTest`.
- An explicit subject must not be displaced by related terms, and ambiguous subject input must stop before Article creation: test in `CaptureSubjectBindingRecoveryTest`/Article pre-create coverage.

## File map

- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/NativeArticleRoutePreflight.php` — deterministic native `wp_post` route evidence boundary.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php` — consume route evidence and keep draft frontend verification out of the gate.
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticlePublicationOutcome.php` and `PublicationDiagnosticRegistry.php` — represent bounded post-publish verification state and diagnostics.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/OwnerPublicationApplicationService.php` — preserve governed native publish, receipt idempotency, and final native read-back.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationContinuationCommand.php` — classify post-publish public verification as PARTIAL/verification-required and persist evidence without republishing.
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` — wire the shared native route evidence and existing public read-back callbacks through the canonical composition path.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php` — remove stale bindings without resolving a missing Media.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php` only if current evidence lacks the structured native route packet; do not add a second subject resolver.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`, `OwnerPublicationApplicationServiceTest.php`, `McpPublicationContinuationTest.php`, `ArticlePublicationContinuationCommandTest.php`, `MediaBindingServiceTest.php`, and existing subject-resolution tests.
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`, `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`, and `docs/architecture/V3_EXECUTION_STATE.md` after implementation verification.

### Task 1: Add deterministic native route preflight and gate semantics

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Article/NativeArticleRoutePreflight.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php` if needed to emit the packet
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`

**Interfaces:**
- `NativeArticleRoutePreflight::check(EditorialPostState $draft): array` returns `['ready'=>bool, 'diagnostics'=>list<string>, 'slug'=>string, 'permalink'=>string, 'canonical_url'=>string, 'collision'=>bool, 'resolver_agreement'=>bool]`.
- The preflight receives injected route/collision callables so unit tests do not require WordPress globals; the production composition supplies native WordPress resolvers.
- `ArticlePublicationGate::check()` continues accepting the existing evidence array and treats `native_route` as the authoritative structured route packet when present.

- [ ] **Step 1: Write failing route and lifecycle tests.** Add tests for valid native route, empty/invalid slug, route collision, resolver disagreement, canonical URL mismatch, and a draft with `rendered_public_verification=false`/unavailable that remains eligible when all pre-publication checks pass. Add the TEXT_ARTICLE no-media warning assertions if not already complete.
- [ ] **Step 2: Run the focused gate test and verify RED.**

  Run: `vendor/bin/phpunit -c phpunit.xml --filter 'ArticlePublicationGateTest'` from `public/wp-content/plugins/nhk-core`.

  Expected: the new route and lifecycle assertions fail for the missing structured route behavior, not because of a test/bootstrap error.
- [ ] **Step 3: Implement `NativeArticleRoutePreflight::check()` and gate changes.** Preserve the existing native `postId`/slug/permalink checks as fallback safety, reject any explicit false route packet with `PUBLIC_ROUTE_NOT_READY`, and remove only the pre-publication requirement for rendered/public frontend verification. For TEXT_ARTICLE, ensure missing real-image support is warning-only; retain IMAGE_ARTICLE hard blockers.
- [ ] **Step 4: Wire route evidence through the canonical Capture publication context.** Use the existing native post reader/permalink resolver and route-collision infrastructure; do not require semantic Public Identity for `wp_post`.
- [ ] **Step 5: Run the focused gate tests and verify GREEN.**

  Run: `vendor/bin/phpunit -c phpunit.xml --filter 'ArticlePublicationGateTest'`.

  Expected: all focused gate tests pass, including hard route failures and optional TEXT_ARTICLE media.
- [ ] **Step 6: Commit the task.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Article/NativeArticleRoutePreflight.php public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php public/wp-content/plugins/nhk-core/src/Plugin.php
  git commit -m "fix: validate native article routes before publication"
  ```

### Task 2: Complete publish-then-verify lifecycle without duplicate publication

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Article/ArticlePublicationOutcome.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Article/PublicationDiagnosticRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/OwnerPublicationApplicationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationContinuationCommand.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/OwnerPublicationApplicationServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationContinuationCommandTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpPublicationContinuationTest.php`

**Interfaces:**
- Add `ArticlePublicationOutcome::PARTIAL` for a native publish whose final public verification is not complete.
- `ArticlePublicationContinuationCommand::finish()` returns `outcome=PARTIAL`, `final_outcome=verification_required`, `diagnostics` containing the exact registered public-readback diagnostic, and `post`/`public_url` for the already-published Post.
- Completed receipts remain the only source for `COMPLETE`; PARTIAL results never create a `:completed` decision.

- [ ] **Step 1: Write failing tests.** Prove successful native publish plus successful public read-back returns PASS/COMPLETE; successful native publish plus failed/unavailable public read-back returns PARTIAL/verification-required; a retry with the same idempotency key performs zero additional publish calls and returns the same Post identity.
- [ ] **Step 2: Run the focused publication tests and verify RED.**

  Run: `vendor/bin/phpunit -c phpunit.xml --filter 'OwnerPublicationApplicationServiceTest|ArticlePublicationContinuationCommandTest|McpPublicationContinuationTest'`.

  Expected: post-publish failure currently returns SYSTEM_BLOCKED or false completion, demonstrating the lifecycle defect.
- [ ] **Step 3: Implement the minimal outcome/diagnostic changes.** Register stable diagnostics for unavailable and failed final public read-back. Keep system-blocked classification for pre-publish route and invariant failures.
- [ ] **Step 4: Update the continuation and owner service read-back flow.** Perform native read-back before public verification, preserve exact Post identity and permalink, persist body-free evidence, and ensure completed/idempotent replay paths never call the native writer again. Reuse the existing `RenderedArticleVerifier`/public callback seam instead of adding a second HTTP client.
- [ ] **Step 5: Run focused publication tests and verify GREEN.**

  Run: `vendor/bin/phpunit -c phpunit.xml --filter 'OwnerPublicationApplicationServiceTest|ArticlePublicationContinuationCommandTest|McpPublicationContinuationTest'`.

  Expected: all lifecycle, failure, and idempotency assertions pass.
- [ ] **Step 6: Commit the task.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Domain/Article/ArticlePublicationOutcome.php public/wp-content/plugins/nhk-core/src/Domain/Article/PublicationDiagnosticRegistry.php public/wp-content/plugins/nhk-core/src/Application/Article/OwnerPublicationApplicationService.php public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationContinuationCommand.php public/wp-content/plugins/nhk-core/tests/Unit/OwnerPublicationApplicationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationContinuationCommandTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpPublicationContinuationTest.php
  git commit -m "fix: complete article publication after public verification"
  ```

### Task 3: Make stale MediaUsage cleanup idempotent and fail closed

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceTest.php`

**Interfaces:**
- `MediaBindingService::mutate()` keeps the existing exact target, `usage_id`, and `expected_usage_revision` contract.
- For `operation=remove`, the implementation may skip `resolveMedia()` after exact usage lookup; it must return a verified read-back showing no active usage for the target/role/placement.

- [ ] **Step 1: Write failing tests.** Add a missing-Media repository fixture with an existing exact usage and assert remove succeeds, marks the usage retired, emits normal invalidation, and returns active read-back absent. Add an idempotent repeated remove assertion and retain a revision-conflict assertion.
- [ ] **Step 2: Run the focused MediaBindingService tests and verify RED.**

  Run: `vendor/bin/phpunit -c phpunit.xml --filter 'MediaBindingServiceTest'`.

  Expected: the stale-Media removal currently fails with `MEDIA_BINDING_MEDIA_NOT_FOUND`.
- [ ] **Step 3: Implement the remove-only path.** Resolve target and usage/revision first; retire the usage without fabricating or resolving Media; verify no active matching usage remains; keep add/replace behavior unchanged and reject uncertain target/revision state.
- [ ] **Step 4: Run focused MediaBindingService tests and verify GREEN.**
- [ ] **Step 5: Commit the task.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceTest.php
  git commit -m "fix: clean up stale article media usage safely"
  ```

### Task 4: Lock primary-subject precedence and unresolved Capture behavior

**Files:**
- Modify only the existing subject-resolution/pre-create implementation identified by the failing tests; likely `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureSubjectBinding.php`, `EditorialDraftGateway.php`, or the current resolver boundary.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureSubjectBindingRecoveryTest.php` and the nearest Article pre-create test.

**Interfaces:**
- Reuse the existing `SubjectResolutionPacket`/Capture subject-binding API and its current canonical UUID/stable-key fields.
- No new resolver, Authority type, UUID generation, or persistence owner may be introduced.

- [ ] **Step 1: Write failing tests.** Add one ambiguous TEXT_ARTICLE subject case that remains `REVIEW_REQUIRED` before `createDraft`, and one explicit primary subject plus related terms case that returns the explicit canonical subject as the sole primary with related terms retained as secondary context.
- [ ] **Step 2: Run the focused subject/pre-create tests and verify RED.**

  Run: `vendor/bin/phpunit -c phpunit.xml --filter 'CaptureSubjectBindingRecoveryTest|EditorialDraftGatewayTest|HierarchicalSubjectResolutionVerificationTest'`.

  Expected: the new assertions fail only if the current boundary promotes or drops the wrong candidate.
- [ ] **Step 3: Implement the smallest precedence correction at the shared boundary.** Preserve canonical UUID/stable-key precedence, require semantic evidence for inferred candidates, and keep unresolved ambiguity review-only.
- [ ] **Step 4: Run focused subject/pre-create tests and verify GREEN.**
- [ ] **Step 5: Commit the task.**

  ```bash
  git add <only-the-subject-boundary-files-and-tests-changed-by-this-task>
  git commit -m "fix: preserve canonical article primary subject precedence"
  ```

### Task 5: Update canonical documentation and execution evidence

**Files:**
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test if needed: existing documentation contract tests under `public/wp-content/plugins/nhk-core/tests/Unit/`

- [ ] **Step 1: Write/update documentation contract assertions if existing tests cover these sections.** Assert the docs state native route authority, no draft frontend prerequisite, post-publish verification, PARTIAL behavior, TEXT_ARTICLE optional media, and stale MediaUsage cleanup without inventing new operations.
- [ ] **Step 2: Run the documentation tests and verify RED if behavior text is still stale.**
- [ ] **Step 3: Update the canonical docs only to match the implemented behavior.** Record exact focused test counts, lint/static results, data-safety status, and the implementation commit(s) in `V3_EXECUTION_STATE.md`; do not claim live Post 766 publication or public deployment.
- [ ] **Step 4: Run documentation tests and verify GREEN.**
- [ ] **Step 5: Commit the documentation checkpoint.**

  ```bash
  git add docs/architecture/ARTICLE_INGEST_CONTRACT.md docs/mcp/MCP_V3_CONTENT_OPERATIONS.md docs/architecture/V3_EXECUTION_STATE.md
  git commit -m "docs: record article publication lifecycle contract"
  ```

### Task 6: Full verification and bounded fixture review

**Files:**
- No planned product-code changes; only verification output and, if necessary, narrowly scoped test fixes.

- [ ] **Step 1: Run all focused publication/Capture/Media tests.**

  Run: `vendor/bin/phpunit -c phpunit.xml --filter 'ArticlePublication|OwnerPublication|McpPublication|MediaBinding|CaptureSubject|EditorialDraftGateway|HierarchicalSubject'`.

- [ ] **Step 2: Run the relevant broader unit/contract suites.** Record pre-existing failures separately; do not hide or downgrade them.
- [ ] **Step 3: Run PHP lint on every changed PHP file, `git diff --check`, and the repository secret review.**
- [ ] **Step 4: Read `docs/architecture/V3_EXECUTION_STATE.md` again and record the verification checkpoint before any live action.**
- [ ] **Step 5: Perform only a read-only publication review of existing Post 766 unless the exact current TEST runtime, fresh documentation/build identity, signed bounded acceptance packet, and required credentials are all available.** Confirm missing images are warnings and `PUBLIC_ROUTE_NOT_READY` is absent only when the native route evidence is genuinely valid. Do not autonomously publish or mutate staging/production without those fail-closed prerequisites.
- [ ] **Step 6: Commit any final verification-only documentation evidence if needed, then report exact changed files, test results, commit hashes, and any runtime gate that prevented live mutation.**

## Self-review

- Spec coverage: lifecycle order is Task 1/2; route hard blockers Task 1; TEXT_ARTICLE media Task 1; stale MediaUsage Task 3; subject precedence Task 4; idempotency and PARTIAL Task 2; documentation Task 5; verification and Post 766 safety Task 6.
- Step scan: each task has a failing-test step, focused RED run, minimal implementation, GREEN run, and commit; documentation/verification tasks have explicit checkable commands.
- Type consistency: route packet is `array<string,mixed>` from `NativeArticleRoutePreflight::check`; gate consumes `native_route`; PARTIAL is an `ArticlePublicationOutcome` value; MediaUsage keeps existing exact usage/revision inputs.
- Review focus coverage: all five focus items have named tests and owning tasks.
- Proportion: the plan adds no new semantic owner, endpoint, operation, migration, or parallel publication writer.
