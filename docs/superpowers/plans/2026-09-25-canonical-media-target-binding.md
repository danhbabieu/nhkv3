# Canonical Media Target Binding and MediaUsage Reuse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Make one existing canonical Media reusable through governed MCP MediaUsage operations across WordPress Posts and registered Authority targets, with canonical target normalization, target-local replacement, projection-safe featured updates and final reverse read-back.

**Architecture:** Add one Media target reference value object and normalizer backed by the existing EndpointTypeRegistry/resolver boundary, while keeping Authority EntityTypeRegistry and WordPress editorial ownership separate. Normalize before proposal fingerprints, staging packets and persistence; keep MediaBindingService as the sole MediaUsage writer; route wp_post featured projection through a narrow attachment-owner method that never invokes Article recomposition.

**Tech Stack:** PHP 8.5, WordPress, PHPUnit 11, existing NHK Core repositories, MCP transport/catalog, Governance/Controlled Apply and guarded integration fixtures.

**Spec:** docs/superpowers/specs/2026-09-25-canonical-media-target-binding-design.md

## Global Constraints

- Preserve WordPress wp_posts as the sole owner of editorial title, body, excerpt, slug, category and publication state.
- Preserve separate Media, MediaAsset, MediaUsage and WordPress attachment boundaries.
- Keep semantic durable mutation on Proposal → Approval → Eligibility → Controlled Apply → canonical owner → durable audit.
- Unknown type, endpoint, role, placement, operation or field fails closed.
- Normalize canonical UUID/stable-key, endpoint key, revision, provenance and idempotency before proposal/persistence.
- Do not create a Graph edge for ordinary MediaUsage.
- Do not create a new Media or attachment when reusing an existing Media.
- Do not create a second generic relationship table or direct WordPress writer.
- Do not rewrite Article title/body/excerpt/slug/category/semantic subject for a Media-only request.
- Do not expand the Authority catalog with wp_post; use the existing registered wp_post endpoint.
- Do not mutate V2, production, staging or live acceptance data in this phase.
- Preserve unrelated existing worktree changes; never reset, clean, checkout or restore them.

## Review Focus

- Same Media bound to two Posts creates two target-local usages and both reverse-lookup entries — Task 2.
- Same idempotency key with changed normalized target or Media conflicts, while equivalent Post forms converge — Tasks 1 and 3.
- wp_post is rejected as an Authority target but accepted as a MediaUsage endpoint only when the Post exists and is not trashed — Tasks 1 and 2.
- Featured-only projection does not rewrite Article editorial or semantic fields — Task 5.
- Staging packets bind normalized endpoint key, role, placement, revision and Capture identity — Task 4.

## File Map

- Create MediaTargetReference.php, MediaTargetRegistry.php and MediaTargetNormalizer.php in src/Application/Media.
- Modify MediaBindingService.php to consume normalized targets, preserve target-local replacement history and read back affected identities.
- Modify McpToolCatalog.php and McpTransport.php to advertise and normalize one target shape for nhk.media.bind and nhk.media.usage.
- Modify MediaBindingStagingAdmission.php, MediaBindingStagingGuard.php and StagingAcceptanceScopeVerifier.php to compare normalized target packets.
- Modify WordPressMediaAttachmentBridge.php to expose a featured-only projection owner that reads and verifies the editorial token.
- Modify AuthorityProposalExecutor.php and Plugin.php only where existing wiring must pass the endpoint registry or projection callback into the canonical owner.
- Add focused Unit/Integration tests without changing existing fixtures into authorization.
- Update V3_EXECUTION_STATE.md only at a verified checkpoint and make no live-acceptance claim.

---

### Task 1: Canonical Media target reference and normalizer

**Files:**
- Create: public/wp-content/plugins/nhk-core/src/Application/Media/MediaTargetReference.php
- Create: public/wp-content/plugins/nhk-core/src/Application/Media/MediaTargetRegistry.php
- Create: public/wp-content/plugins/nhk-core/src/Application/Media/MediaTargetNormalizer.php
- Modify: public/wp-content/plugins/nhk-core/src/Plugin.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/MediaTargetNormalizerTest.php

**Interfaces:**
- Consume existing EndpointTypeRegistry, EntityTypeRegistry, AuthorityRepository and registered endpoint resolvers.
- Produce an immutable reference with endpointType, endpointKey, nullable canonicalUuid, nullable stableKey and positive revision.
- Expose normalize(array target): MediaTargetReference and normalizeRequestTarget(array request): array.

