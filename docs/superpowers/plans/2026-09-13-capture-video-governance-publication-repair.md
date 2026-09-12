# Capture Video Governance Publication Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repair the existing Capture → Video → Governance → public projection workflow while preserving canonical identities and fail-closed publication gates.

**Architecture:** Keep Capture as orchestration, Video as the external-reference owner, Governance as the only semantic mutation path, Graph as the only relation owner, and WordPress as the Article owner. Add the smallest application services needed for authoritative subject handoff, governed continuation identity envelopes, quality-aware source thumbnail selection, and structured operator diagnostics.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress plugin runtime, WPDB repositories, existing MCP/Graph/Governance/Media/SEO boundaries, existing NHK theme templates.

**Spec:** `docs/superpowers/specs/2026-09-13-capture-video-governance-publication-design.md`

## Global Constraints

- Preserve canonical UUID/stable-key, optimistic revision, typed relation, provenance, readiness, idempotency, public identity and fail-closed invariants.
- Never use a generic WordPress writer for Video or semantic writes.
- Never create or repair live data outside the explicitly authorized existing Capture acceptance path.
- Do not create a new entity type, endpoint, predicate, relation type or semantic owner.
- Existing uncommitted user changes remain intact; only task-scoped files may be modified.
- Use Vietnamese-first public/operator copy and keep machine-readable diagnostic codes.
- Every production change follows a failing test, minimal implementation, passing focused test, and checkpoint diff review.

---

### Task 1: Lock and validate the Capture subject handoff

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoProvenancePlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoIntakeService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoProvenancePlannerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`

**Interfaces:**
- Consumes the existing Capture `subject_resolution` packet and the existing canonical subject resolver/repository boundary.
- Produces a validated packet with `id`, `type`, `match`, active lifecycle state and revision metadata; Video preview, `knowledge_enrichment.subject`, provenance planning and relation planning consume this packet.

- [ ] **Step 1: Write failing tests for explicit packet authority.** Add tests proving: a valid `uuid_exact` Variant plus no source text match returns `READY` and keeps Variant; weak unrelated text returns `READY` and keeps Variant; strong canonical B returns `REVIEW_REQUIRED` with explicit conflict diagnostics while retaining A; malformed/nonexistent UUID fails closed; inactive/retired UUID fails closed.

- [ ] **Step 2: Run the focused planner/coordinator tests and confirm RED.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoProvenancePlannerTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`

Expected: the new explicit-handoff tests fail because source identity matching is currently mandatory or because invalid packet state is not differentiated.

- [ ] **Step 3: Implement the minimal authoritative handoff.** Add a private validation path that accepts a packet only when its UUID/type resolves through the existing canonical resolver as `uuid_exact`, the type is allowed for Video relations, the owner is active, and the revision is positive/current according to the reader boundary. When valid, bypass text-match as a gate, retain source identity fields as diagnostics/provenance, and record a strong A/B contradiction without replacing A. Keep source-title matching behavior for packets without explicit validated handoff.

- [ ] **Step 4: Thread the same packet into all Video consumers.** Ensure `VideoIntakeService::preview()` uses the validated explicit packet for the package, relation target and knowledge enrichment subject, and that coordinator resume does not replace it with a fresh textual resolution. Preserve the existing no-Model/Brand-fallback behavior.

- [ ] **Step 5: Run focused tests and inspect the diff.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoProvenancePlannerTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`

Expected: all existing and new subject-handoff tests pass; no old negative source-only tests are weakened.

- [ ] **Step 6: Commit the subject boundary.**

Run: `git add public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoProvenancePlanner.php public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Video/VideoIntakeService.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoProvenancePlannerTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php && git commit -m "fix: preserve validated capture video subject handoff"`

### Task 2: Expose a resumable governed Video proposal envelope

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpGovernanceHandler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoProposalReconciliationService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpGovernanceAutomationTest.php`

**Interfaces:**
- Consumes existing `GovernedLifecycle`, proposal repository idempotency lookup, Capture video provenance plans and Controlled Apply callback.
- Produces `REVIEW_REQUIRED` packets containing `proposal_id`, `proposal_state`, `target_uuid`, `canonical_id: null`, fingerprints, semantic subject and external-video identity; applied packets contain canonical read-back and idempotency state.

