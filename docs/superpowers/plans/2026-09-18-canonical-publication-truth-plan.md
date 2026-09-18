# Canonical Publication Truth and Retry Convergence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make compliance scope, publication evidence, and Capture retry status derive from the correct current owner state while preserving historical audit evidence.

**Architecture:** Reuse the existing Article `claim_trace` as the publication claim-selection owner; Graph/Knowledge retrieval remains discovery input. Add a bounded canonical evidence assembly/readback step before `ArticlePublicationGate`, with target-scoped Article MediaUsage and subject persistence read from existing owners. Extend the existing JSON phase-receipt payload with append-only attempt history plus a derived latest outcome; do not add a semantic owner or a second truth store.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress-native editorial adapters, existing NHK V3 repositories/services, JSON Capture context/diagnostic/receipt columns.

**Spec:** `docs/superpowers/specs/2026-09-18-canonical-publication-truth-design.md`

## Global Constraints

- WordPress `wp_posts` remains the sole editorial truth.
- Graph neighborhood and Claim retrieval are discovery only; only selected/editorial-trace/asserted claims enter publication compliance.
- `ArticlePublicationGate` consumes verified current evidence and does not become a repository or semantic owner.
- Current canonical readback outranks historical Capture planning; missing current evidence remains fail-closed.
- Historical failed attempts remain append-only audit evidence; current outcome is derived from latest required-phase outcomes.
- Media, MediaAsset, MediaUsage and attachment remain distinct; Article slots are target-scoped `wp_post:<blog>:<post>` usages, not representative/global usages.
- Governance remains mandatory for genuine semantic mutations; `NONE` remains `NOT_REQUIRED`.
- No hard-coded production IDs, object-specific allowlists, generic WordPress writer, direct DB semantic mutation, deployment, push, SSH or live acceptance.
- Prefer no migration: phase history/current outcome fits existing JSON columns and is revision/CAS persisted by `WpdbCaptureRepository`.

## Earliest wrong assumptions and owner map

### Defect A

- **Earliest wrong assumption:** `ArticleResearchPreflight::publicationClaims()` treats the entire retrieved `knowledge` neighborhood as publication claims when Article context lacks a trace/selection.
- **Current wrong-scope class:** `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php`.
- **Correct owner:** Article composition's existing body-free `claim_trace`, produced by `ArticleComposer` from selected canonical claims; rendered/public assertion mapping remains Article/SEO/MediaUsage projection evidence.
- **Data change:** use trace/selected records already in `articleContext`; do not introduce `selected_claim_ids` persistence or a compliance database. Return an explicit empty publication set when no selected/publicly asserted claim exists.
- **RED tests:** `ArticleResearchPreflightTest` with four neighboring claims/two selected; unsupported unselected claim; selected unsupported claim; selected supported claim; and no selection.

### Defect B

- **Earliest wrong assumption:** the `CaptureArticlePreflightHandoff` evidence snapshot is treated as canonical after Article/media/subject reconciliation, even though it is assembled from planning/result DTOs.
- **Current wrong-state classes:** `CaptureArticlePreflightHandoff`, the injected publication callback in `EditorialCaptureCoordinator`, and any current publication review adapter that forwards the stale snapshot.
- **Correct owners:** `EditorialPostStore`/`EditorialStateReader` for native Post/token; current subject persistence/readback owner used by Article research; `ArticleMediaCoordinator` plus `MediaUsageRepository` for target-scoped slots; existing semantic readback; category/Public Identity/SEO/route readers.
- **Data change:** add a bounded canonical evidence assembly port/service around existing owner readers. It may accept the current Article ID/token and historical context as diagnostics, but it must construct gate fields from fresh reads and refresh at most once on a changed token. No DTO becomes a second source of truth.
- **RED tests:** stale plan versus complete current WP/MediaUsage; persisted subject after planning candidate; genuinely missing current media; concurrent native write requiring exactly one refresh; same Media in featured+inline with representative usage preserved; Post-573-like complete regression.

### Defect C

- **Earliest wrong assumption:** phase receipt maps are treated as the current phase result, and `EditorialCaptureContinuationService::retry()` exposes the old `diagnostics.failure.code` instead of deriving a latest required-owner outcome.
- **Current wrong-state classes:** `EditorialCaptureCoordinator::save/startReceipt`, `GovernedCaptureContinuationService::emitPhaseReceipt`, completion aggregation, and `EditorialCaptureContinuationService::retry` response mapping.
- **Correct owner:** Capture durable phase receipts plus completion required-owner aggregation. Audit history is Capture-owned; no new failure store is needed.
- **Data change:** append an `attempts[]` record per phase and derive `latest`/`current_outcome` from the newest authoritative receipt. A successful current attempt may carry `resolves_attempt`/`supersedes_failure_code` as derived metadata; it must not rewrite or delete old receipt entries. Current status/code uses latest required phase plus completion owner requirements.
- **RED tests:** old collision then verified exact reuse; old failure then retry success with audit retained and current code cleared; current unresolved child failure; multiple historical failures then success; repeated successful retry idempotency.

