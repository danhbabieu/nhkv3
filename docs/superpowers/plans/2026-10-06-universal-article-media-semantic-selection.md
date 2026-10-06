# Universal Article Media Semantic Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Article automatic media selection universally fail closed on missing or invalid canonical subject scope, rank only semantically eligible media, preserve explicit/pinned placements, and revalidate the result across persistence, publication, SEO, frontend, and legacy audit paths.

**Architecture:** Strengthen `SemanticSuitabilityPolicy` and place one reusable `ArticleMediaCandidateSelector` between Article coordination and the Media repositories. `ArticleMediaCoordinator` will consume canonical persisted subject context, use the selector for automatic candidates, and verify the governed Usage through canonical readback. Article SEO and public dossier projections will revalidate `SYSTEM_AUTO` Article usages while leaving `USER_EXPLICIT`/`PINNED` usage governed by its explicit-placement contract. A separate read-only, cursor-bounded legacy audit will produce Governance-ready repair plans without applying them.

**Tech Stack:** PHP 8.5, WordPress plugin runtime, PHPUnit 11, existing Media/Usage repositories, existing CAS-aware `MediaService`, existing structural subject context and Governance boundaries.

**Spec:** `docs/superpowers/specs/2026-10-06-universal-article-media-semantic-selection-design.md`

## Global Constraints

- WordPress native `wp_posts` remains the sole source of truth for editorial title, body, author, dates, categories, archives, homepage, search, RSS, sitemap and editorial URLs.
- Media availability and semantic suitability remain separate; a ready/public asset without persisted subject proof is not automatic semantic coverage.
- Title, body, dictionary labels, research topics, filenames, lexical similarity and image recognition cannot create or replace canonical semantic scope.
- `USER_EXPLICIT`/`PINNED` precedence is preserved; automatic reconciliation cannot replace an active pinned usage.
- Automatic Article selection returns no candidate when no hard-eligible candidate exists; it never falls back to a global or visually strongest image.
- Use existing MediaUsage CAS, optimistic revision, idempotency, placeholder and diagnostic boundaries; do not introduce a parallel status enum.
- No schema migration, production/V2/staging mutation, direct SQL write, WordPress featured-image write, hard delete, or live legacy repair is in scope.
- Any future apply of a legacy repair plan must use the existing Governance lifecycle and canonical readback.
- Synthetic neutral fixtures are required for regression tests; reference articles are read-only acceptance evidence only.
- Before each checkpoint commit, read `docs/architecture/V3_EXECUTION_STATE.md`; after the checkpoint, update it with factual evidence.

## Review Focus

- Missing persisted subject scope on a ready image must produce no automatic selection, even when the image is visually ideal. Test in Task 1 and Task 2.
- A same-brand sibling, broad ancestor, or unrelated image must not outrank or replace an exact subject candidate. Test semantic tier ordering and hard rejection in Task 1.
- An active `USER_EXPLICIT`/`PINNED` Article usage must remain stable while automatic reconciliation runs. Test in Task 2 and Task 3.
- A stale `SYSTEM_AUTO` usage must disappear from Article SEO, dossier, and frontend projections when its subject binding no longer matches. Test in Task 3.
- A large repository and reversed insertion order must not starve an exact candidate or change the deterministic winner. Test in Task 1.

---

### Task 1: Make semantic eligibility and candidate ranking universal

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCandidateSelector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/SemanticSuitabilityPolicy.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Domain/Media/MediaDiagnosticCodeRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticSuitabilityAndProjectionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`

**Interfaces:**
- `ArticleMediaCandidateSelector::__construct(MediaRepository $media, MediaAssetRepository $assets, MediaUsageRepository $usages, SemanticSuitabilityPolicy $suitability)`.
- `ArticleMediaCandidateSelector::select(MediaSeoBlueprint $blueprint, array $usedMediaIds = [], bool $allowReuseOfUsed = false): array` returns `media: ?Media`, `candidates: list<array<string,mixed>>`, and `diagnostics: list<array<string,mixed>>`.
- `SemanticSuitabilityPolicy::evaluate()` keeps its existing array contract and adds deterministic `relationship_class`, `relation_path`, `semantic_tier`, `eligible`, and `score_components` fields without removing existing fields.

- [ ] **Step 1: Write failing policy tests**

  Add tests named `test_unscoped_ready_media_is_not_automatic_article_coverage`, `test_visually_superior_unrelated_media_is_hard_rejected`, `test_registered_structural_compatibility_is_distinguished_from_broad_ancestor_scope`, and `test_policy_exposes_deterministic_semantic_tier_and_rejection_reason`. Assert that unscoped and mismatched candidates are not `auto_select` or `valid_for_completeness`, while exact and explicitly registered compatible candidates are eligible with explainable tier data.

- [ ] **Step 2: Run the policy tests and verify they fail for the current contract**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'SemanticSuitabilityAndProjectionTest|ArticleMediaPolicyTest' --testdox`

  Expected: failures showing the current `unscoped_article_media` compatibility and missing semantic-tier output.

