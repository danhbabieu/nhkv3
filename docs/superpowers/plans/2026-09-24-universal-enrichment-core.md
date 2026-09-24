# Universal Enrichment Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (native execution) or superpowers:subagent-driven-development (subagent execution) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Evolve the existing shared semantic enrichment path into one domain-neutral, transient Universal Enrichment Core serving all configured owner/source types without creating owner-specific enrichment brains or bypassing Governance.

**Architecture:** `SharedEnrichmentBoundary` remains a thin coordinator. The current `SemanticInputEnvelope` becomes the single `UniversalInputEnvelope` contract, and the shared content/Knowledge/relation outputs become an `EnrichmentPack` read model with independent branch readiness. Existing Article, Video, Media, entity and source adapters map into and consume the pack while retaining their own lifecycle, quality, publication and persistence boundaries.

**Tech Stack:** PHP 8+, PHPUnit, existing NHK V3 runtime registries/contracts, WordPress plugin application services, Composer tooling.

**Spec:** `docs/superpowers/specs/2026-09-24-universal-enrichment-core-design.md`

## Global Constraints

- WordPress `wp_posts` remains the sole source of truth for Article editorial content and URLs.
- Authority, Knowledge, Source/Evidence, Graph, Governance, Media, MediaAsset, MediaUsage and Video retain their existing ownership boundaries.
- The Universal Input Envelope and EnrichmentPack are transient; neither is canonical storage, Evidence, a Graph edge, approval or publication authority.
- Only eligible, applicable and publicly composable canonical Claims may support factual content output.
- Generated prose, captions, OCR, transcripts, MediaUsage metadata, titles and hints never become Knowledge or Evidence automatically.
- Canonical UUID/stable-key, revision, provenance, readiness, idempotency and fail-closed rules remain unchanged.
- No new entity type, endpoint type, predicate, relation type, canonical field, migration, backfill, staging/production mutation, deployment or cutover is included.
- Existing authoritative subject packets always outrank lexical, title, filename, source and machine-derived hints.
- Media remains honest with `MEDIA_ADAPTER_NOT_YET_CONNECTED` until a real Media lifecycle is connected.
- Relation discovery is read/planning-only in the shared core; relation application remains Graph/Governance-owned.
- Odo 24/1957 is regression evidence only and must not shape class names, vocabularies, selectors or owner architecture.

## Review Focus

- Missing envelope fields: return bounded sparse/unavailable diagnostics instead of invented defaults; test in the universal contract task.
- Conflicting subject hints: retain the authoritative subject packet and reject broader replacement; test in semantic decomposition and adapter tasks.
- Dominant and sparse facets: allocate bounded retrieval opportunity per need before global pruning; test in facet-aware retrieval integration.
- Broader contextual Claims: preserve treatment/scope and prevent narrower direct assertion; test in pack/selection compatibility.
- Unconnected Media lifecycle: return `MEDIA_ADAPTER_NOT_YET_CONNECTED` without claiming completion; test in adapter seam task.
- Generated editorial prose and observations: allow candidate discovery only through the Knowledge proposal path and reject generated prose as Evidence; test in Knowledge branch task.
- Relation candidates: expose typed read/planning output without applying a relation or invoking Governance; test in relation branch task.
- Existing Article/Video behavior: preserve downstream composition, SEO, quality, publication and owner admission contracts; test in compatibility task.

## File Map

**Create or evolve shared transient contracts:**

- `public/wp-content/plugins/nhk-core/src/Application/Semantic/UniversalInputEnvelope.php` — canonical transient input contract; carries optional owner/source, subject packet, text, observations, metadata, relations, existing Knowledge, provenance, intent and constraints.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/EnrichmentPack.php` — immutable shared result read model with independent content, relation and Knowledge-candidate branches.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/UniversalEnrichmentCore.php` — coordinates understanding, decomposition, retrieval, applicability, relaxation, KnowledgeUnit/coverage, relation discovery and Knowledge candidate planning; never persists or applies.
- `public/wp-content/plugins/nhk-core/src/Application/Media/MediaUniversalEnrichmentAdapter.php` — read/planning-only Media/Image adapter; returns the honest unavailable state until a governed Media lifecycle is connected.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticInputEnvelope.php` — compatibility adapter only during migration; no peer semantics.
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php` — compatibility-facing entry point that delegates to the core and preserves current callers during the transition.

