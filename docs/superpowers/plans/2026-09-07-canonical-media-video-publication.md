# Canonical Media/Video Publication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the Constitution-compliant Admin Media/Video publication and first-party Video projection slice while preserving existing owners, writers, routes and Unified Admin Workbench behavior.

**Architecture:** Extend the existing read-only Admin adapters, `AdminWorkbenchReadApi`, `MediaVideoPageQuery`, Public Identity, Graph/related-content and SEO projection seams. Video canonical pages use persisted `/video/{slug}/`; Media remains gallery/entity/asset projection only because standalone indexable Media pages are not authorized by the current Constitution.

**Tech Stack:** PHP 8+, PHPUnit 11, WordPress hooks/REST, existing NHK V3 application services, vanilla admin JavaScript and the existing NHK V3 theme templates.

**Spec:** `docs/superpowers/specs/2026-09-07-canonical-media-video-publication-design.md`

## Global Constraints

- WordPress `wp_posts` remains the sole editorial source of truth.
- Authority, Knowledge, Source/Evidence, Graph, Media and Video retain separate canonical owners.
- Semantic mutation remains Proposal → Approval → Eligibility → Controlled Apply → canonical read-back.
- Admin is a read/orchestration adapter and must not write SQL, create semantic records or bypass Governance.
- Video canonical route is `/video/{persisted-slug}/`; no UUID, external ID or opaque short-id suffix is added.
- YouTube is source/provenance/embed only; “Xem trên web” never falls back to the external URL.
- No seed, migration, legacy repair, bulk backfill, Graph JSON mutation, production publish or external deployment.
- Vietnamese-first copy and honest empty, blocked, unavailable and projection-pending states are mandatory.

### Task 1: Establish failing Admin workspace contract tests

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/UnifiedWorkbenchTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminDomainAdapterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchArchitectureTest.php`

**Interfaces:**
- Consumes: existing `AdminWorkbenchPage`, `AdminVideoAdapter`, `AdminMediaAdapter`.
- Produces: executable assertions for Media tabs, separate Video/Image workspaces, technical disclosure and action separation.

- [ ] **Step 1: Add red tests** asserting `Tất cả`, `Hình ảnh`, `Video`, the eight detail tabs, `Xem trên web`, `Mở nguồn gốc`, and that normal forms contain no proposal/evidence/fingerprint/revision/raw JSON inputs.
- [ ] **Step 2: Run the focused Admin tests** with `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/Admin/UnifiedWorkbenchTest.php public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminDomainAdapterTest.php public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchArchitectureTest.php`; confirm failure is due to missing workspace behavior.
- [ ] **Step 3: Commit the red tests** with `git add public/wp-content/plugins/nhk-core/tests/Unit/Admin/UnifiedWorkbenchTest.php public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminDomainAdapterTest.php public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminWorkbenchArchitectureTest.php && git commit -m "test: define media video publication workspaces"`.

### Task 2: Extend read-only Admin domain projections

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminVideoAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminMediaAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/AdminWorkbenchReadApi.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminDomainAdapterTest.php`

**Interfaces:**
- Consumes: canonical repositories, existing Graph read service, existing proposal eligibility service and `VideoUrlPolicy`.
- Produces: reader-safe rows/details with publication, relation, evidence/readiness, governance and frontend states; no new writer.

- [ ] **Step 1: Add the minimal failing adapter assertions** for Video title/external platform/external ID/short canonical ID, primary target, relation count, article count, evidence state, governance state and frontend state; add image dimensions/role/primary entity/usage count/readiness/public state.
- [ ] **Step 2: Run only those tests** and verify the missing keys fail rather than silently passing.
- [ ] **Step 3: Implement read-only composition** using existing repositories/services. Filter private Source/Evidence and non-public Graph relations before returning public-facing values; preserve technical UUID/revision only in a `technical` disclosure structure.
- [ ] **Step 4: Add REST detail responses** for the selected Video and Media/Image without accepting proposal UUID, Evidence UUID, fingerprint, expected revision or raw payload from normal UI requests.
- [ ] **Step 5: Run the focused adapter/API tests** and confirm green.
- [ ] **Step 6: Commit** as `feat: expose canonical media video readback`.

### Task 3: Add shared Media tabs and real Video/Image admin workspaces

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Admin/AdminWorkbenchPage.php`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js`
- Modify: `public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/UnifiedWorkbenchTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/Admin/AdminSharedPresentationTest.php`

**Interfaces:**
- Consumes: Task 2 REST read models and existing `AdminDetailShell`, `AdminListTable`, `AdminReadBackPanel`, `AdminTechnicalDetails`.
- Produces: one Media shell with `Tất cả`/`Hình ảnh`/`Video`, domain-specific list/detail rendering, accessible status labels and separated external/source actions.

