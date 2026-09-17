# Video Editorial Reuse Canonical Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent a matching stored editorial fingerprint from producing `REUSE_EDITORIAL` unless the canonical Video's persisted editorial, subject, SEO and source identity payload also matches the desired replay.

**Architecture:** Keep `VideoEditorialResumePlanner` as the decision boundary. It reconstructs the desired package from the current canonical Video and continuation context, compares the governed persisted fields it owns, and returns `REBUILD_EDITORIAL` with a governed update payload whenever canonical state is stale. The existing Capture path then updates the same Video UUID and completes only after canonical read-back parity; public URL reprojection remains out of scope.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress-domain repositories, NHK V3 Video/Capture/Governance services.

**Spec:** User request at `/Users/imac24-2125d/.codex/attachments/c490105b-74bc-4b0f-a8c8-9357d31428bf/pasted-text.txt`; governing contracts: `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`, `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`, `docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md`, `docs/mcp/MCP_V3_VIDEO_WORKFLOW.md`.

## Global Constraints

- Work only in `/Users/imac24-2125d/Developer/nhk-v3`; do not SSH, hotfix a server, deploy, push, or mutate live Video/Knowledge/Graph data.
- Preserve Video UUID, external platform identity, relation state, optimistic revision, idempotency and provenance.
- `REUSE_EDITORIAL` requires fingerprint equality plus canonical persisted payload parity.
- Stale canonical editorial/SEO/subject state returns a bounded update plan, never false-positive reuse.
- Do not reproject public URLs in this patch; canonical Video correction comes first.
- Keep operational instructions as `non_semantic_context`; preserve `SUBJECT_CONFLICT_REVIEW_REQUIRED`; do not create duplicate Knowledge.
- Do not add types, fields, predicates, relation stores or live-data fixtures.

### Task 1: Add failing planner regressions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialResumePlannerTest.php`

**Interfaces:**
- Consumes: `VideoEditorialResumePlanner::plan(array $videoProposal, array $context): array`.
- Produces: tests requiring reuse only after parity and requiring an update for stale canonical payloads.

- [ ] **Step 1: Write the failing tests**

Add tests using deterministic helper fixtures for:

1. matching fingerprint plus matching canonical editorial/SEO/subject package → `REUSE_EDITORIAL`;
2. matching fingerprint plus stale canonical editorial title → `REBUILD_EDITORIAL`, `operation=update`, same `target_uuid), current `expected_revision`;
3. matching fingerprint plus stale `seo_projection` or subject packet → update, not reuse.

The fixture must assert parity for UUID, platform, external ID, canonical URL, editorial package/title, subject packet, SEO package, `open_graph`, and `video_object`. Build the expected package through the real generator/projection, not copied prose.

- [ ] **Step 2: Run the focused test to verify RED**

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --filter VideoEditorialResumePlannerTest --testdox
```

Expected: the new stale-field tests fail because the current planner trusts the stored fingerprint alone.

### Task 2: Require canonical payload parity before reuse

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialResumePlanner.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialResumePlannerTest.php`

**Interfaces:**
- Consumes: canonical `Video`, normalized source/subject/claims, `VideoEditorialGenerator`, and `VideoSeoProjection`.
- Produces: `REUSE_EDITORIAL` only for fingerprint + parity; otherwise the existing governed update plan with a stale-replay diagnostic.

- [ ] **Step 1: Factor desired-package construction**

Extract the existing enrichment/editorial/SEO construction into a private method returning the desired `editorial`, `seo`, `subject_resolution_packet`, `enrichment_context`, and `seo_projection`. Preserve current generated output.

- [ ] **Step 2: Add the canonical parity predicate**

Before the fingerprint-match reuse return, reconstruct the desired package and compare the canonical Video UUID, platform, external ID, canonical URL, stored input fingerprint, `metadata.editorial`, `metadata.seo`, `metadata.subject_resolution_packet`, and `metadata.seo_projection` including any required revision/fingerprint token. Compare only fields owned by this editorial package so relation reconciliation can update other metadata independently.

If parity fails, return the normal `REBUILD_EDITORIAL` update payload and add an internal `STALE_EDITORIAL_REPLAY` diagnostic. Do not change the Video UUID, source identity, relation state or URL allocation.

- [ ] **Step 3: Make reuse read-back explicit**

Extend the reuse packet with verified canonical title/package, subject, SEO parity and current revision. It must not imply completion from fingerprint equality alone.

- [ ] **Step 4: Run the focused test to verify GREEN**

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --filter VideoEditorialResumePlannerTest --testdox
```