- [ ] Write failing tests for Authority UUID, Authority stable key, wp_post blog_id/post_id, wp_post canonical id, unknown type, malformed key, missing Post and trashed Post. Assert distinct invalid-type, malformed-reference and target-not-found diagnostics.
- [ ] Run the new test alone and confirm RED is caused by the missing normalizer behavior.
- [ ] Implement the value object and registry-backed normalizer. Use EndpointTypeRegistry::resolver() for endpoint existence; do not add wp_post to CanonicalEntityTypeCatalog.
- [ ] Wire the already-constructed endpoint registry into the Media binding runtime without adding a second endpoint allow-list.
- [ ] Run the focused test and PHP lint for all three new PHP files.
- [ ] Commit with: git commit -m "feat: add canonical media target normalization"

### Task 2: MediaUsage reuse and target-local replacement

**Files:**
- Modify: public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php
- Modify only if required by verified schema behavior: public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WpdbMediaUsageRepository.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceTest.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceGenericOwnerTest.php
- Create: public/wp-content/plugins/nhk-core/tests/Unit/MediaUsageReuseAcceptanceTest.php

**Interfaces:**
- Consume MediaTargetNormalizer::normalize() from Task 1.
- Keep MediaBindingPort::bind() and MediaBindingService::mutate() as canonical MediaUsage write entry points.
- Produce receipts with usage_id, previous_usage_id, normalized target, post-apply revision and canonical read-back.

- [ ] Add failing tests for one Media bound to wp_post 1:18 and 1:19, reverse lookup returning both, and Model representative plus Variant technical_detail coexisting with Post usages.
- [ ] Add failing tests for OLD to Media A replacement on featured_primary: old usage retired/demoted and retained, A active, unrelated usages unchanged, replay idempotent, changed payload conflicts and stale revision fails.
- [ ] Run the new test and confirm RED against the current in-place replacement or wp_post rejection.
- [ ] Normalize targets before binding operation fingerprints. Keep representative_bind Authority-only unless an existing capability explicitly supports another endpoint.
- [ ] Implement target-local replacement: require exact usage UUID/revision, retire/demote old usage, create one active replacement, preserve history and never use Media-global uniqueness.
- [ ] Extend read-back to verify target-local active cardinality and reverse lookup by Media.
- [ ] Run focused MediaBinding, MediaUsage placement and persistence tests plus PHP lint.
- [ ] Commit with: git commit -m "feat: preserve reusable media usages across targets"

### Task 3: MCP schema, transport and Governance payload convergence

**Files:**
- Modify: public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php
- Modify: public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php
- Modify only if schema parity requires it: public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php
- Modify only if normalized apply binding requires it: public/wp-content/plugins/nhk-core/src/Application/Governance/AuthorityProposalExecutor.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/McpSchemaParityTest.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/McpTransportBoundaryTest.php
- Create: public/wp-content/plugins/nhk-core/tests/Unit/McpMediaUsageTargetConvergenceTest.php

**Interfaces:**
- Consume the Task 1 normalizer and Task 2 MediaBinding owner.
- Produce identical canonical target payloads from Post fields and canonical id.
- Preserve current capability and internal/admin guards.

- [ ] Add failing tests for advertised wp_post forms, invalid arbitrary targets, and equivalent Post forms producing one canonical proposal payload/fingerprint.
- [ ] Run the new test alone and verify RED.
- [ ] Update catalog and Ability schema from the single target contract, retaining strict bounds and additionalProperties false.
- [ ] In mediaUsage(), resolve Media, normalize target, replace request target with canonical type/id, then create the Governance proposal. Keep target_uuid null for wp_post and preserve Authority UUID for Authority targets.
- [ ] Run MCP schema, transport, Governance and proposal eligibility focused tests.
- [ ] Commit with: git commit -m "feat: converge MCP media usage target contracts"

### Task 4: Staging packet and admission convergence

**Files:**
- Modify: public/wp-content/plugins/nhk-core/src/Application/Governance/MediaBindingStagingAdmission.php
- Modify: public/wp-content/plugins/nhk-core/src/Application/Governance/MediaBindingStagingGuard.php
- Modify: public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScopeVerifier.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingStagingAdmissionTest.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/StagingAcceptanceScopeVerifierTest.php
- Create: public/wp-content/plugins/nhk-core/tests/Unit/MediaUsageTargetScopeConvergenceTest.php

**Interfaces:**
- Consume the Task 1 normalizer.
- Produce one signed scope target with wp_post id 1:post_id and Authority id canonical UUID, plus exact operation, role, placement, revision, Capture identity, capability, expiry and payload fingerprint.

- [ ] Add failing tests issuing a scope from blog_id/post_id, accepting canonical id, and rejecting wrong Post, Media, role, placement, revision, Capture fingerprint, changed payload and missing signature.
- [ ] Run the new test and verify RED.
- [ ] Replace duplicated Post-key construction in verifier, admission and guard with the shared normalizer while retaining signature, expiry and capability checks.
- [ ] Run staging, Authority admission and Capture dependency focused tests.
- [ ] Commit with: git commit -m "fix: bind media staging scopes to normalized targets"