- [ ] **Step 1: Write failing tests for pending identity and one-proposal resume.** Assert that a submitted Video proposal returned under review includes the exact proposal UUID, submitted state, target UUID when applicable and null canonical ID before apply. Add a regression where the first run is blocked before proposal creation, the next run creates exactly one Video proposal, and repeated resumes reuse it.

- [ ] **Step 2: Run the focused continuation tests and confirm RED.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpGovernanceAutomationTest.php`

Expected: pending envelope assertions fail because `pending()` omits target/canonical identity and the continuation aggregates a generic blocker.

- [ ] **Step 3: Implement the envelope at the governed boundary.** Centralize proposal snapshots from `review()` and payload metadata, make `pending()` include the required identity fields, preserve `canonical_id` as null until verified apply, and propagate subject/conflict diagnostics without converting them to generic approval-only output.

- [ ] **Step 4: Make Video proposal creation idempotent against Capture and external identity.** Derive and persist one stable Video proposal idempotency key from Capture ID, platform, external video ID and semantic subject/intent. Before `createFromArguments`, use the existing proposal lookup and exact binding; resume the existing proposal state instead of minting another proposal. Keep dependency proposal identities distinct.

- [ ] **Step 5: Run focused tests and verify no Governance bypass.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpGovernanceAutomationTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoProposalReconciliationServiceTest.php`

Expected: pending/replay tests pass and all approval calls still flow through `GovernedLifecycle`.

- [ ] **Step 6: Commit the proposal envelope.**

Run: `git add public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php public/wp-content/plugins/nhk-core/src/Application/Mcp/McpGovernanceHandler.php public/wp-content/plugins/nhk-core/src/Application/Video/VideoProposalReconciliationService.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpGovernanceAutomationTest.php && git commit -m "fix: expose resumable video governance identity"`

### Task 3: Complete governed Video apply, relation verification and replay

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoPublicationVerifier.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyService.php` only if an existing idempotent apply read-back gap is proven by tests
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoPublicationVerifierTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoRelationLifecycleTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/GraphWpdbIntegrationTest.php` only for an existing guarded relation lifecycle path

**Interfaces:**
- Consumes the governed Video proposal and existing Video/Graph/Public Identity read boundaries.
- Produces a status-bearing lifecycle result distinguishing candidate, proposal, approval pending, approved, eligible, applied, canonical read-back verified, public-ready and blocked.

- [ ] **Step 1: Write failing lifecycle/replay tests.** Cover approval → eligibility → Controlled Apply → canonical Video read-back; exact one `Video → about → Variant` edge; replay after APPLIED with no second semantic mutation; and final public identity/readiness verification.

- [ ] **Step 2: Run focused tests and confirm RED.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoPublicationVerifierTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoRelationLifecycleTest.php`

Expected: lifecycle status or replay assertions fail at the currently incomplete handoff/read-back stage.

- [ ] **Step 3: Implement only the failing lifecycle transitions.** After approval, re-read proposal binding, run eligibility, call Controlled Apply, require canonical Video read-back, verify the single Graph edge through `GraphService`, verify public identity/readiness through the existing verifier, and return those receipts. For an APPLIED proposal, use the existing apply idempotency/read-back path and do not invoke a second semantic mutation.

- [ ] **Step 4: Persist Capture continuation receipts with the latest optimistic revision.** Ensure dependency and Video phase receipts include proposal ID, canonical ID, status and blockers, and the coordinator refreshes Capture before saving, preserving one Capture/Post/external Video identity.

- [ ] **Step 5: Run focused lifecycle tests and guarded Graph tests if runtime is available.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoPublicationVerifierTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoRelationLifecycleTest.php`

Expected: all focused lifecycle tests pass. If guarded integration is unavailable, record the exact environment gate without weakening unit assertions.

- [ ] **Step 6: Commit the lifecycle continuation.**

Run: `git add public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureVideoPublicationVerifier.php public/wp-content/plugins/nhk-core/src/Application/Governance/ControlledApplyService.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoPublicationVerifierTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoRelationLifecycleTest.php public/wp-content/plugins/nhk-core/tests/Integration/GraphWpdbIntegrationTest.php && git commit -m "fix: complete governed video continuation readback"`