- [ ] **Step 1: Add red source/markup tests** for tabs, Video-specific search/filter controls, Image-specific fields, eight tabs and action labels.
- [ ] **Step 2: Run the UI contract tests** and confirm they fail for the missing markup/behavior.
- [ ] **Step 3: Implement the shared shell** without duplicating the legacy Workbench. Keep technical fields collapsed and render normal workflows from selected canonical IDs returned by the read API.
- [ ] **Step 4: Implement JS list/detail loading** with textContent/DOM APIs, explicit `projection_pending` handling and no external fallback for “Xem trên web”.
- [ ] **Step 5: Run JS syntax validation** with `node --check public/wp-content/plugins/nhk-core/assets/admin/admin-workbench.js` and the focused Admin tests.
- [ ] **Step 6: Commit** as `feat: add media image video admin workspaces`.

### Task 4: Harden Video first-party projection and lifecycle mapping

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoUrlPolicy.php` only if a proven gap exists
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicMediaVideoRoutes.php`
- Modify: `public/wp-content/themes/nhk-v3/video.php`
- Modify: `public/wp-content/themes/nhk-v3/media-video.css`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoUrlPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicRouteRedirectTest.php`

**Interfaces:**
- Consumes: persisted Public Identity, `VideoUrlPolicy`, `VideoSeoProjection`, `RelatedContentQuery`, historic route resolver.
- Produces: eligible Video detail projection with breadcrumb, player, supported editorial copy, public related content, provenance and one-hop redirects.

- [ ] **Step 1: Add red tests** for canonical slug stability, route resolve, invalid/private 404 or noindex behavior, projection-pending distinction, related entities/knowledge/articles and one-hop historic slug redirect.
- [ ] **Step 2: Run the focused route/projection tests** and verify expected failures.
- [ ] **Step 3: Implement only the smallest read/projection changes**. Keep `/video/{slug}/`, do not append stable IDs, and do not change Video semantic storage or ingestion.
- [ ] **Step 4: Render structured data only from real values**: omit duration/uploadDate/thumbnail when unavailable; preserve source link under provenance and keep internal canonical link as the primary CTA.
- [ ] **Step 5: Run focused Video/SEO/route tests** and confirm green.
- [ ] **Step 6: Commit** as `feat: complete canonical video projection`.

### Task 5: Preserve and verify Constitution-compliant Image projection

**Files:**
- Modify only if required: `public/wp-content/plugins/nhk-core/src/Application/Media/PublicMediaGalleryQuery.php`
- Modify only if required: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php`
- Modify only if required: `public/wp-content/themes/nhk-v3/media.php`
- Test: existing Media/projection/SEO tests plus a focused regression test if a gap is found

**Interfaces:**
- Consumes: Media/MediaAsset/MediaUsage repositories, public delivery policy and entity related projection.
- Produces: image list/detail usage presentation without inventing a standalone Media semantic route or exposing private provenance.

- [ ] **Step 1: Run current Media projection tests and inspect failures before changing code.**
- [ ] **Step 2: Add a red test only for a verified missing behavior** such as representative/evidence exclusion, dimensions, contextual alt/caption or private provenance filtering.
- [ ] **Step 3: Implement the minimal read-only fix using current role/SEO registries.** Do not create `/hinh-anh/{slug}/` or a new indexable page while the Constitution remains unchanged.
- [ ] **Step 4: Run Media projection/SEO tests and commit** as `fix: preserve compliant image projection` when changes are needed; otherwise record no code change in the execution state.

### Task 6: Regression, runtime smoke and release gates

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test/inspect: focused Admin, Media, Video, route, projection and SEO suites

**Interfaces:**
- Consumes: all prior read models and templates.
- Produces: evidence-backed verification report; no deployment or push.

- [ ] **Step 1: Run focused suites** for Admin, Media, Video, routes, projection and SEO.
- [ ] **Step 2: Run full Unit** with `vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite Unit`; if the configuration has no `Unit` suite, run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit` and record the exact invocation.
- [ ] **Step 3: Run `php -l` across changed PHP files, `node --check` for changed JS, `composer validate --no-check-publish` and `git diff --check`.
- [ ] **Step 4: Run guarded Integration only if the exact `nhk_v3_test` runtime is available; otherwise record concrete environment evidence.
- [ ] **Step 5: Perform browser/UI smoke when WordPress runtime is available: Admin Media tabs, search `truOChTNbwA`, Video detail/player/relations/evidence/frontend state, canonical Video URL and source separation. Do not seed the fixture.
- [ ] **Step 6: Update `docs/architecture/V3_EXECUTION_STATE.md` with commit, test counts, regression result, blockers and the constitutional Media-route limitation.
- [ ] **Step 7: Review `git diff --stat`, `git diff --check`, secret scan and Graph JSON status; commit the final implementation as `feat: add canonical media and video pages` only if all applicable gates pass. Do not push.