- [ ] **Step 3: Implement the strengthened policy and selector**

  In `SemanticSuitabilityPolicy`, remove unscoped automatic eligibility for Article roles, keep explicit current-Capture/editorial placement behavior distinct from semantic representative reuse, and derive semantic tier only from persisted candidate scope plus validated target structural context. Add the bounded no-candidate diagnostic `NO_SEMANTICALLY_ELIGIBLE_MEDIA` to `MediaDiagnosticCodeRegistry`.

  In `ArticleMediaCandidateSelector`, enumerate the complete `MediaRepository::list()` result, discard inactive/not-ready/placeholders/no-public-asset candidates, build persisted scope evidence from Media provenance and governed variant/authority-variant usages, call the policy before scoring, and score only eligible candidates. Sort by semantic tier/relationship specificity first, then registered role/provenance, readiness/public asset suitability, dimensions/detail preference, reuse penalty, and stable key. Return a deterministic no-selection result rather than a fallback when the eligible set is empty.

- [ ] **Step 4: Add selector regression tests**

  Add tests named `test_selector_ranks_exact_subject_before_larger_unrelated_asset`, `test_selector_rejects_same_brand_sibling_and_broad_ancestor_without_registered_rule`, `test_selector_is_independent_of_repository_insertion_order`, `test_selector_scans_past_large_ineligible_prefix`, and `test_selector_returns_no_media_when_all_candidates_are_ineligible`. Assert winner identity, rejection diagnostics, and no random/global fallback.

- [ ] **Step 5: Run the focused tests and verify they pass**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'SemanticSuitabilityAndProjectionTest|ArticleMediaPolicyTest' --testdox`

  Expected: PASS with the new synthetic matrix and all existing focused behavior that remains constitution-compliant.

- [ ] **Step 6: Commit the policy boundary**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCandidateSelector.php public/wp-content/plugins/nhk-core/src/Application/Media/SemanticSuitabilityPolicy.php public/wp-content/plugins/nhk-core/src/Domain/Media/MediaDiagnosticCodeRegistry.php public/wp-content/plugins/nhk-core/tests/Unit/SemanticSuitabilityAndProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php
  git commit -m "fix: enforce semantic eligibility before article media ranking"
  ```

### Task 2: Bind automatic Article coordination to canonical subject context

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php`

**Interfaces:**
- Keep `ensureForPost(int $postId, array $context = [], array $selectedMediaBySlot = [], array $supportingMediaIds = []): ArticleMediaResult` and `diagnoseForPost(int $postId, array $context = []): ArticleMediaResult` stable.
- Extend the optional `ArticleMediaCoordinator` dependency list with a final `?SubjectStructuralContextReader` only if needed; existing test constructors must remain valid through defaults.
- The coordinator passes the normalized persisted subject IDs, subject type/revision, structural ancestors and relation path to `ArticleMediaCandidateSelector`; it never derives automatic scope from title-only context.

- [ ] **Step 1: Write failing coordinator and wiring tests**

  Add `test_title_only_reconciliation_does_not_reuse_global_ready_media`, `test_missing_capture_subject_binding_fails_closed_to_placeholder`, `test_existing_pinned_usage_is_not_replaced_by_automatic_selection`, `test_subject_revision_change_invalidates_system_auto_usage`, and `test_wordpress_reconciliation_hook_passes_persisted_subject_context_or_disables_unscoped_reuse`. Update the prior stable-key tie test to supply an exact synthetic subject binding; title-only input must now yield a placeholder/no-selection result.

- [ ] **Step 2: Run the coordinator tests and verify they fail**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ArticleMediaPolicyTest|PluginBootWiringTest' --testdox`

  Expected: failures showing title-only global reuse and the current title-only WordPress hook.