### Task 3: Prove governed retry, completion and projections

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`
- Modify: the existing Video search/projection test file discovered with `rg`, if needed
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoCompletenessPersistenceTest.php`, if needed for canonical completion coverage
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php` only if a focused test proves the reuse packet can still bypass parity
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Completion/CompletionCoordinator.php` only if a focused test proves mismatched canonical read-back can still become `COMPLETE`

**Interfaces:**
- Consumes: planner update/reuse result, governed Video update executor, canonical repository read-back, `VideoSearchDocument`, `MediaVideoPageQuery`, and `CompletionCoordinator`.
- Produces: same-UUID update after a prior downstream failure, relation KEEP plus editorial update, corrected Search/SEO output, and non-COMPLETE completion for mismatched canonical title.

- [ ] **Step 1: Add retry regression before any extra production change**

Persist the editorial fingerprint before a simulated downstream relation failure and leave canonical editorial fields stale. An explicit `resume_children=['video']` retry must create a governed `video/update` plan, preserve the UUID, and use the current revision.

- [ ] **Step 2: Add relation KEEP coverage**

Return an active correct `about` relation with KEEP/reused semantics. Assert the editorial update executes exactly once, with no new relation and no duplicate Knowledge.

- [ ] **Step 3: Assert canonical/Search/SEO parity after update**

After the governed update, read the original UUID and assert the corrected title appears in canonical editorial metadata, `VideoSearchDocument::title()`, `seo_projection.title`, Open Graph title and VideoObject name. Assert stale Public Identity/slug is not treated as projection consistency and no URL reprojection is invoked.

- [ ] **Step 4: Add the completion gate regression**

Assert a Video/Capture completion packet is not `COMPLETE` while canonical read-back has the wrong editorial title or lacks the required parity marker; matching canonical read-back may complete.

- [ ] **Step 5: Preserve unrelated invariants**

Keep regressions for `non_semantic_context`, `SUBJECT_CONFLICT_REVIEW_REQUIRED`, no duplicate Knowledge, no Media/Article 564/image-uploader changes, no W200/RvRFz5D_scc behavior, and no use of proposal `01a0ae3f-6404-7df2-b0c0-55d21b481513`.

- [ ] **Step 6: Run the focused regression slice**

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'VideoEditorialResumePlannerTest|GovernedCaptureContinuationServiceTest|VideoCompletenessPersistenceTest|VideoSearch|Search|Projection|Completion' --testdox
```

### Task 4: Verification, execution-state checkpoint and local commit

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` with a local-code checkpoint after verification
- Read: `docs/architecture/V2_V3_PARITY_MATRIX.md` before any parity claim

- [ ] **Step 1: Run required focused families**

Run Existing Capture video resume, Video correction, canonical Video repository, relation KEEP, Search, public/SEO projection, Capture completion, and Knowledge retry tests using the exact files/filters discovered during implementation.

- [ ] **Step 2: Run quality gates**

```bash
composer lint
git diff --check
rg -n --hidden --glob '!vendor/**' --glob '!node_modules/**' '(BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9]{20,})' .
```

The secret scan must produce no repository secrets; do not print or commit environment files, tokens or credentials.

- [ ] **Step 3: Record the local checkpoint**

Read the current relevant execution-state section and the full parity matrix. Append the root cause, canonical parity gate, focused test result, and any unavailable integration boundary. Do not claim live/deployed parity.

- [ ] **Step 4: Inspect and commit locally**

```bash
git status --short
git diff --stat
git diff --check
git add public/wp-content/plugins/nhk-core/src public/wp-content/plugins/nhk-core/tests/Unit docs/architecture/V3_EXECUTION_STATE.md docs/superpowers/plans/2026-09-17-video-editorial-reuse-canonical-parity.md
git commit -m "fix(video): require canonical parity before editorial reuse"
```

Do not push or deploy. Report the local commit hash and verification evidence only after the commands pass.
