# Public Clock Final Closeout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:test-driven-development for every production fix and superpowers:verification-before-completion before any completion claim.

**Goal:** Close the existing Public Clock reference implementation by repairing only generic readiness and MediaUsage continuation root causes, proving the canonical local workflow, and performing one bounded deployment/read-back attempt when the approved runtime configuration is available.

**Architecture:** Preserve Authority, Knowledge, Source/Evidence, Graph, Media, MediaAsset, MediaUsage, WordPress Post, Capture, Governance, and Public Identity ownership. Improve the existing `PresentationReadiness`, `PublicEntityCollectionQuery`, `MediaService`, `ArticleMediaCoordinator`, and Capture continuation seams without adding semantic vocabulary or data owners.

**Tech Stack:** PHP 8.5, WordPress, PHPUnit 11, MySQL guarded integration database `nhk_v3_test`, Composer, existing SSH/rsync `RemoteDeploymentAdapter`, canonical MCP/runtime read-back.

**Spec:** User-provided `NHK V3 — PUBLIC CLOCK ONE-PASS FINAL CLOSEOUT` request in `/Users/imac24-2125d/.codex/attachments/4d8f5cd8-ee62-4fbf-9fd5-e789d720febd/pasted-text.txt`.

## Global Constraints

- Reuse the exact existing Public Clock, Turret Clock, Capture, Media, Attachment, Asset, relation, Article, Knowledge, and approved staging IDs from `AGENTS.md` and the request.
- Do not create Entity, Capture, Proposal, Knowledge, relation, Public Identity, Media, Asset, Usage, or Article duplicates.
- Do not mutate V2 or production; staging mutations are allowed only through the explicit exact-ID `STAGING_ACCEPTANCE_SCOPE` and canonical governed workflows.
- Do not use direct WordPress/database writers to bypass Capture, Governance, MediaUsage CAS, Public Identity, or Article publication contracts.
- Do not update `docs/architecture/V3_EXECUTION_STATE.md` until live acceptance is actually complete; if deployment/runtime is unavailable, record only the exact blocker after all safe checks.

## Root-cause matrix

| Component | Current implementation | Test coverage | Known gap | Fix required | Already fixed |
|---|---|---|---|---|---|
| Clock Group archive | `PublicEntityCollectionQuery::archiveProfile()` filters resolved ACTIVE `classification` entities through shared `item()` and READY | Clock profile archive and readiness unit tests | Local code is present; deployed/live archive is stale until the unified build is deployed | Add complete signal matrix and collection regression; no special case | Generic route/profile path and Knowledge signal landed in `0a13bc90` |
| PresentationReadiness | route + eligibility + recursive `public_signals` truthiness | Basic active/inactive, identity-only, Knowledge tests | Arbitrary unknown signal keys can count as content; matrix lacks hierarchy/media/no-route coverage | Evaluate only summary, representative media, Knowledge, Article, hierarchy | Core generic signal rule landed in `0a13bc90` |
| PublicEntityCollectionQuery | profile filters before pagination; public identity is required; readiness filters cards | Public Clock Knowledge fixture | Required regression does not prove child/hierarchy plus no media and exactly-once collection behavior | Add integration-style fixture and exact-once assertion | Sort-before-pagination and identity gate exist |
| Entity dossier / Knowledge | detail query composes direct subject Knowledge and related context | Entity/dossier unit coverage | No evidence of a new defect in current code | Regression only if focused run identifies a gap | Existing 7/9 read-back is recorded |
| Article subject/publication | existing Capture/Article/Governance and owner publication boundaries | Article, capture, publication unit tests | Live Articles remain drafts because MediaUsage continuation fails before publication | Preserve current body; resume existing Capture after generic fix; use governed publication continuation | Jargon and optional-media gate are implemented |
| MediaUsage persistence | `WpdbMediaUsageRepository::update()` uses `usage_uuid AND revision`, increments revision, reads back | Code-side only; no dedicated CAS repository test | Caller treats a stale CAS exception as terminal; placement-aware identity is already present and needs regression protection | Add bounded refresh/retry; retain placement-aware identity; preserve usage UUID | Revision column/index and updater interface landed in prior work |
| Article MediaUsage reconciliation | `ArticleMediaCoordinator::reconcileUsage()` reads once, updates once, or adds | In-memory reuse/replacement tests | stale read or concurrent hook can produce `Media usage update conflict` and abort Capture | Refresh canonical endpoint state, no-op equivalent state, retry one fresh CAS, fail typed after bound | In-place update and no delete/recreate exist |
| Capture continuation | retries existing receipt/Capture and re-enters Article Media phase | Capture continuation tests | Media exception is surfaced as generic retryable failure without bounded reconciliation | Keep same Capture/idempotency key and retry through repaired coordinator | Existing continuation/replay path exists |
| Visual support/media requirement | optional missing media is warning; invalid blueprint/pipeline failure block | Article SEO gate tests | No new code gap identified | Retain optional Turret state; do not reopen file transport | Already fixed in `ArticleSeoGate` |
| Public Identity/routing | persisted identity resolves canonical route; Clock Type uses `/dong-ho-{slug}/` | route/profile tests | Live consumer not verified | Read-only live route/content read-back after deployment | Existing policy and resolver are in place |
| Deployment verifier | clean worktree, docs snapshot, plugin/theme/MU transfer, target and direct MCP verification | adapter unit tests | `NHK_DEMO_DEPLOY_CONFIG` is currently unset; live target may be dirty | Discover approved config once; deploy exactly once only if available | Existing canonical wrapper is the only allowed path |
| Live read-back | prior ledger records route 200/dossier but stale archive and drafts | historical evidence only | target runtime/build identity and content acceptance are unverified | Fresh post-deploy content read-back; otherwise one exact external blocker | No live completion claim exists |