- [ ] **Step 3: Implement canonical subject binding and selector use**

  Change automatic historical reuse defaults to require a locked persisted subject scope. Keep current-Capture explicit media and explicitly selected Article illustration behavior on their existing explicit contract. Treat an active existing `USER_EXPLICIT`/`PINNED` usage as protected from automatic replacement, while still reporting unavailable assets or semantic conflicts for completeness.

  Replace the coordinator’s private global scoring loop with `ArticleMediaCandidateSelector::select()`. Preserve distinct mandatory-slot behavior, placeholder creation, Usage reconciliation, diagnostic shape, and idempotency. The selector result and the subsequent suitability assessment must use the same target subject packet.

  In `Plugin.php`, wire the existing Capture subject binding recovery into the post reconciliation path. For a post with a persisted packet, pass exact primary ID/type/revision and capture-owned media IDs; when the packet is unavailable, call the coordinator with unscoped automatic reuse disabled. Do not use post title/body or a dictionary label as a substitute.

- [ ] **Step 4: Run the coordinator and wiring tests and verify they pass**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ArticleMediaPolicyTest|PluginBootWiringTest' --testdox`

  Expected: PASS, including explicit/pinned stability, exact subject reuse, no unscoped fallback, and canonical hook wiring.

- [ ] **Step 5: Commit the canonical binding change**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/PluginBootWiringTest.php
  git commit -m "fix: bind article media reconciliation to canonical subjects"
  ```

### Task 3: Enforce persistence, publication, SEO, and frontend readback

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaSeoProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/FrontendSemanticBootstrap.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleSemanticDossierTest.php`

**Interfaces:**
- `ArticleMediaSeoProjection` and `EntityMediaProjection` keep their public projection methods stable; optional Blueprint/policy dependencies are additive and default-safe for non-Article endpoints.
- System-auto Article projection uses the persisted Blueprint subject context and the same `SemanticSuitabilityPolicy`; explicit pinned Article usage remains visible under its explicit-placement contract.
- `ArticleMediaCoordinator::canonicalUsageReadback()` returns the persisted Usage identity, role, revision, selection source/policy, Media identity, and post-readback suitability/conflict diagnostics.

- [ ] **Step 1: Write failing readback/projection tests**

  Add `test_usage_readback_rejects_subject_or_revision_drift`, `test_system_auto_seo_projection_fails_closed_without_subject_scope`, `test_system_auto_entity_projection_hides_wrong_subject_media`, `test_explicit_pinned_article_projection_remains_visible`, and `test_article_dossier_and_frontend_gallery_share_fail_closed_projection`. Add a publication-gate regression asserting that an Image Article with a semantically unsafe featured snapshot remains blocked/incomplete. Assert that stale auto media is not eligible or rendered, while explicit pinned media is not silently hidden.

- [ ] **Step 2: Run the projection tests and verify they fail**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ArticleMediaPolicyTest|MediaPresentationProjectionTest|ArticleSemanticDossierTest' --testdox`

  Expected: failures showing raw active Usage projection and SEO acceptance without subject IDs.

- [ ] **Step 3: Implement one readback/revalidation law**

  After governed Usage persistence, re-read the active Usage and confirm endpoint, role, placement key, revision progression, Media identity, and current policy eligibility against the same subject context. Convert any drift or conflict into the existing incomplete/review diagnostics and never claim a successful automatic selection.

  In `ArticleMediaSeoProjection`, revalidate `SYSTEM_AUTO` featured usage even when a Blueprint is absent; absence of canonical scope is incomplete. In `EntityMediaProjection`, apply the same rule only for `wp_post` Article roles and system-selected usages, while retaining the current explicit projection behavior for `USER_EXPLICIT`/`PINNED` and non-Article owners. Wire the Article Blueprint repository and shared policy through the public/frontend composition roots.

- [ ] **Step 4: Run publication/readback and frontend tests**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ArticleMediaPolicyTest|MediaPresentationProjectionTest|ArticleSemanticDossierTest|SemanticSuitabilityAndProjectionTest' --testdox`

  Expected: PASS with stale auto usages blocked from completeness, SEO, dossier, and gallery output; explicit pinned placements preserved.

- [ ] **Step 5: Commit the readback boundaries**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaCoordinator.php public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaSeoProjection.php public/wp-content/plugins/nhk-core/src/Application/Entity/EntityMediaProjection.php public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/FrontendSemanticBootstrap.php public/wp-content/plugins/nhk-core/src/Infrastructure/Frontend/EntityDossierBootstrap.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php public/wp-content/plugins/nhk-core/tests/Unit/MediaPresentationProjectionTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleSemanticDossierTest.php public/wp-content/plugins/nhk-core/tests/Unit/ArticlePublicationGateTest.php
  git commit -m "fix: revalidate article media across public readback"
  ```

### Task 4: Add a dry-run, cursor-bounded legacy audit planner

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Media/ArticleMediaUsageInventory.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WpdbArticleMediaUsageInventory.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaLegacyAudit.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaLegacyAuditTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`

