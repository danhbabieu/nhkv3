# P0 Capture → Subject → Article Media → Visual Support → Publication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (native implementation in this session) or superpowers:subagent-driven-development. Steps use checkbox syntax for tracking.

**Goal:** Make the generic `IMAGE_ARTICLE` Capture pipeline resolve one canonical subject, reconcile explicit Article Media through governed MediaUsage, tolerate optional Visual Support gaps, allocate a native WordPress slug, and complete publication/read-back without Article-specific production branches.

**Architecture:** Keep `wp_posts` as the native editorial owner and use one shared source-ranked resolver plus one immutable `SubjectResolutionPacket` as the handoff to Article, Media, Knowledge/Graph planning, Visual Support, and publication. Keep MediaUsage and native attachment projection as separate read-back proofs, issue Capture-bound child admission internally, and make route readiness a native WordPress lifecycle independent of semantic PublicIdentity.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress adapter/repositories, NHK V3 runtime registries, Governance Proposal → Eligibility → Controlled Apply lifecycle, guarded integration tests.

**Spec:** `docs/superpowers/specs/2026-09-22-p0-capture-subject-media-publication-design.md`

## Global Constraints

- Production code must not branch on Article, attachment, entity, person or fixture values from the canary.
- No direct SQL semantic owner writes, generic WordPress writer bypass, Governance bypass, duplicate Article/Media, forced `_thumbnail_id`, forced publish status, legacy body migration, or invented Authority/Knowledge/Evidence.
- Canonical Capture is the only normal new Article entry point; existing Article replay remains one Capture/one Article.
- Durable semantic mutation remains Proposal → approval/policy → eligibility → Controlled Apply → canonical read-back.
- `USER_EXPLICIT > SYSTEM_AUTO > HISTORICAL_REUSABLE` for current Article Media selection.
- Missing optional Visual Support is enrichment; invalid inferred candidates are diagnostic/review only.
- Native WordPress owns Article slug/permalink; semantic PublicIdentity is not required for a normal `wp_post` route.
- Development DB `nhk_v3` is read-only for health/inspection/UP checks; destructive integration operations are restricted to exact `nhk_v3_test`.
- Article 636 reconciliation is forbidden until the generic system fix has its own commit and approved deployment/runtime verification.

## Review Focus

- Explicit hints that all fail must not promote a body entity — Task 1 tests unresolved/review precedence.
- Explicit current Media must beat stale featured and inline history while preserving editorial-only semantics — Task 2 tests full replacement/read-back.
- Capture must issue its own exact MediaUsage admission while standalone writers remain blocked — Task 3 tests both paths.
- Unknown Visual Support candidates must not invalidate a valid Article — Task 4 tests fail-soft validation and malformed diagnostics.
- Empty draft slugs must become unique native WordPress routes without semantic PublicIdentity — Task 5 tests allocation/read-back and publication phases.

## Root-cause map from current repository audit

- **Subject:** `SubjectResolutionService::resolve()` flattens all hints and then ranks resolved records by match/type; it does not model source buckets, explicit-hint failure, or caller-order tie breaking. `CanonicalAuthoritySubjectResolver` provides exact-name/alias and stable-key lookup but has no body-vs-explicit source boundary. Multiple tests and composition paths construct `SubjectResolutionService` independently.
- **Media:** `ArticleMediaCoordinator` and Capture continuation already contain precedence/read-back primitives, but `Plugin` wires multiple coordinator instances and the full Capture path still needs a generic proof that current explicit selection removes stale managed placements and verifies both MediaUsage plus native attachment state. Article media must remain editorial usage and not trigger semantic representative/evidence promotion.
- **Staging scope:** `MediaBindingService` is guarded by `MediaBindingStagingGuard`, while Capture owns enough exact values to issue `MediaBindingStagingAdmission`; the missing proof is the canonical child-admission handoff from Capture rather than caller-supplied scope, without weakening standalone fail-closed behavior.
- **Visual Support:** `VisualSupportRequirementService::require()` assumes callers have already validated scope/facet/feature/intent; coordinator-level validation is incomplete, so malformed inferred candidates can reach persistence or publication failure paths.
- **Publication route:** `ArticlePublicationGate` currently reports route and semantic identity failures from evidence; native Article route readiness must be supplied by the WordPress draft gateway and separated from semantic PublicIdentity requirements, with slug allocation/read-back before publish and permalink/rendered read-back after publish.
- **Composition:** `Plugin.php` is a large composition root with independent construction of Subject, Article Media, Visual Support, and publication services. The repair must converge runtime wiring and adapt existing child constructors instead of adding a parallel resolver/writer.