## Implementation tasks

### Task 1: Add failing presentation and collection regressions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationReadinessTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/PublicEntityCollectionQueryTest.php`

**Interfaces:**
- Consumes: `PresentationReadiness::evaluate()` and `PublicEntityCollectionQuery::archiveProfile()`.
- Produces: executable coverage for all eight readiness cases and a root-with-Knowledge-child collection fixture.

- [ ] Add tests for active route + hierarchy, active route + published Article, active route + representative Media, active route + Knowledge, inactive, no route, and unknown signal data.
- [ ] Add a collection fixture with one ACTIVE Clock Group root, persisted public identity, one public Knowledge claim, and one child context; assert one returned card and no required Media.
- [ ] Run the two focused files and confirm the new assertions fail for the currently over-broad signal behavior or missing coverage.

### Task 2: Implement the minimal readiness signal boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Presentation/PresentationReadiness.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PresentationReadinessTest.php`

**Interfaces:**
- Consumes: `public_signals` packets emitted by collection and dossier projections.
- Produces: READY only for summary/description, representative Media, public Knowledge, published Article, or meaningful hierarchy.

- [ ] Replace recursive arbitrary-signal truthiness with explicit evaluation of the five registered signal keys while retaining the existing route, eligibility, and activity gates.
- [ ] Keep `content` compatibility behavior for existing non-profile callers, but ignore identity-only fields and unknown operational keys.
- [ ] Run the focused readiness and collection tests and confirm the new matrix is green.

### Task 3: Add failing MediaUsage CAS/idempotency and placement regressions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/MediaUsageReconcilerTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/MediaServiceUsageIdentityTest.php`

**Interfaces:**
- Consumes: `MediaService::addUsage()`, `ArticleMediaCoordinator::ensureForPost()`, `MediaUsageUpdater::update()`, and endpoint reads.
- Produces: coverage for identical replay, equivalent existing usage, one stale revision refresh/retry, distinct representative/inline roles, two retries with stable count, failed continuation replay, and different-subject non-reuse.

- [x] Add a fake updater that fails once with `Media usage update conflict`, then succeeds only when passed a freshly reread revision; assert one usage UUID and two total attempts.
- [ ] Add replay assertions that equivalent existing usage returns without update and two identical retries preserve final usage count.
- [x] Add placement-key assertions that two same-role placements on one Media remain distinct and that representative and inline roles coexist.
- [ ] Add subject-scope assertions through the existing Article Media test stores so a different subject cannot silently reuse a wrong binding.
- [x] Run the conflict/readiness regressions before implementation; retain the already-correct placement identity behavior as a regression guard.

### Task 4: Implement bounded generic MediaUsage reconciliation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WpdbMediaUsageRepository.php` only if the focused test proves the SQL read-back boundary is incomplete
- Test: Task 3 tests