## Migration decision

No migration is planned. `phase_receipts_json` already stores arbitrary JSON and
`WpdbCaptureRepository` persists it with optimistic Capture revision checks.
The implementation will preserve the legacy single-receipt shape for readers
that only need the latest phase result while adding append-only `attempts` and
derived `latest` fields. A migration would add schema risk without adding a
new owner or query requirement. If runtime inspection proves an existing
contract requires relational querying of attempts, stop before changing schema
and report the exact conflict; do not silently migrate.

## Implementation tasks

### Task 1: Establish generic RED fixtures and shared current-state shapes

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:** Tests use existing `ArticleResearchPreflight`, `ArticlePublicationGate`, `EditorialCaptureCoordinator`, `EditorialCaptureContinuationService` and in-memory repositories. No production API is invented in this task.

- [ ] **Step 1: Add one failing compliance test** asserting four neighboring claims produce diagnostics only for the two claims present in the existing `claim_trace`/selected editorial input.
- [ ] **Step 2: Add failing tests** for selected unsupported, selected supported, and no selected claims; assert the unselected unsupported claim cannot produce `PUBLIC_CLAIM_COMPLIANCE_BLOCKED`.
- [ ] **Step 3: Add failing publication tests** for stale media/subject planning versus fresh current evidence, current missing media, target-scoped Article slots versus representative usages, and a Post-573-like complete state.
- [ ] **Step 4: Add failing retry tests** for historical collision → verified reuse, unresolved current child failure, multiple historical failures → current success, and repeated same-key success.
- [ ] **Step 5: Run the focused tests and confirm each fails for the missing invariant rather than a fixture/setup error.**