**Modify shared semantic services:**

- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedDecomposer.php`
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedVocabulary.php`
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php`
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php`
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php`
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeUnitBuilder.php`
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/ReaderJourneyPlanner.php`
- `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEditorialComposer.php`

**Modify owner/source seams:**

- `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php`
- `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- `public/wp-content/plugins/nhk-core/src/Application/Media/MediaUniversalEnrichmentAdapter.php`
- `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentPlanner.php`
- `public/wp-content/plugins/nhk-core/src/Application/Graph/ExplicitRelationIntentPlanner.php`

**Tests:** add focused tests under `public/wp-content/plugins/nhk-core/tests/Unit/` and extend the existing semantic, Article, Video, Media, Knowledge and Graph tests. Do not create fake owner fixtures or canonical records outside existing test contracts.

**Documentation:** update `docs/architecture/V3_EXECUTION_STATE.md` only after verified checkpoints; do not modify the Constitution.

---

### Task 1: Define the universal transient contracts

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/UniversalInputEnvelope.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EnrichmentPack.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticInputEnvelope.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalEnrichmentContractTest.php`

**Interfaces:**
- `UniversalInputEnvelope::fromArray(array $input): self` and `toArray(): array`.
- `EnrichmentPack::fromBranches(array $branches): self` and `toArray(): array`; branch keys are `content`, `relations`, `knowledge` and each branch has explicit `status`, payload and `diagnostics`/`readiness` fields.
- `SemanticInputEnvelope` delegates normalization to `UniversalInputEnvelope` for compatibility; new code must depend on the universal contract.

- [ ] Write failing tests for optional fields, normalized origins, authoritative `subject_resolution`, existing relations/Knowledge references, provenance/confidence/constraints, deterministic serialization and missing-field diagnostics.
- [ ] Run `vendor/bin/phpunit --testsuite Unit --filter UniversalEnrichmentContractTest` from `public/wp-content/plugins/nhk-core`; confirm the new classes/tests fail before implementation.
- [ ] Implement immutable normalization. Preserve absent values as absent/empty with explicit diagnostics; never infer canonical identity, evidence or owner state from title/text.
- [ ] Implement `EnrichmentPack` with bounded branch arrays and stable serialization; reject unknown branch structure rather than silently dropping readiness information.
- [ ] Run the focused contract test and confirm PASS.
- [ ] Run `git diff --check` and commit: `git commit -m "feat: add universal enrichment transient contracts"`.

### Task 2: Move semantic decomposition behind the universal input

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedDecomposer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedVocabulary.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeed.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/TextInputInterpreter.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalSemanticNeedDecomposerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticNeedTest.php`

**Interfaces:**
- `SemanticNeedDecomposer::decompose(UniversalInputEnvelope $input): SemanticNeedDecompositionResult`.
- `SemanticNeed::fromArray(array $data): self` remains the compatibility constructor and must continue preserving canonical subject, facet/concept, scope, origin, confidence and policies.

- [ ] Write failing tests proving five independent concepts become deterministic deduplicated needs, equivalent wording merges, missing subject remains fail-closed, and a conflicting lexical/source hint cannot replace the authoritative subject packet.
- [ ] Run the two focused test filters and record the expected failures.
- [ ] Implement decomposition using registered vocabulary/interpreter inputs only. Preserve `accepted`, `merged`, `unresolved` diagnostics and origin/scope; do not add Odo/title phrase lists.
- [ ] Keep existing array callers working through a narrow adapter at the boundary, not in each owner adapter.
- [ ] Run focused decomposition and existing `SemanticNeed` tests; confirm PASS.
- [ ] Run `git diff --check` and commit: `git commit -m "feat: make semantic needs owner neutral"`.

