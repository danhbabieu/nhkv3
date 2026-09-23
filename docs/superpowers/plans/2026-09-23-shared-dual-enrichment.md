# Shared Dual Enrichment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add one transient shared enrichment boundary that reuses the existing eligible-Claim retrieval/selection and governed Knowledge candidate/proposal services for Article, Video, Media, and Knowledge Delta profiles.

**Architecture:** `SharedEnrichmentBoundary` will be a small application coordinator with independent content, knowledge, and relation result branches. Article and Video adapters will consume its content branch and retain their existing downstream composition/quality logic; Knowledge candidate planning will remain read-only and proposal shaping will remain an existing envelope operation. No persistence, owner creation, Graph authorization, Controlled Apply, or publication is added.

**Tech Stack:** PHP 8+, PHPUnit, existing NHK V3 application/domain contracts and runtime registries.

**Spec:** `docs/superpowers/specs/2026-09-23-shared-dual-enrichment-design.md`

## Global Constraints

- WordPress `wp_posts` remains the sole Article editorial truth.
- Working enrichment context is transient and never becomes a semantic record, Graph edge, Evidence record, approval, or canonical subject.
- Only eligible canonical Claims may support factual editorial output.
- Generated Article/Video prose is never Source or Evidence.
- Knowledge mutation remains Proposal → Approval → Eligibility → Controlled Apply → canonical read-back.
- Checkpoints 1–3, exact subject precedence, idempotency, owner ordering, MediaUsage ownership, and fail-closed readiness remain unchanged.
- No schema/data migration, backfill, staging/live mutation, deployment, publication, or Checkpoint 5 work.

## Review Focus

- Missing or partial content retrieval must return a local incomplete branch without blocking an admitted owner; test Article and Video sparse retrieval.
- An authoritative prepared subject must scope shared retrieval and weaker hints must not replace it; test Article prepared-context binding.
- Same-claim, add-evidence, qualify, contradict, new-claim, ambiguous, and unsupported Knowledge outcomes must retain provenance and readiness; test each classification through the shared boundary.
- Generated editorial prose must never enter Knowledge candidate provenance; test rejection/diagnostic behavior.
- Relation readiness must remain independent and non-applied; test that the relation branch reports findings without invoking Governance or mutation.

---

### Task 1: Add the transient shared result and boundary

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentResult.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SharedEnrichmentBoundaryTest.php`

**Interfaces:**
- `SharedEnrichmentBoundary::enrich(array $request): array` dispatches `content`, `knowledge`, and `relations` profile work without persistence.
- The result contains `content`, `knowledge`, and `relations` keys, each with `status`, payload, and `diagnostics`/`readiness` data.

- [ ] Write tests for Article/Video content, Knowledge classifications, provenance safety, Media/Knowledge profiles, and independent relation readiness.
- [ ] Run the new test file and confirm the missing boundary/result behavior fails.
- [ ] Implement the boundary by composing `EditorialClaimRetrievalService`, `EditorialKnowledgeSelector`, `KnowledgeEnrichmentPlanner`, `KnowledgeEnrichmentProposalFactory`, and an optional relation candidate callable; never compose final prose or invoke mutation.
- [ ] Validate request profile and reject generated-prose-as-observation with a deterministic diagnostic; preserve canonical IDs, revisions, scope, locator, and classification.
- [ ] Run the new test file and confirm it passes.

### Task 2: Route Article and Video shared retrieval through the boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleEditorialAdapter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Video/VideoEditorialAdapter.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleEditorialAdapterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VideoEditorialAdapterTest.php`

**Interfaces:**
- `fromEngine()` constructs the boundary from the same retrieval and selector services already used by each adapter.
- `prepare()` continues returning the existing adapter keys (`retrieval`, `pack`, `plan`, `draft`, `seo_plan`, `quality_report`) and adds only shared branch diagnostics.

- [ ] Add regression assertions that Article and Video use the shared content branch while keeping Article and Video profiles, budgets, SEO structured-data types, and downstream decision pipelines distinct.
- [ ] Implement the smallest constructor/factory change needed to inject/use the boundary; keep prepared-context binding before selection and keep all composition downstream.
- [ ] Run both adapter tests and confirm existing output contracts remain stable.

### Task 3: Cover the full acceptance matrix and repository verification

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/SharedEnrichmentBoundaryTest.php`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [ ] Add matrix cases for Article, Video, Media, Knowledge Delta; duplicate, add-evidence, conflict, novelty, ambiguity, unsupported, sparse retrieval, relation incompleteness, and idempotent repeated enrichment.
- [ ] Run the focused Capture/Article/Video/Media/Knowledge/shared suite.
- [ ] Run the full Unit suite and repository suite when the documented WordPress test environment is available; record environment-gated results without weakening tests.
- [ ] Run PHP lint on every changed PHP file and `git diff --check`.
- [ ] Update the execution state with CP4 local result, exact test counts, environment-gated failures, and explicit no-mutation/deferred-CP5 evidence.
