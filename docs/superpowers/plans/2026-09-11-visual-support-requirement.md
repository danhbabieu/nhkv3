# Visual Support Requirement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an executable, application-level VisualSupportRequirement ledger that records exact semantic visual needs, reverse-reconciles later canonical Media, and projects only eligible contextual usages.

**Architecture:** Keep VisualSupportRequirement outside Authority, Knowledge Claim, Source/Evidence, Graph and Media identity. Store the requirement in an additive indexed ledger; keep MediaUsage as the contextual binding; reuse ProjectionDependencyIndex for affected consumer invalidation. Capture remains the only normal intake boundary, and Media ingest invokes bounded reverse reconciliation after canonical Media read-back.

**Tech Stack:** PHP 8.2+, WordPress/dbDelta, PHPUnit 11, Composer, existing NHK V3 repositories/services/registries and canonical documentation manifest generator.

**Spec:** `docs/superpowers/specs/2026-09-11-visual-support-requirement-design.md`

## Global Constraints

- VisualSupportRequirement is an application-level persistent requirement ledger, not an Authority entity, Knowledge Claim, Source/Evidence, Graph edge or Media identity.
- MediaUsage owns contextual usage/binding; one canonical Media may serve many exact requirements and consumers without duplication.
- Capture is the normal Media intake boundary; no direct MCP VisualSupportRequirement writer is added.
- Reverse reconciliation is indexed and bounded; it never scans the whole database or uses filename/keyword/brand/gallery/checksum similarity as proof.
- Visual support never promotes a Media observation to Claim/Evidence and never broadens Specimen scope to Variant/Model/Brand.
- Semantic `RESOLVED` is independent of public eligibility; private, review, placeholder and ineligible Media are fail-closed in public projections.
- Persistence is additive UP-only with deterministic uniqueness/idempotency; no legacy backfill and no production/staging/V2 mutation.
- Existing dirty user changes, including the untracked root-repair plan and current Governance queue files, must be preserved and excluded from unrelated edits.
- Every checkpoint reads and updates `docs/architecture/V3_EXECUTION_STATE.md`; no live/deployed claim is made without target MCP read-back.

---

### Task 1: Add the canonical contract, registries and domain ledger model

**Files:**
- Create: `docs/architecture/VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Media/VisualSupportIntentRegistry.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Media/VisualSupportRequirementStateRegistry.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/Media/VisualSupportRequirement.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Media/VisualSupportRequirementRepository.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/VisualSupportRequirementService.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportRequirementTest.php`

**Interfaces:**
- Consumes `KnowledgeFacetProfile`, `MediaDetailTypeRegistry`, `MediaUsageRoleRegistry`, `Media`, `MediaAsset`, `MediaUsage`, `ProjectionDependencyIndex` vocabulary and UUID/revision conventions.
- Produces `VisualSupportRequirement::identityFingerprint()`, `VisualSupportRequirement::idempotencyFingerprint()`, immutable state validation, registry validation, and a service boundary for create-or-reconcile without a public writer.

- [ ] **Step 1: Write RED domain tests.** Cover registry values and rejection of unknown values; exact semantic identity excluding consumer; MISSING/RESOLVED/REVIEW_REQUIRED state; no evidence/claim/graph owner fields; and fixture-generic examples for a feature detail.
- [ ] **Step 2: Run the focused test to verify it fails.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportRequirementTest.php`; expected failure is missing classes or methods.
- [ ] **Step 3: Implement the smallest domain objects and application service.** Validate subject UUID/type, allowed Knowledge facet/scope, registered Media detail, visual intent/state, non-empty fingerprints, nullable Media binding and revision. Compute fingerprints from subject type/id/scope/facet/feature/intent only; retain consumer/context in provenance, not identity.
- [ ] **Step 4: Write the active contract.** Define creation order, exact matching, state/public split, MediaUsage/Evidence separation, reverse sequence, bounded query requirement, reuse/idempotency, Article/Knowledge/Video/Media/projection consumers and admin read-model fields.
- [ ] **Step 5: Run the focused tests to verify they pass.** Expected: all domain assertions pass and no direct MCP writer is introduced.

### Task 2: Add additive persistence and bounded repository lookup

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/VisualSupportRequirementMigration019.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WpdbVisualSupportRequirementRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Contracts/Media/VisualSupportRequirementRepository.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Shared/Migration/MigrationStatus.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportRequirementMigration019Test.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/WpdbVisualSupportRequirementRepositoryTest.php`