### Task 3: Add universal multi-need retrieval and traced relaxation

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ClaimRetrievalEngine.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticNeedRetrievalPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/CoverageAspect.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalFacetRetrievalTest.php`
- Extend: `public/wp-content/plugins/nhk-core/tests/Unit/FacetAwareClaimRetrievalTest.php`
- Extend: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticRelaxationTest.php`

**Interfaces:**
- `ClaimRetrievalEngine::retrieveForNeeds(UniversalInputEnvelope $input, array $needs, array $profile = []): array` remains the multi-need entry point and returns merged candidates plus per-need diagnostics.
- Each candidate preserves canonical Claim identity/revision, subject, scope, facet/concept, applicability, provenance/evidence, `need_ids`, retrieval tier, semantic distance and treatment.

- [ ] Write failing tests for per-need opportunity allocation before global pruning, one Claim linked to multiple needs without duplication, exact coverage stopping relaxation, sparse facet retention, unsafe tier skipping, and broader context not satisfying a narrower direct fact.
- [ ] Run focused retrieval/relaxation tests and confirm failures.
- [ ] Implement deterministic allocation and per-need tier progression using registered policies; merge by canonical Claim identity plus revision and preserve all need links.
- [ ] Keep the existing single-subject `retrieve()` path as a compatibility adapter over the same candidate metadata rules.
- [ ] Add bounded diagnostics for allocation, initial/eligible counts, rejection reasons, relaxation rounds and stop reason; exclude raw text/secrets.
- [ ] Run focused and existing facet/relaxation tests; confirm PASS.
- [ ] Run `git diff --check` and commit: `git commit -m "feat: add universal facet retrieval path"`.

### Task 4: Produce the shared EnrichmentPack and preserve selection behavior

**Files:**
- Create/modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/UniversalEnrichmentCore.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialKnowledgeSelector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/KnowledgeUnitBuilder.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/ReaderJourneyPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEditorialComposer.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalEnrichmentCoreTest.php`
- Extend: `public/wp-content/plugins/nhk-core/tests/Unit/ReaderJourneyCoverageTest.php`
- Extend: `public/wp-content/plugins/nhk-core/tests/Unit/SharedEditorialComposerTest.php`

**Interfaces:**
- `UniversalEnrichmentCore::enrich(UniversalInputEnvelope $input, array $options = []): EnrichmentPack`.
- `SharedEnrichmentBoundary::enrich(array $request): array` remains a compatibility façade and serializes/unwraps the pack only at the legacy boundary.
- Existing editorial consumers continue receiving `EditorialContextPack` through an explicit projection from the shared pack; no consumer may treat the typed pack as an array.

- [ ] Write failing tests for content, relation and Knowledge branch independence; deterministic repeated enrichment; sparse/unavailable content; and preservation of KnowledgeUnit coverage, supporting claims, treatment and quality trace.
- [ ] Run focused core/coverage/composer tests and confirm failures.
- [ ] Implement the core as a coordinator over the existing decomposer, retrieval, selector, KnowledgeUnit, coverage and adaptive-selection services. It must not compose final prose, create owners, persist data or invoke Governance.
- [ ] Project shared candidates into the existing editorial pack without weakening eligibility, applicability, evidence or public-composability checks.
- [ ] Preserve the current `profile` compatibility behavior while accepting generic owner/source types through the universal envelope; do not hardcode a new semantic owner.
- [ ] Run focused plus existing semantic tests and confirm PASS.
- [ ] Run `git diff --check` and commit: `git commit -m "feat: compose universal enrichment pack"`.

### Task 5: Add Knowledge candidate and relation branches safely

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Knowledge/KnowledgeEnrichmentProposalFactory.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Graph/ExplicitRelationIntentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/UniversalEnrichmentCore.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalKnowledgeBranchTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalRelationBranchTest.php`

**Interfaces:**
- Knowledge discovery consumes `UniversalInputEnvelope` observations/source evidence and returns candidates/proposals/readiness without applying them.
- Relation discovery returns typed candidates and readiness/read-only diagnostics; it does not call a relation apply service or Governance.