### Task 4: Select and project the highest-quality usable Video thumbnail

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoThumbnailSelector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/YouTubeDataApiClient.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Home/HomeSemanticQuery.php`
- Modify: `public/wp-content/themes/nhk-v3/video.php`
- Modify: `public/wp-content/themes/nhk-v3/single.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoThumbnailSelectorTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoSeoProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php`

**Interfaces:**
- Consumes the existing YouTube thumbnail URL list and an approved HTTP/readability callback (WP HTTP in production, deterministic callback in tests).
- Produces selected URL, source variant, actual width/height, probe timestamp/hash and a safe fallback result without creating Media.

- [ ] **Step 1: Write failing selector/projection tests.** Assert quality order `maxresdefault`, `sddefault`, `hqdefault`, `mqdefault`, `default`; filename alone cannot win; invalid/placeholder/low-resolution responses are rejected; dimensions are read from bytes; SEO `VideoObject.thumbnailUrl`, sitemap and frontend query use the selected candidate.

- [ ] **Step 2: Run selector/projection tests and confirm RED.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VideoSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoThumbnailSelectorTest.php`

Expected: selector class is missing and existing projections still return `thumbnail_urls[0]`.

- [ ] **Step 3: Implement the bounded selector.** Probe only HTTPS source URLs via the approved boundary, reject non-image/error/placeholder bodies, read actual dimensions, prefer the highest valid candidate, and return structured selection metadata. Preserve the original candidate list and never create a Media identity.

- [ ] **Step 4: Persist selection in the existing Video source metadata projection.** Extend the YouTube source packet in-place with selection metadata and keep old `thumbnail_urls` readable. Ensure source refresh re-probes and updates the selection timestamp/hash through the existing Video governed update path.

- [ ] **Step 5: Switch all projections and rendering to the selected result.** Update SEO, `VideoObject`, sitemap, archive/detail/related/home/article-card data and theme templates. Use intrinsic dimensions plus responsive `srcset/sizes` where the current theme data supports it; do not upscale a tiny fallback when no larger candidate is valid.

- [ ] **Step 6: Run focused projection/frontend tests and commit.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VideoThumbnailSelectorTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticDossierTest.php`

Expected: selected thumbnail metadata and all consumer projections agree.

Run: `git add public/wp-content/plugins/nhk-core/src/Application/Video/VideoThumbnailSelector.php public/wp-content/plugins/nhk-core/src/Application/Video/YouTubeDataApiClient.php public/wp-content/plugins/nhk-core/src/Application/Video/VideoSeoProjection.php public/wp-content/plugins/nhk-core/src/Application/Video/VideoSitemapProjection.php public/wp-content/plugins/nhk-core/src/Application/Media/MediaVideoPageQuery.php public/wp-content/plugins/nhk-core/src/Application/Entity/SemanticDossierQuery.php public/wp-content/plugins/nhk-core/src/Application/Home/HomeSemanticQuery.php public/wp-content/themes/nhk-v3/video.php public/wp-content/themes/nhk-v3/single.php public/wp-content/plugins/nhk-core/tests/Unit/VideoThumbnailSelectorTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoSeoProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php && git commit -m "fix: project best usable video thumbnail"`

### Task 5: Improve Article Media publication guidance

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaResult.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`

**Interfaces:**
- Consumes existing MediaUsage slot state and eligible canonical Video read model.
- Produces the existing diagnostic codes plus a structured guidance object with featured/inline missing flags, expected subject, preferred aspect/view, optional Video thumbnail fallback eligibility and upload preference.

- [ ] **Step 1: Write failing UX payload tests.** Assert `MEDIAUSAGE_INCOMPLETE` yields Vietnamese conversational guidance, structured slot flags, subject/aspect and optional Video fallback metadata while publication remains blocked. Assert a real uploaded Media still passes through the existing governed Media path.

- [ ] **Step 2: Run the focused media/publication tests and confirm RED.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`

Expected: current payload exposes only technical codes and no guidance envelope.

- [ ] **Step 3: Add guidance without changing gate semantics.** Derive slot state from existing MediaUsage, pass expected subject and preferred visual intent from the blueprint, and expose an optional selected Video thumbnail candidate only when the canonical Video is eligible. Do not auto-download, adopt, or create Media.

- [ ] **Step 4: Run focused tests and commit.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`

Expected: guidance is structured and human-readable while all legitimate blockers remain enforced.

Run: `git add public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaResult.php public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php && git commit -m "feat: explain article image publication blockers"`

### Task 6: Return exact claim-level compliance diagnostics

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Projection/ClaimClassifier.php` only if existing classification cannot be reused
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php`