### Task 1: Source-ranked canonical subject resolution

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SubjectResolutionService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/CanonicalAuthoritySubjectResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Capture/SubjectResolutionPacket.php` only if packet provenance/source-rank fields are required by existing persistence shape
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` to establish/rehydrate one packet and pass it downstream
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` and any `GovernanceRuntimeFactory` composition path to share the resolver
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`, `SubjectResolutionServiceTest.php` if present, and one new generic regression test file if no focused home exists

**Interfaces:**
- Consume typed source buckets: `canonical_uuid`, `stable_key`, `subject_hints`, `title_subject`, `body_mentions`.
- Produce the existing resolution array shape plus a single immutable `SubjectResolutionPacket`; downstream code receives the packet and does not rerun primary selection.

- [ ] Write failing tests for explicit Model vs body-known Music, explicit unknown vs body-known entity, multiple hints preserving caller order, exact alias/name beating fuzzy candidates within a tier, and packet identity surviving continuation.
- [ ] Run focused subject tests and confirm the current flattened/ranked resolver fails at least the explicit-unknown and source-order cases.
- [ ] Implement the smallest source-bucket policy: UUID, stable key, explicit hints, title/topic, then body fallback; if explicit hints are non-empty and unresolved, return unresolved/review and never body-promote.
- [ ] Re-run focused tests, then inspect all resolver construction sites and adapt them to the shared policy/adapter without changing fixture semantics.
- [ ] Run the existing Capture semantic suite and commit the subject boundary with a message such as `fix: preserve explicit capture subject precedence`.

### Task 2: Explicit Article Media precedence and reconciliation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php` only where current-selection context is normalized
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` to pass the packet/current manifest and reconcile stale managed usage
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/WordPress/*` media attachment bridge used by Article Media
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`, `MediaBindingServiceTest.php`, and a generic IMAGE_ARTICLE orchestration test

**Interfaces:**
- Consume current Capture Media manifest with Media UUID/revision, role, placement, selection source, and expected owner revision.
- Produce canonical MediaUsage read-back and native attachment read-back; stale usages are retired through the existing owner workflow, never by deleting Media.

- [ ] Add failing tests for explicit current Media beating stale featured/inline placements, no current Media refusing unrelated global reuse, and Article featured Media not creating representative/evidence/Graph state.
- [ ] Run those tests against the current coordinator to capture the exact failing diagnostics/read-back mismatch.
- [ ] Normalize and enforce `USER_EXPLICIT > SYSTEM_AUTO > HISTORICAL_REUSABLE`; reconcile only managed placements owned by the Article and require both usage and `_thumbnail_id`/featured attachment read-back for `featured_primary`.
- [ ] Add inline managed-placement read-back and ensure unrequested historical inline placements are removed/retired through the normal owner path.
- [ ] Run focused media tests and commit the generic media reconciliation boundary.

### Task 3: Capture-bound MediaUsage child admission

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/MediaBindingStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/MediaBindingStagingGuard.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceTest.php`, Capture continuation/convergence tests, and a new generic Capture admission regression if needed

**Interfaces:**
- Capture creates an immutable child packet bound to Capture fingerprint, Article owner, Media UUID/revision, role, placement, selection source, payload/operation fingerprint, expected revisions, idempotency, expiry and runtime build checkpoint.
- Guard verifies the packet; absent/mismatched/expired caller scope remains `STAGING_SCOPE_REQUIRED` or the existing precise fail-closed error.

- [ ] Add failing tests proving canonical Capture succeeds without a caller-provided scope and standalone unsafe MediaUsage writer remains blocked.
- [ ] Run the tests and verify the current code requires external scope or loses the exact Capture binding.
- [ ] Issue the packet only inside canonical Capture and feed it through Proposal → Eligibility → Controlled Apply → usage/native read-back.
- [ ] Re-run focused governance/media/Capture tests, confirm no direct repository write was introduced, and commit.

### Task 4: Fail-soft Visual Support validation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/VisualSupportRequirementService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` and visual opportunity planner/coordinator call sites
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Media/VisualSupportRequirement*` only for existing registry/error normalization
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportReverseReconciliationTest.php`, `VisualSupportRequirementTest.php`, and Capture convergence tests

**Interfaces:**
- Validator consumes the shared subject packet, registered scope/facet/feature_key/visual_intent, and policy-requiredness.
- It returns a diagnostic/review/skip result for invalid optional candidates; only validated candidates reach `VisualSupportRequirementService::require()`.

- [ ] Add failing tests for unknown feature/intent, missing subject packet, malformed trailing-underscore error normalization, and valid Article continuation when optional support is absent.
- [ ] Implement pre-persistence validation and preserve `MISSING`/`REVIEW_REQUIRED` as enrichment unless policy marks the slot required.
- [ ] Run focused Visual Support and Capture tests and commit.