**Interfaces:**
- `ArticleMediaUsageInventory::page(string $endpointType, ?string $afterUsageId, int $limit): array` returns `items: list<MediaUsage>` and `next_cursor: ?string` in stable Usage-ID order.
- `ArticleMediaLegacyAudit::__construct(ArticleMediaUsageInventory $inventory, MediaRepository $media, MediaAssetRepository $assets, MediaUsageRepository $usages, ArticleMediaBlueprintRepository $blueprints, ArticleMediaCandidateSelector $selector, SemanticSuitabilityPolicy $policy)`.
- `ArticleMediaLegacyAudit::audit(string $cursor = '', int $limit = 100): array` returns `status: DRY_RUN`, `next_cursor`, and bounded finding/repair-plan items with Usage, Media, Article endpoint, reason, fingerprint, expected revisions, and optional proven replacement ID.

- [ ] **Step 1: Write failing audit tests**

  Add `test_audit_reports_missing_scope_and_invalid_auto_usage_without_mutation`, `test_audit_protects_explicit_pinned_usage`, `test_audit_emits_replacement_only_when_selector_proves_one`, `test_audit_returns_no_safe_media_disposition`, and `test_audit_cursor_and_limit_are_deterministic`. Assert zero repository updates/creates and no direct WordPress writes.

- [ ] **Step 2: Run the audit tests and verify they fail**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter ArticleMediaLegacyAuditTest --testdox`

  Expected: class/interface failures because the inventory and audit planner do not yet exist.

- [ ] **Step 3: Implement the read-only inventory and audit planner**

  Add a read-only WordPress database inventory query ordered by stable Usage UUID/row identity, with bounded `limit` and opaque cursor. Reuse the shared selector/policy for every `SYSTEM_AUTO` Article featured/primary usage. Report `USER_EXPLICIT`/`PINNED` separately as protected, include retired/inactive/missing-scope/stale findings, hash only bounded identifying fields for fingerprints, and emit a Governance-ready action with expected Usage/Media/subject revisions only when a replacement is proven.

- [ ] **Step 4: Run audit tests and verify dry-run behavior**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter ArticleMediaLegacyAuditTest --testdox`

  Expected: PASS; the planner is resumable, deterministic, idempotent, and mutation-free.

- [ ] **Step 5: Commit the audit planner**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Contracts/Media/ArticleMediaUsageInventory.php public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WpdbArticleMediaUsageInventory.php public/wp-content/plugins/nhk-core/src/Application/Media/ArticleMediaLegacyAudit.php public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaLegacyAuditTest.php public/wp-content/plugins/nhk-core/src/Plugin.php
  git commit -m "feat: add dry-run article media legacy audit"
  ```

### Task 5: Full verification, execution-state evidence, and acceptance report

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify only if needed: focused implementation/test files from Tasks 1–4

- [ ] **Step 1: Read the execution state and parity matrix again**

  Confirm the implementation checkpoint is compatible with `docs/architecture/V3_EXECUTION_STATE.md` and `docs/architecture/V2_V3_PARITY_MATRIX.md`; do not claim parity unless the matrix proves it.

- [ ] **Step 2: Run the complete relevant verification suite**

  Run: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'ArticleMediaPolicyTest|SemanticSuitabilityAndProjectionTest|RepresentativeMediaReconcilerTest|MediaPresentationProjectionTest|ArticleSemanticDossierTest|ArticlePublicationGateTest|ArticleMediaLegacyAuditTest|PluginBootWiringTest' --testdox`

  Then run PHP lint for every changed PHP file, `git diff --check`, and a secret review over the complete diff. Expected: all selected tests pass, lint passes, diff check is clean, and no secrets or live data are present.

- [ ] **Step 3: Update execution evidence**

  Record the implementation checkpoint, test commands/results, no-schema/no-mutation boundary, and any known deprecations in `V3_EXECUTION_STATE.md`. Keep the update factual and do not describe the legacy planner as an applied repair.

- [ ] **Step 4: Perform read-only reference fixture acceptance**

  Inspect the approved reference fixtures through existing read-only repository/application paths only. Verify the universal policy outcome and record the result; do not add article/entity-specific branches and do not mutate staging or production data.

- [ ] **Step 5: Commit documentation evidence and verify final worktree**

  ```bash
  git add docs/architecture/V3_EXECUTION_STATE.md
  git commit -m "docs: record article media selection verification"
  git status --short --branch
  ```

  Expected: only intentional commits are present, unrelated pre-existing worktree changes are preserved and reported, and the final report can provide the exact `COMMIT_HASH` and `TEST_RESULTS` evidence.