**Interfaces:**
- Consumes: canonical endpoint reads and the existing `MediaUsageUpdater` CAS capability.
- Produces: equivalent-state no-op, one bounded refresh/retry on stale revision, deterministic role/placement identity, and typed failure when the bound is exhausted.

- [x] Verify and retain `MediaService::addUsage()` placement-aware identity behavior; no production change was required for this already-correct seam.
- [ ] Refactor `ArticleMediaCoordinator::reconcileUsage()` into a bounded loop: read current endpoint usages, choose the exact desired role/placement, return equivalent state unchanged, update with the current revision, catch only the typed usage conflict, reread, and retry once.
- [ ] Preserve the existing usage UUID and never delete/recreate a usage to resolve a conflict; return the canonical current usage after a successful retry.
- [ ] If duplicate current rows are encountered, select deterministically by exact desired media/placement, then lowest usage UUID, and fail closed with an explicit duplicate diagnostic when no safe canonical survivor exists; do not hide a duplicate by broad deletion.
- [ ] Run the Task 3 tests green, then run the prior Article Media, Usage Reconciler, and Article SEO suites.

### Task 5: Run the complete local verification matrix and commit one unified build

**Files:**
- Modify: `docs/superpowers/plans/2026-09-16-public-clock-final-closeout.md` only if the executed evidence requires a factual correction.
- Update later only after live acceptance: `docs/architecture/V3_EXECUTION_STATE.md`.

**Interfaces:**
- Consumes: all changed runtime/test files and current canonical documentation.
- Produces: fresh test, lint, diff-check, and secret-review evidence plus one committed build identity.

- [ ] Run focused PresentationReadiness, PublicEntityCollectionQuery, MediaUsage, Capture, Article, dossier, routing, and contract tests in that order.
- [ ] Run the Unit suite; classify database/bootstrap failures as `ENVIRONMENT_FAILURE` rather than product failures.
- [ ] Attempt the documented guarded integration command only against exact `nhk_v3_test`; do not use staging as a substitute.
- [ ] Run PHP lint, `git diff --check`, and a repository secret review; inspect the diff for prohibited IDs/data or direct writers.
- [ ] Commit the plan plus the minimal source/test changes with one logical commit before any deployment attempt.

### Task 6: Discover the existing deployment path once and deploy once if available

**Files:**
- No new deployment system or config file.

**Interfaces:**
- Consumes: existing `scripts/nhk-deploy-verify`, `RemoteDeploymentAdapter`, current committed HEAD, and `NHK_DEMO_DEPLOY_CONFIG`/authenticated runtime.
- Produces: verified deployment identity or one exact blocker `DEPLOY_CONFIG_UNAVAILABLE` / other canonical verifier code.

- [ ] Check only the approved environment/config presence and known repository documentation paths; never print secret values.
- [ ] If config and credentials are present, run the existing wrapper once with the committed HEAD and canonical target; do not run manual SSH/rsync.
- [ ] If config is absent after bounded discovery, do not invoke the wrapper and classify deployment as blocked without modifying code.

### Task 7: Reconcile/read back existing canonical data and close the ledger only with evidence

**Files:**
- Update only on verified live/staging acceptance: `docs/architecture/V3_EXECUTION_STATE.md`.

**Interfaces:**
- Consumes: fresh target build/runtime identity, existing exact Capture/Article IDs, governed continuation/publication boundaries, and visitor-facing routes.
- Produces: canonical read-back of Public Clock/Turret, Articles #485/#487, MediaUsage, optional Turret media state, and read-only reusability checks for Cuckoo/Vai Bò/400 days.

- [ ] Complete the existing Capture continuations using the same Capture IDs and idempotency keys after deployment, only through the canonical operator boundary and exact approved scope.
- [ ] Validate #487’s persisted body with the existing public jargon validator; change only an exact remaining leak if one exists.
- [ ] Publish only after the governed publication evidence, state-token CAS, owner decision policy, media policy, and rendered public read-back all pass.
- [ ] Read `/loai-dong-ho/`, `/dong-ho-cong-cong/`, `/dong-ho-thap/`, and both Article routes for required visible content, not merely HTTP status.
- [ ] Simulate generic read-only paths for Cuckoo, Vai Bò, and 400 days without creating or mutating data.
- [ ] Update execution state with factual build/test/routes/counts/usage IDs only after acceptance; otherwise record the single exact external blocker and stop.