Run: `vendor/bin/phpunit -c public/wp-content/plugins/nhk-core/phpunit.xml public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

### Task 2: Enforce publication-unit claim scope

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleResearchPreflight.php`
- Review only: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ArticleComposer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleResearchPreflightTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`

**Interfaces:** `ArticleComposer::compose()` remains the producer of `claim_trace`; `ArticleResearchPreflight::research()` continues to return `ArticleResearchResult`. The private selection helper must consume the existing trace/selection and rendered-copy context without persisting a new selection owner.

- [ ] **Step 1: Make the failing tests assert the desired boundary before implementation.**
- [ ] **Step 2: Change `publicationClaims()` so Graph `knowledge` is never the implicit publication set.** When trace/selection is present, resolve only matching canonical IDs/revisions and asserted mappings; when no selection exists, return only claims proven by the registered rendered/public assertion mapping, otherwise `[]`.
- [ ] **Step 3: Keep discovery claims in `knowledgeInventory['claims']` for research/diagnostics, but pass the scoped set to `claimEvidencePolicy()` and `claimComplianceDiagnostics()`.
- [ ] **Step 4: Preserve selected-claim scope/provenance/revision in diagnostics and fail closed if a selected claim cannot be resolved/read back; do not broaden by subject or relation path.**
- [ ] **Step 5: Run the two focused test files and confirm unsupported neighboring claims disappear while selected unsupported claims still require review/blocking.**

### Task 3: Build publication evidence from fresh canonical owner readback

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureArticlePreflightHandoff.php`
- Modify or create: `public/wp-content/plugins/nhk-core/src/Application/Article/CanonicalPublicationEvidenceBuilder.php` (only if the existing composition root has no equivalent owner-bound builder)
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Review/modify only as required: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`, `public/wp-content/plugins/nhk-core/src/Contracts/Media/MediaUsageRepository.php`, `public/wp-content/plugins/nhk-core/src/Contracts/Article/EditorialStateReader.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureSemanticCoreTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`

**Interfaces:** The builder consumes the existing owner read ports and returns a gate evidence array bound to `{capture_id, article_id, editorial_state_token}`. It may expose `refresh_count` for verification, but it does not persist semantic state. The coordinator invokes it once for normal review and once only when the current token/readback reports a concurrent native change.

- [ ] **Step 1: Write a failing handoff/builder test** where planning says subject/media missing but current subject binding, Post slots, MediaUsage and public route are complete; assert current evidence is complete.
- [ ] **Step 2: Write a failing test** where featured/inline representative usages exist but Article target-scoped slots are missing; assert `MEDIAUSAGE_INCOMPLETE` and `ARTICLE_MEDIA_FEATURED_MISSING` remain.
- [ ] **Step 3: Write a failing test** where native write changes the token during review; assert exactly one refresh and no stale plan replay/loop.
- [ ] **Step 4: Implement owner-bound evidence assembly.** Refresh the native Post/token, subject persistence, target-scoped Article MediaUsage slots and attachment state, semantic readback, category, public identity, SEO and route. Preserve historical planning only under diagnostics/recovery context.
- [ ] **Step 5: Ensure featured/inline diagnostics read only `wp_post:<blog>:<post>` Article slots and distinguish the same canonical Media from representative usages.
- [ ] **Step 6: Wire the coordinator’s publication callback to the builder and preserve the existing `ArticlePublicationGate` decision contract. Do not make the gate call ArticlePreflight as a substitute for owner readback.
- [ ] **Step 7: Run focused Article/Capture/Media tests and verify the Post-573-like fixture remains eligible.**

### Task 4: Derive retry truth from append-only phase attempts

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Capture/CaptureRecord.php` only if a typed accessor is needed; keep JSON compatibility
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Capture/WpdbCaptureRepository.php` only if normalization is needed; no SQL/schema change
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureContinuationTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:** Add a small internal receipt reducer/accessor over `CaptureRecord::$phaseReceipts`; input is the prior receipt map plus one new attempt, output is the same JSON-compatible map with `attempts`, `latest`, and legacy latest-phase fields. Completion aggregation consumes current required-owner outcomes, not historical failures.

- [ ] **Step 1: Write a failing test** asserting a failed first attempt remains in `phase_receipts[phase].attempts`, while a later `REUSED_VERIFIED`/readback-complete attempt becomes `latest` and removes the old failure from current `retry.code`.
- [ ] **Step 2: Write a failing test** asserting a current unresolved child keeps `PARTIAL`/`FAILED` even when another phase succeeds.
- [ ] **Step 3: Write a failing test** asserting multiple historical failures remain auditable and a later all-required-owner success derives a complete current status.
- [ ] **Step 4: Implement append-only receipt recording.** Every phase execution receives an attempt identity/order, status/result/failure code, canonical readback and timestamps. Never overwrite/remove historical attempt entries.
- [ ] **Step 5: Implement current outcome reduction.** For each required phase/owner, select the latest authoritative attempt; mark old failures as derived `superseded`/`resolved_by` metadata only. Preserve a legacy top-level phase result as the latest result for compatibility.
- [ ] **Step 6: Change retry response mapping to use the reduced current outcome, not stale `diagnostics.failure.code`. Return `null`/absent current code after successful recovery while retaining the historical collision code in audit history.
- [ ] **Step 7: Ensure same-key replay returns the stored current outcome and does not create duplicate receipts, Media, Article or MediaUsage.**
- [ ] **Step 8: Run focused retry/convergence tests and inspect serialized Capture output for both history and current outcome.**

### Task 5: Contract/regression coverage and repository-level verification

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureArticlePreflightHandoffTest.php` if contract assertions need current evidence fields
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureMediaIdsContractTest.php` or the nearest existing Article/Media contract test only when a current target-scoped MediaUsage shape lacks coverage
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Review: `docs/architecture/V2_V3_PARITY_MATRIX.md` before any parity claim

- [ ] **Step 1: Run all focused suites for Compliance, Article, Capture, Publication, Media, Governance and Claim projection.**
- [ ] **Step 2: Run Contract tests; record exact pass/fail counts and distinguish baseline failures.**
- [ ] **Step 3: Run full Unit; if a baseline failure remains, run the exact parent comparison and record it without hiding or weakening tests.**
- [ ] **Step 4: Run guarded Integration if the required WordPress/MySQL environment is available. If unavailable or blocked, record `SKIPPED`/`BLOCKED`, never `PASS`.**
- [ ] **Step 5: Run changed-file PHP lint, `git diff --check`, and changed-scope secret review.**
- [ ] **Step 6: Update `docs/architecture/V3_EXECUTION_STATE.md` with the three earliest wrong assumptions, implementation evidence, test evidence, migration decision, and explicit no-deploy/no-live-mutation status.**
- [ ] **Step 7: Commit logical boundaries: implementation/tests, then execution-state evidence if separate. Verify `git status --short --branch` and report commit hashes.**

## Execution order and stop conditions

Execute Tasks 1–2, then Task 3, then Task 4, and finally Task 5. Stop and
report before implementation if a current contract requires a new schema,
locked invariant change, new semantic owner, or a contradiction with the
Constitution. Do not stop for ordinary test failures: diagnose them with the
existing systematic-debugging workflow, compare against baseline, and keep
historical evidence intact.