### Task 5: WordPress featured-only projection and Article immutability

**Files:**
- Modify: public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php
- Modify only if required after MediaUsage apply: public/wp-content/plugins/nhk-core/src/Application/Governance/AuthorityProposalExecutor.php
- Modify: public/wp-content/plugins/nhk-core/src/Plugin.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php
- Create: public/wp-content/plugins/nhk-core/tests/Unit/FeaturedMediaOnlyProjectionTest.php
- Create guarded test: public/wp-content/plugins/nhk-core/tests/Integration/MediaUsageWordPressProjectionIntegrationTest.php

**Interfaces:**
- Consume applied canonical MediaUsage for wp_post + featured_primary and the existing Media-to-attachment mapping.
- Produce verified featured attachment projection and unchanged editorial state token/content fields.

- [ ] Add a failing regression test snapshotting title, body, excerpt, slug, categories and semantic subject, then applying only a featured MediaUsage replacement and asserting only usage/featured attachment changes.
- [ ] Run the regression test and verify RED if the broad Article composition path is entered.
- [ ] Add a narrow bridge method accepting Post ID, canonical Media ID, expected editorial token and resolved attachment representation. Read current Post, CAS the token, project only featured attachment when different, and return attachment/Media/editorial read-back. Do not inspect or rewrite inline content.
- [ ] Invoke the method only after MediaUsage owner success. Projection failure must prevent a false complete result.
- [ ] Run focused Article, Capture and Media tests.
- [ ] Run guarded Integration only when NHK_WP_TEST_PATH and NHK_WP_TEST_DB identify exact nhk_v3_test; otherwise record environment blocked and do not substitute another database.
- [ ] Commit with: git commit -m "fix: isolate featured media projection from article composition"

### Task 6: Reverse lookup, full verification and execution-state checkpoint

**Files:**
- Modify only if reader-safe fields are incomplete: public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php
- Modify only if final read-back exposes a verified gap: public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php
- Test: public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingReadbackContractTest.php
- Create: public/wp-content/plugins/nhk-core/tests/Unit/MediaReverseLookupAcceptanceTest.php
- Modify: docs/architecture/V3_EXECUTION_STATE.md

**Interfaces:**
- Consume all prior target, owner, staging and projection boundaries.
- Produce the requested report fields and exact verification evidence without claiming live acceptance.

- [ ] Add failing tests asserting nhk.media.get exposes all independent usages with target type/key, role, placement, active state and revision; target lookup must return the active usage for 1:18 and preserve retired history where the current reader policy allows it.
- [ ] Run the new test and verify RED.
- [ ] Implement only missing reader/read-back behavior using listByMediaId() and listByEndpoint(); do not add a second relationship store.
- [ ] Run the complete focused suite covering normalizer, binding, reuse, reverse lookup, staging, MCP, Article and Capture.
- [ ] Run PHP lint on every changed PHP file from the final diff.
- [ ] Run Unit with memory_limit=512M and guarded Integration. Record exact counts, warnings, deprecations and environmental blockers; unavailable integration is not a pass.
- [ ] Run git diff --check, secret review and hostile diff review for owner bypass, duplicate authority, Article recomposition, Media-global uniqueness, registry drift, false read-back and uncallable MCP schema.
- [ ] Append an evidence-only checkpoint to V3_EXECUTION_STATE.md with ROOT_CAUSE, ARCHITECTURE_DECISION, FILES_CHANGED, TARGET_TYPES_SUPPORTED, WP_POST_NORMALIZATION, MEDIA_REUSE_MODEL, GRAPH_RELATION_POLICY, GOVERNANCE_PATH, REGRESSION_TESTS, FOCUSED_TESTS, FULL_UNIT, INTEGRATION, COMMIT and REMAINING_BLOCKERS.
- [ ] Commit with: git commit -m "test: verify canonical media target binding"

## Completion Report Format

ROOT_CAUSE=
ARCHITECTURE_DECISION=
FILES_CHANGED=
TARGET_TYPES_SUPPORTED=
WP_POST_NORMALIZATION=
MEDIA_REUSE_MODEL=
GRAPH_RELATION_POLICY=
GOVERNANCE_PATH=
REGRESSION_TESTS=
FOCUSED_TESTS=
FULL_UNIT=
INTEGRATION=
COMMIT=
REMAINING_BLOCKERS=

Do not claim the concrete Media 01a0d7ee-3e33-7366-88c6-287112b34936 / Post 18 live case passed unless the exact external runtime was read back after a governed, explicitly authorized acceptance mutation.