**Interfaces:**
- Consumes the immutable domain object from Task 1 and current migration/UUID/WPDB patterns.
- Produces `save()`, `findBySemanticFingerprint()`, `findByIdempotencyFingerprint()`, `findCandidatesForMedia(array $context, int $limit)`, `findById()`, and `listForAdmin(array $filters, int $limit)` with indexed, bounded queries.

- [ ] **Step 1: Write RED migration/repository contract tests.** Assert migration version 19, exact table name, schema readiness, additive UP-only SQL with unique semantic/idempotency keys and indexes, and repository method signatures/round-trip semantics.
- [ ] **Step 2: Run the focused tests to verify they fail.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportRequirementMigration019Test.php public/wp-content/plugins/nhk-core/tests/Unit/WpdbVisualSupportRequirementRepositoryTest.php`.
- [ ] **Step 3: Implement migration 019 and repository.** Use `MigrationDatabaseGuard::assertUpAllowed`; create `{$wpdb->prefix}nhk_visual_support_requirements` with binary UUID, subject/scope/facet/feature/intent columns, state, media UUID/revision, context/provenance/reason JSON/text, fingerprints, revision/timestamps, unique keys and lookup indexes. Hydrate malformed rows as unavailable rather than success.
- [ ] **Step 4: Wire migration target and pending runner.** Update boot/activation/pending migration target to 19 and schema readiness without changing existing migrations or adding destructive operations. Keep migration execution opt-in as currently enforced.
- [ ] **Step 5: Run focused migration/repository tests and PHP lint.** Expected: schema guards, idempotent lookup and malformed-row behavior pass; `php -l` passes for new PHP files.

### Task 3: Implement exact suitability and reverse reconciliation after Media read-back

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/VisualSupportMediaSuitability.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/VisualSupportReverseReconciliationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaIngestGateway.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaService.php` only if an existing canonical ingest read-back hook is required; preserve its public signature.
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportReverseReconciliationTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportPublicProjectionTest.php`

**Interfaces:**
- Consumes canonical Media, MediaAsset, MediaUsage repository/service, VisualSupportRequirementRepository, ProjectionDependencyIndex, suitability registries and existing projection invalidation service where available.
- Produces `VisualSupportReverseReconciliationService::reconcile(Media $media, array $context = [], int $limit = 100): array` with per-requirement outcome/reason, idempotent binding, candidate ranking, affected consumers and final read-back status.

- [ ] **Step 1: Write RED reconciliation tests.** Cover MISSING creation/read-back, exact feature resolution, wrong Variant rejection, same Model without feature rejection, later Media reverse match, three consumers/one Media identity, replay idempotency, better candidate replacement with retained history, PRIVATE internal resolution/public omission, no Claim/Evidence/Graph creation, specimen non-broadening and honest missing status.
- [ ] **Step 2: Run the focused test to verify it fails.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportReverseReconciliationTest.php public/wp-content/plugins/nhk-core/tests/Unit/VisualSupportPublicProjectionTest.php`.
- [ ] **Step 3: Implement exact suitability.** Require canonical subject/scope/facet/feature/detail and intent match, valid active Media/readiness/assets/context; reject broad or inferred scopes; score only candidates that pass exact gates; ambiguous candidates become REVIEW_REQUIRED; missing candidates remain MISSING with typed reason.
- [ ] **Step 4: Implement reverse orchestration.** After Media ingest/read-back, query only indexed MISSING/REVIEW_REQUIRED candidates with a fixed limit; validate each; save one binding with optimistic revision and old binding history in provenance/context; call existing MediaService `addUsage()` for the contextual usage; add/update projection dependency rows; invalidate/rebuild affected consumers; read back ledger/usage/projection. Replays must return existing identities.
- [ ] **Step 5: Wire Capture/Media runtime boundary.** Inject the reconciler optionally into `MediaIngestGateway` and runtime factory/boot paths so normal Capture Media ingest triggers it after canonical Media read-back. Do not expose a new MCP operation or low-level writer.
- [ ] **Step 6: Implement public projection guard.** Resolve only public-safe derivative/assets for a RESOLVED requirement; return no image for MISSING, REVIEW_REQUIRED, private, placeholder, unavailable or ineligible Media. Internal diagnostics may retain resolved Media and affected consumers.
- [ ] **Step 7: Run focused tests and lint.** Expected: all 13 behavioral cases pass, replay is idempotent, public output fails closed and no semantic owner is created.

