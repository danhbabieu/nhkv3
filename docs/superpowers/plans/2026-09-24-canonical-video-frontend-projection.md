# Canonical Video Frontend Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make Video frontend verification read the same public projection contract used by `/video/`, homepage cards, search and detail routes, while preserving one canonical Video owner.

**Architecture:** The checked-out runtime has no Video CPT or materialized Video listing table. Public Video presentation is a read-only projection derived from the canonical Video plus persisted Public Identity and `VideoUrlPolicy`. Introduce one shared projection reader for that contract; route/archive/home/search consume it, and Capture verification reads back archive/home/detail-consumable state from it. No WP post, direct DB write, or new semantic owner is added.

**Tech Stack:** PHP 8+, WordPress theme/plugin, PHPUnit, existing repositories and public identity registry.

**Spec:** User request: Canonical Video → Frontend Projection/Listing investigation and generic repair.

## Global Constraints

- Canonical Video remains the sole Video owner; no duplicate owner or WP post/CPT workaround.
- Public Identity and governed canonical lifecycle remain the only mutation boundaries.
- Frontend verification must prove materialized/readable state from the same source queried by frontend listings.
- No ingest/enrichment expansion, staging mutation, push, pull or deploy.
- Existing Video reconciliation is read-only/idempotent projection rebuilding and must not create a new Video, Knowledge, Claim, Post or relation.

## Review Focus

- A canonical Video with identity but a missing/ineligible public projection must not be `VERIFIED`.
- Archive and homepage must not diverge in eligibility from the detail route.
- Search must not return a Video whose public detail/listing projection is unavailable.
- Repeated projection reads must remain deterministic and duplicate-free.
- Existing valid Videos must keep their route, archive card and homepage card behavior.

### Task 1: Shared Video frontend projection contract

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoFrontendProjection.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoFrontendProjectionTest.php`

**Interfaces:**
- Consumes: `Video`, `VideoUrlPolicy`, `VideoPublicContextSelector`, persisted Public Identity registry.
- Produces: `project(Video): ?array`, with canonical ID, public path, title, external ID and listing-safe fields; null plus blocker diagnostics when not eligible.

- [ ] Write one failing test for a valid canonical Video whose Public Identity is absent or whose public policy is incomplete; assert no frontend projection and explicit blockers.
- [ ] Run the focused PHPUnit test and observe the expected missing-class/API failure.
- [ ] Implement the smallest read-only projection class using the existing `VideoUrlPolicy`; do not write any repository.
- [ ] Add passing tests for valid projection, deterministic repeated reads and no duplicate identity fields.
- [ ] Run the focused test again and confirm PASS.

### Task 2: Use the shared projection in all Video frontend readers

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Home/HomeSemanticQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Search/SearchSemanticQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSearchDocument.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/HomeSemanticQueryTest.php`

**Interfaces:**
- Consumes: `VideoFrontendProjection::project()`.
- Produces: archive, homepage and search items only when the shared frontend projection is consumable; detail continues resolving the same public path.

- [ ] Add FAIL-BEFORE archive/home regression coverage for a canonical Video that route/detail verification currently accepts but listing policy rejects.
- [ ] Run the focused tests and confirm the mismatch is observable before the fix.
- [ ] Replace duplicated Video eligibility gates with the shared projection reader and map its public fields into existing card payloads.
- [ ] Wire the verifier callback to assert archive and homepage source membership, not just detail route/title equality.
- [ ] Run focused archive/home/search/detail tests and confirm PASS.

### Task 3: Generic verifier and bounded reconciliation behavior

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoPublicationVerifier.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoPublicationVerifierTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicProjectionVerifierTest.php`

**Interfaces:**
- Consumes: shared frontend projection read-back result.
- Produces: `frontend_state=VERIFIED` only after frontend-source read-back; otherwise bounded blocker/readiness suitable for retry/reprojection.

- [ ] Add FAIL-BEFORE test proving canonical Video + Public Identity + detail route is not enough when archive/home source read-back is absent.
- [ ] Run it and confirm current false-positive behavior.
- [ ] Implement fail-closed generic verification with explicit projection/listing blockers and idempotent read-only retry semantics.
- [ ] Add idempotency/duplicate assertions: one canonical Video, one identity, one projection result, no Knowledge/Claim writes.
- [ ] Run focused verifier tests and confirm PASS.

### Task 4: Contract and integration verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test as available: focused, Unit, Contract, guarded Integration.

- [ ] Run PHP lint for changed files.
- [ ] Run focused Video projection/archive/home/detail/verifier tests.
- [ ] Run Unit and Contract suites; run guarded Integration if the environment is available without mutation of `nhk_v3`.
- [ ] Run `git diff --check` and a special-case scan over production diff for supplied IDs/titles.
- [ ] Record evidence in execution state and create one focused local commit.