### Task 5: Native WordPress slug route lifecycle and publication gate

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/WordPress/EditorialDraftGateway.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticlePublicationGate.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/OwnerPublicationApplicationService.php`
- Modify: native WordPress Article repository/adapter and `public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php` only where route ownership is currently conflated
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`, `OwnerPublicationApplicationServiceTest.php`, `EditorialDraftGatewayTest.php` if present, plus a generic empty-slug regression

**Interfaces:**
- Draft preparation allocates a collision-safe native slug from the title, persists it, and reads it back as `PRE_PUBLIC_ROUTE_READY`.
- Publish requires fresh canonical evidence; after native publish it reads back the native permalink and rendered route as `POST_PUBLISH_ROUTE_VERIFIED`. Semantic PublicIdentity remains required only for semantic Authority routes.

- [ ] Add failing tests for empty draft slug, native route readiness without semantic PublicIdentity, fresh evidence replacing stale gate evidence, and post-publish permalink/read-back failure.
- [ ] Implement native slug allocation/read-back and remove only the circular semantic PublicIdentity requirement for native Article routes; retain collision checks and all actual media/semantic/compliance blockers.
- [ ] Run focused publication/draft tests and commit.

### Task 6: Generic full IMAGE_ARTICLE orchestration coverage

**Files:**
- Modify or create: `public/wp-content/plugins/nhk-core/tests/Unit/ImageArticleProductionFlowTest.php` and/or `EditorialCaptureConvergenceE2ETest.php`
- Modify: only the smallest orchestration seams needed to make the generic flow use the shared packet/media admission/route evidence

**Interfaces:**
- Fixture uses registered generic Authority subjects and Media IDs unrelated to the historical canary.
- Assert one Capture/one Article, explicit subject precedence, explicit featured Media, stale inline absence, semantic and MediaUsage/native read-back, slug readiness, clean publication review, publish, permalink and rendered read-back.

- [ ] Add the failing full-path test with exact Model + body Music + explicit image + stale historical usage.
- [ ] Run it to identify remaining cross-boundary wiring gaps; fix one root cause at a time with a focused regression test.
- [ ] Run the full focused orchestration suite and commit the generic end-to-end proof.

### Task 7: Verification, execution-state checkpoint, and generic system commit

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with root causes, verification evidence, no-live-mutation status, and commit identity
- Do not modify canary data or add canary-specific production conditions

- [ ] Run PHP lint over changed PHP files, `git diff --check`, focused unit tests, integration/contract checks where runtime exists, and the full NHK suite.
- [ ] Run secret review and production-source hardcode scan for the forbidden canary values; classify only existing docs/tests/live-evidence occurrences.
- [ ] Inspect resolver constructors, Media writers, direct semantic writes, stale WordPress image import paths, publication bypasses, and circular route checks.
- [ ] Record exact pass/fail/skip evidence in `V3_EXECUTION_STATE.md` and commit the generic system fix separately from any deployment/canary work.

### Task 8: Approved deployment verification, then canary Article reconciliation

**Files:**
- No generic production-code changes for the canary.
- Use only the existing approved deployment workflow and canonical Capture/Governance/MCP operations.
- Record evidence in `docs/architecture/V3_EXECUTION_STATE.md` or the approved dated acceptance artifact.

- [ ] Verify deployment source revision, build identity, documentation version, manifest hash, migrations and health/read-back; if blocked, stop and report the exact blocker.
- [ ] Perform duplicate/read-only audit for the existing Capture/Article/Media and obtain a fresh server-issued exact bounded packet; do not fabricate scope.
- [ ] Replay the existing Capture through canonical continuation, reconcile subject/media/inline/visual/slug/publication state, and read back every owner.
- [ ] Verify canary outcomes without adding production conditions for the canary values; if live runtime/credentials/scope are unavailable, stop without mutation and report the blocker.

## Verification commands

Run from `/Users/imac24-2125d/Developer/nhk-v3`:

```bash
cd public/wp-content/plugins/nhk-core
composer test -- --testsuite Unit
php -l src/Application/Semantic/SubjectResolutionService.php
git diff --check
```

Use the repository's existing full and guarded integration commands discovered from `composer.json`, `phpunit.xml*`, and `scripts/`; do not invent a destructive database command. Run migration checks only for schema changes (this plan expects none).

## Plan self-review

- Subject precedence, packet reuse, Media precedence, admission, Visual Support, native route lifecycle, publication and generic E2E each have an owning task and regression.
- No task authorizes live mutation before the generic commit/deployment checkpoint.
- No canary value appears in production implementation tasks or generic fixtures.
- Existing contracts and Constitution remain higher authority than this plan.