### Task 4: Update all ACTIVE documentation and reconcile conflicting old wording

**Files:**
- Modify: `docs/constitution/NHK_V3_CONSTITUTION.md`
- Modify: `docs/constitution/READ_FIRST.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/architecture/04_MEDIA_MODEL.md`
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`
- Modify: `docs/architecture/11_GRAPH_CORE_CONTRACT.md`
- Modify: `docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md`
- Modify: `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- Modify: `docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md`
- Modify: active Article SEO/media projection contract(s) discovered in the status index
- Modify: `docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md`
- Modify: `docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md`
- Modify: `docs/architecture/MCP_V3_VIDEO_WORKFLOW.md`
- Modify: active Video SEO/YouTube/Hub contracts where thumbnail wording is relevant
- Modify: `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: active admin/media/SEO contracts where representative wording is relevant
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php`

**Interfaces:**
- Consumes the Task 1 canonical contract and the current Constitution/ACTIVE contract map.
- Produces one consistent normative law: node representative coverage is separate from semantic feature technical/contextual support; missing visual persists honestly; Media ingest reverse-reconciles; visual support is not Evidence/Claim; public projections fail closed; dependency fingerprints include binding/revision where relevant.

- [ ] **Step 1: Write RED documentation assertions.** Add registry/get assertions for `visual-support-requirement`, active status-index/router references, post-ingest reverse sequence, evidence separation and stale/tampered fail-closed language.
- [ ] **Step 2: Run the focused docs test to verify it fails.** Run `vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php`.
- [ ] **Step 3: Update source contracts.** Add a dated Constitution amendment with owner/boundary/state/reconciliation/public/evidence rules; update media, knowledge, governance, graph, Article, Video, public dossier, SEO, MCP and admin wording without hard-coding any model/brand example.
- [ ] **Step 4: Update router/status/execution state.** Make the new contract ACTIVE and required for Media/Knowledge/Article/Video tasks; record WHAT/WHY/FILES/TESTS/code status/runtime/deployment/remaining gap in the newest execution checkpoint.
- [ ] **Step 5: Add docs registry allowlist entry and assertions.** Include the new source document in `McpDocumentationRegistry::DOCUMENTS`; do not edit ignored generated files by hand.
- [ ] **Step 6: Run docs tests and source consistency checks.** Expected: source docs expose one law and no contradictory “missing image equals representative” interpretation remains.

### Task 5: Generate documentation projection and complete repository verification

**Files:**
- Modify: generated ignored snapshot under `public/wp-content/plugins/nhk-core/resources/canonical-docs/` only through the generator
- Modify: only implementation/docs/test files from Tasks 1–4