**Interfaces:**
- Consumes current Article subject, claims, evidence and the existing claim classifier/scope policy.
- Produces compliance diagnostics with `claim_text`, `claim_class`, `scope`, `reason`, `evidence_status`, `suggested_rewrite` and `review_required`, without adding a closed semantic vocabulary.

- [ ] **Step 1: Write failing claim-level tests.** Assert unsupported superiority/uniqueness output identifies the exact claim, canonical scope and missing/mismatched evidence; a descriptive rewrite narrows meaning; synonym-swapping a superlative remains blocked; human review remains true when required.

- [ ] **Step 2: Run the focused Article tests and confirm RED.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php`

Expected: compliance is currently the generic `HUMAN_REVIEW_REQUIRED` packet.

- [ ] **Step 3: Implement claim-level diagnostics.** Use existing claim text/type/scope/evidence fields and classifier outputs; produce a genuinely narrower descriptive rewrite only where the policy allows it, otherwise return null rewrite plus mandatory review. Preserve current blocker `PUBLIC_CLAIM_COMPLIANCE_BLOCKED` and policy version.

- [ ] **Step 4: Run focused tests and commit.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`

Expected: exact diagnostics are available to MCP/Admin while publication remains fail-closed.

Run: `git add public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php public/wp-content/plugins/nhk-core/src/Application/Projection/ClaimClassifier.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php && git commit -m "feat: expose claim-level compliance diagnostics"`

### Task 7: Documentation bootstrap, full verification and live acceptance

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Do not modify live data files, environment files or credentials.

- [ ] **Step 1: Read fresh runtime documentation.** Invoke the available documentation bootstrap boundary, then read the fresh READ_FIRST, status index, execution state and ACTIVE Capture, Video, Governance, Article, Media, public claims, Public Identity and SEO contracts through the same runtime connection used for acceptance. Record the checkpoint hash/version.

- [ ] **Step 2: Run the complete quality suite.**

Run: `vendor/bin/phpunit --configuration phpunit.xml.dist`

Run: `composer lint`

Run: `git diff --check`

Run: `git status --short --branch`

Run a secret review over changed files, excluding `.git`, local env files and binary assets; confirm no token/private key/API secret is staged.

Expected: unit/contract suites pass; integration either passes guarded environment or reports exact mandatory environment blocker; lint and diff checks exit 0.

- [ ] **Step 3: Run live acceptance through `nhk.capture.ingest`.** Use only existing Capture `01a096c0-97cc-7192-acf2-4735f9bf6582` with `resume_children:["video"]`, external ID `VwP1AH9E3HA`, intended Variant `7301f50c-ef0d-4e95-a581-39e5063d4648`, and the fresh documentation checkpoint. Do not create new Capture/Post/Variant/Video. Reuse intended Video candidate `01a096c0-9c59-7cee-b1b6-7bdd445345ac` where valid.

- [ ] **Step 4: Verify live read-backs and duplicates.** Read Capture, Article 467, proposal list by exact idempotency/external identity, canonical Video, Graph outgoing `about`, Public Identity, Video SEO/VideoObject, selected thumbnail dimensions, frontend routes, Article MediaUsage, media guidance and claim diagnostics. Repeat the same resume once to prove idempotency and one-edge/one-owner counts.

- [ ] **Step 5: Update execution state with evidence.** Append a checkpoint describing code root causes, tests/counts, live acceptance result, exact blockers if any, context URL limitation (`Cache miss`), and whether deployment readiness is justified. Do not label COMPLETE unless every requested read-back and legitimate publication requirement passes.

- [ ] **Step 6: Final diff/secret review and report.** Confirm only task-scoped changes plus preserved prior user changes are present, then produce the requested `ROOT_CAUSES=...` report with PASS/FAIL per field.

---

## Plan self-review

- Subject handoff requirements map to Task 1.
- Proposal identity, approval envelope, idempotency and resume map to Tasks 2–3.
- Thumbnail probing and every named projection consumer map to Task 4.
- Article media guidance and claim-level compliance map to Tasks 5–6.
- Fresh documentation, quality gates, exact live IDs, duplicate checks and final report map to Task 7.
- No task authorizes a new semantic type, direct writer, data migration or publication-gate bypass.
- No placeholders or unresolved implementation choices are used; a production file is modified only if its failing test proves the existing boundary is the correct owner.

