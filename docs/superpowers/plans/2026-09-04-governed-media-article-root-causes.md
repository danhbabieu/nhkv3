# Governed Media and Article Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect real multipart image intake to one governed Media identity and make Article subject resolution deterministic while preserving source assets, projection boundaries and fail-closed publication behavior.

**Architecture:** Multipart input is normalized by the WordPress adapter, then enters `MediaIngestGateway`/`MediaService`; WordPress attachments are mapped storage/projection records and never semantic authority. Source-original and derivatives remain assets of one Media. Article resolution uses the shared `McpSemanticContextResolver` boundary, while Article preflight remains an operation-level gate and never owns Post identity.

**Tech Stack:** PHP 8.5, WordPress, MySQL/dbDelta, PHPUnit 11, existing NHK Core repositories and Governance/Controlled Apply services.

**Spec:** `docs/superpowers/specs/2026-09-02-media-ingest-image-seo-design.md`, plus Owner-approved contract amendments recorded in `docs/constitution/NHK_V3_CONSTITUTION.md` and `docs/architecture/ARTICLE_INGEST_CONTRACT.md`.

## Global Constraints

- Preserve all pre-existing user worktree changes; do not reset, restore, overwrite or absorb unrelated changes.
- No production/staging/V2 mutation, semantic backfill, identity merge, real publication or fixture-driven production exception.
- WordPress attachment/postmeta is infrastructure/projection only; Graph remains the sole semantic relation store and semantic writes remain governed.
- Source-original is retained; derivatives share the parent Media identity and never become semantic Media identities.
- Subject precedence is canonical UUID → stable key → exact canonical name/alias; ambiguity and unavailable dependencies fail closed.
- Run each regression RED before implementation and report the known baseline `ArticleResearchPreflightTest.php:114` failure separately.

### Task 1: Contract checkpoint

**Files:**
- Modify: `docs/constitution/NHK_V3_CONSTITUTION.md`
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- Modify: `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`

- [x] Record Owner decisions: governed multipart flow, source retention, role precedence, explicit UUID resolution and no Post-ID special case.
- [ ] Run `git diff --check` and review the changed contract text for contradictions.

### Task 2: Multipart governed Media identity and source retention

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentIngestor.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaIngestGateway.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaMultipartGovernedIngestTest.php`

**Interfaces:** Multipart input produces a packet containing source-original and derivative asset specs; `MediaIngestGateway::ingest()` returns the canonical `Media`; the bridge maps the resulting Media/asset to one attachment idempotently.

- [ ] Write failing tests proving a real uploaded file creates/resolves one Media identity, repeated adoption does not duplicate identity/mapping, and source-original remains present beside WebP derivative.
- [ ] Run only the new test and record the expected RED failure caused by the current WordPress-only adapter.
- [ ] Implement the smallest adapter/gateway change that preserves the source file, creates derivatives when supported, and routes both first ingest and adoption through the governed Media boundary.
- [ ] Run the focused test GREEN, then the existing Media persistence/contract tests.

### Task 3: Representative/evidence precedence and public projection

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Media/MediaUsageRoleRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityPageQuery.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpReadHandler.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationPrecedenceTest.php`

- [ ] Write failing tests for representative-vs-evidence selection, deterministic ties, entity representative retrieval and evidence retrieval without representative replacement.
- [ ] Run the focused test RED.
- [ ] Add only registry-backed role intent and deterministic selection/projection behavior; do not add a new predicate or semantic writer.
- [ ] Run focused Media/Article/frontend projection tests GREEN.

### Task 4: Deterministic Article subject resolver and generic preflight

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpSemanticContextResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Article/ArticleIngestPreflight.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpSemanticContextResolverTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleIngestPreflightTest.php`

- [ ] Add RED tests for `canonical_uuid`, stable-key fallback, ambiguous alias fail-closed, generic Post 89 acceptance and the Variant UUID `95873bfe-d978-4eda-a5a2-ce9ba79625df` as an ordinary fixture.
- [ ] Run the new tests RED and verify failure is resolver/preflight behavior, not test setup.
- [ ] Implement UUID field normalization and remove the Post 55 literal while preserving endpoint/contract validation.
- [ ] Run focused resolver/preflight tests GREEN and confirm no production code contains a Post-ID special case.

### Task 5: Governed E2E and verification checkpoint

**Files:**
- Modify only if required by verified behavior: `public/wp-content/plugins/nhk-core/tests/Integration/*Media*`, `*Article*`, or existing MCP integration tests.
- Update after evidence: `docs/architecture/V3_EXECUTION_STATE.md`.

- [ ] Run focused unit tests, PHP lint on every changed PHP file, `git diff --check`, composer validation and secret review.
- [ ] Run guarded integration against exact `nhk_v3_test` only if WordPress/MySQL bootstrap is available; use isolated temporary fixture data and read-back cleanup authorized by the integration guard.
- [ ] Probe the complete sequence read-only where mutation credentials/runtime are unavailable; do not publish Post 89 or any real content.
- [ ] Record exact pass/fail/environment-blocked evidence, migration impact and post-deploy runtime probes.