**Interfaces:**
- Consumes Composer script `generate:mcp-docs`, `McpDocumentationRegistry`, source docs and all new tests.
- Produces a deterministic manifest with changed `documentation_version` and `manifest_hash`, documentation bootstrap/list/get evidence, and fail-closed stale/tamper verification.

- [ ] **Step 1: Capture old local manifest identity.** Read the existing generated `manifest.json` if present; if absent, record that local pre-change snapshot was unavailable and use the last recorded execution-state identity only as historical context.
- [ ] **Step 2: Run the canonical generator.** Run `composer generate:mcp-docs`; never hand-edit `resources/canonical-docs`.
- [ ] **Step 3: Run documentation projection tests.** Run focused registry tests and the MCP transport/documentation integration test when WordPress infrastructure is available; prove bootstrap version/hash, list ACTIVE contract, get law content, source hash match, stale checkpoint fail-closed and tamper fail-closed.
- [ ] **Step 4: Run implementation and regression verification.** Run focused Visual Support tests, relevant Media/Capture/Knowledge/Video/Projection/MCP tests, then `composer test`; run `composer lint`, `git diff --check`, and a targeted secret review excluding known documentation prose/fixtures.
- [ ] **Step 5: Read back current status and update execution state.** Record exact test outcomes, local runtime availability, live/deployed status and remaining target MCP acceptance gap.

### Task 6: Commit and push only the scoped change, then report live acceptance honestly

**Files:**
- No new files; stage only the Visual Support spec/plan, source docs, implementation and tests from this task.

**Interfaces:**
- Consumes repository verification evidence and exact deployment procedure discovered in `scripts/nhk-demo-cutover`, `docs/superpowers/specs/2026-09-03-demo-cutover-infrastructure-design.md`, `tools/deployment-preflight.php` and Composer scripts.
- Produces a logical commit, push status, and exact human-run target commands if no authorized deployment configuration is present.

- [ ] **Step 1: Re-read status and inspect the scoped diff.** Confirm pre-existing dirty Governance queue changes remain untouched and do not stage them.
- [ ] **Step 2: Run final verification before claiming completion.** Re-run `composer generate:mcp-docs`, focused docs/Visual Support tests, `composer lint`, `git diff --check`, secret review and `git status --short --branch`.
- [ ] **Step 3: Commit only the scoped changes.** Use a clear message such as `feat: add visual support requirement ledger`; do not include unrelated dirty files.
- [ ] **Step 4: Push the current branch if the repository policy and credentials permit.** If push is blocked, report the exact error; do not claim remote publication.
- [ ] **Step 5: Deploy only through the repository’s configured human-controlled DEMO procedure if `NHK_DEMO_DEPLOY_CONFIG` is explicitly available and preflight permits.** Otherwise report `IMPLEMENTED_CODE_SIDE / LIVE_ACCEPTANCE_PENDING` and provide the exact real command from the script, with no guessed credentials/target.
- [ ] **Step 6: If deployed, verify target MCP documentation-bootstrap and documentation-get.** Require new documentation version/hash and the Visual Support law in returned content before writing `LIVE_VERIFIED`; otherwise keep `LIVE_ACCEPTANCE_PENDING`.

## Self-review checklist

- [ ] Every spec requirement has a task: owner boundary (Tasks 1–2), exact state and idempotency (Tasks 1–3), reverse backfill and Media reuse (Task 3), Article/Knowledge/Video/public/admin contracts (Task 4), docs projection/fail-closed (Task 5), deployment/live status (Task 6).
- [ ] No task depends on an undefined class or method; Task 1 defines the requirement identity and Task 2 defines repository methods consumed by Task 3.
- [ ] Every plan step is concrete and executable; no deferred placeholder instruction is present.
- [ ] The design distinguishes representative image coverage from feature-level technical/contextual visual support.
- [ ] No example model, brand, component or logo is embedded in architecture or code contracts.
- [ ] No task authorizes direct writers, evidence promotion, global scans, duplicate Media, production mutation or generated snapshot hand edits.