- [ ] Write failing tests for duplicate, add-evidence, qualify, contradict, new-claim, ambiguous and unsupported outcomes with subject/revision/provenance retained.
- [ ] Write failing tests proving generated Article/Video prose is rejected, specimen observations remain scoped, and relation candidates are returned without mutation.
- [ ] Run focused Knowledge/relation filters and confirm failures.
- [ ] Implement the branches as independent pack outputs. Preserve the existing proposal factory and Governance handoff contract; never promote generated prose, captions, OCR or MediaUsage into Evidence.
- [ ] Return explicit `NOT_REQUESTED`, `INCOMPLETE`, `REVIEW_REQUIRED`, `PROPOSAL_READY`, `AVAILABLE` or unavailable diagnostics according to existing readiness vocabulary; do not collapse empty data into success.
- [ ] Run focused tests and existing Knowledge/Graph tests; confirm PASS.
- [ ] Run `git diff --check` and commit: `git commit -m "feat: add safe knowledge and relation enrichment branches"`.

### Task 6: Route owner/source adapters through the shared core

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaUniversalEnrichmentAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php` only if its existing envelope mapping requires the universal contract.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleEditorialAdapterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialAdapterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/UniversalOwnerAdapterTest.php`

**Interfaces:**
- Article and Video adapters map existing context into `UniversalInputEnvelope`, consume the shared content pack, and retain their existing downstream output keys and decision pipelines.
- Media adapter maps image/caption/visual observations and returns `MEDIA_ADAPTER_NOT_YET_CONNECTED` when no governed lifecycle exists.
- Generic text/note/source input can call the core without requiring an Article or Video owner.

- [ ] Write failing compatibility tests proving Article and Video use the same shared retrieval/decomposition path while retaining profile-specific budgets, SEO structured data, quality and publication behavior.
- [ ] Write failing tests for authoritative Capture subject packets, sparse Media input, generic text/source input and honest Media unavailable state.
- [ ] Run focused adapter tests and confirm failures.
- [ ] Implement thin mappings only. `MediaUniversalEnrichmentAdapter::enrich(UniversalInputEnvelope $input): EnrichmentPack` must return `MEDIA_ADAPTER_NOT_YET_CONNECTED` without writing Media, MediaAsset or MediaUsage. Keep Video source/YouTube identity, Article WordPress ownership, MediaAsset/MediaUsage boundaries and all existing owner admission/read-back rules unchanged.
- [ ] Ensure no adapter creates owner-specific enrichment brain classes or copies raw editorial bodies into semantic storage.
- [ ] Run Article, Video, Media, Capture and universal adapter suites; confirm PASS.
- [ ] Run `git diff --check` and commit: `git commit -m "feat: connect owner adapters to universal enrichment"`.

### Task 7: Verify the full matrix and update execution state

**Files:**
- Extend: focused semantic, Article, Video, Media, Knowledge, Graph and Capture unit tests as failures reveal missing contract coverage.
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`.

- [ ] Run the focused matrix covering universal contracts, needs, retrieval, relaxation, core, Article, Video, Media, Knowledge, Graph and Capture.
- [ ] Run the complete Unit suite with the repository-standard memory setting; record exact test/assertion counts and existing warnings/deprecations.
- [ ] Run guarded integration only against the documented `nhk_v3_test` environment if available. If WordPress/database infrastructure is unavailable, record `INTEGRATION_ENVIRONMENT_GATED`, not a false pass.
- [ ] Run PHP lint on every changed PHP file, Composer validation/lint, `git diff --check`, and a changed-scope secret review.
- [ ] Confirm no migration, database mutation, owner creation, publication, deployment, retry or staging/production semantic write occurred.
- [ ] Update `V3_EXECUTION_STATE.md` with the checkpoint, first broken boundary if any, exact verification evidence and deferred work; do not claim Media completion when the adapter remains unavailable.
- [ ] Commit the verified implementation and execution-state evidence: `git commit -m "chore: verify universal enrichment core"`.
