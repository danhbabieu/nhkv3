# NHK V3 Media Ingest & Image SEO Implementation Plan

> REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` or
> `superpowers:executing-plans` when those execution skills are available.

**Goal:** enforce one canonical Media intake boundary, two distinct mandatory
Article image usages, placeholder/incomplete semantics and contextual Image SEO
without migrating or repairing legacy data.

**Architecture:** WordPress owns editorial state. `MediaIngestGateway` is the
shared intake adapter over existing Governance → `AuthorityProposalExecutor`
→ `MediaService` → Media/Asset/Usage repositories. Article coordination owns
slot reconciliation and Blueprint projection. No Media usage creates Graph or
Evidence truth.

**Tech Stack:** PHP 8.5-compatible code, Symfony UID, WordPress dbDelta,
MySQL-compatible repositories, PHPUnit 11 and existing NHK Core REST/MCP.

**Spec:** `docs/superpowers/specs/2026-09-02-media-ingest-image-seo-design.md`

**Global constraints:** preserve concurrent work; no V2/production/staging
mutation; no legacy article-body import; no Graph backfill; no semantic repair;
no new Authority type, Graph endpoint, predicate or Article entity; all public
failure states remain distinct; run fresh verification after changes.

## Files discovered and interfaces consumed

- `Domain/Media/Media.php`, `MediaAsset.php`, `MediaUsage.php`.
- `Application/Media/MediaService.php`, `PublicMediaAssetDelivery.php` and
  `MediaVideoPageQuery.php`.
- `Contracts/Media/MediaRepository.php`, `MediaAssetRepository.php` and
  `MediaUsageRepository.php`.
- `Application/Governance/AuthorityProposalExecutor.php` and the governed MCP
  catalog/transport.
- `Application/Article/ArticleIngestCoordinator.php`, the editorial reader and
  Article receipt repository.
- `Infrastructure/Migration/MediaMigration004.php`,
  `ArticleIngestMigration010.php` and Plugin bootstrap.

Produced interfaces are `ArticleMediaBlueprintRepository`,
`MutableMediaUsageRepository`, `MediaIngestGateway`, `ArticleMediaCoordinator`,
`ArticleMediaSeoProjection`, the controlled registries and the persisted
Blueprint schema. Existing Media/Graph/Governance interfaces remain the
canonical owners.

## Task 1 — Constitutional checkpoint and contract documents

Failing test first: run `vendor/bin/phpunit --configuration phpunit.xml.dist
public/wp-content/plugins/nhk-core/tests/Unit/P0ConstitutionIntegrityTest.php`
and record the pre-change baseline. Expected failure for new law coverage is
that the sole Constitution has no Article Media law or invariant entries.

Minimal implementation: amend only
`docs/constitution/NHK_V3_CONSTITUTION.md`; add the non-normative spec and this
plan; update the MCP implementation guidance to point to the Constitution and
registries. Passing check: Constitution integrity test, a repository scan for
unresolved planning markers in the new documents returns no matches, and
`git diff --check`. Commit boundary: `docs: define NHK media
ingest and image SEO law`.

## Task 2 — Registries and MediaUsage contextual metadata

Failing test first: run the new focused test file with the registry assertions;
expected failure is missing mandatory role/detail/state/diagnostic/keyword
registries and contextual usage fields.

Modify `Domain/Media/MediaUsage.php`, `Application/Media/MediaService.php`,
`Infrastructure/Media/WpdbMediaUsageRepository.php` and
`Infrastructure/Migration/MediaMigration004.php`; create the six registry/value
files under `Domain/Media` and `MediaFilenameNormalizer.php`. Consume existing
Media repositories; produce controlled role validation, alt/caption/keyword
usage fields and camera filename normalization. Pass with
`vendor/bin/phpunit --configuration phpunit.xml.dist
public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php
public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoCoreTest.php`. Commit
boundary: `feat: add NHK media registries and contextual usage SEO`.

## Task 3 — Blueprint persistence and Article coordinator

Failing test first: run `ArticleMediaPolicyTest.php`; expected failure is no
coordinator, no distinct placeholders, no Blueprint repository and no
idempotent mandatory usages.

Create `Domain/Media/MediaSeoBlueprint.php`, `MediaIngestBatch.php`,
`Application/Media/ArticleMediaResult.php`, `ArticleMediaCoordinator.php`,
`ArticleMediaSeoProjection.php`, contracts for Blueprint/mutable usage,
`Infrastructure/Media/WpdbArticleMediaBlueprintRepository.php` and
`Infrastructure/Migration/ArticleMediaMigration011.php`. Update Article receipt
diagnostics and migration compatibility. The coordinator consumes MediaService,
Media/Asset/Usage repositories and produces two mandatory roles, placeholders,
state/diagnostics, Blueprints and contextual usage metadata. Passing test:
`vendor/bin/phpunit --configuration phpunit.xml.dist
public/wp-content/plugins/nhk-core/tests/Unit/ArticleMediaPolicyTest.php`. Commit
boundary: `feat: enforce NHK article media slots and SEO blueprints`.

## Task 4 — Canonical intake and channel wiring

Failing test first: run the governed Media persistence and MCP contract tests;
expected failure is the absence of Gateway delegation, new role schema values,
Article media context and persisted diagnostics.

Modify `AuthorityProposalExecutor.php`, `McpToolCatalog.php`,
`McpArticleIngestHandler.php`, `ArticleIngestCoordinator.php`, `Plugin.php`,
`McpReadHandler.php`, `MediaVideoPageQuery.php` and `ReadApi.php`. Create
`MediaIngestGateway.php` and `MediaBatchIngestService.php`. Wire the WordPress
post hook after native creation, use the Gateway in governed Media Apply, add
MCP diagnostics/context validation and keep WordPress Abilities read-only.
Passing commands:
`vendor/bin/phpunit --configuration phpunit.xml.dist
public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php
public/wp-content/plugins/nhk-core/tests/Unit/P6PersistenceTest.php` and
`php -l` on every changed PHP file. Commit boundary: `feat: converge NHK
media intake channels`.

## Task 5 — Documentation, audit and verification checkpoint

Failing test first: run full PHPUnit and `composer lint`; expected failures are
any stale exact MCP role assertions, migration receipt-schema mismatches or
runtime syntax issues. Update `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`,
`docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`,
`docs/architecture/V3_CONSTITUTION_COMPLIANCE_AUDIT_2026-09-02.md` and
`docs/architecture/V3_EXECUTION_STATE.md` with evidence, remaining Product /
Specimen relation gap and explicit no-repair status. Produce a read-only audit
report only if the WordPress runtime is available; inspect Post 55 without
mutation. Passing commands: `composer validate --no-check-publish`,
`composer lint`, focused PHPUnit, full PHPUnit, `git diff --check`, migration
checks, preflight and available MCP/HTTP smoke checks. Commit boundary:
`docs: record NHK media ingest compliance checkpoint`.
