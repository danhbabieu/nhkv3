# Multipart Batch Media Upload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the canonical multipart single/batch upload transport from real file bytes through WordPress attachment read-back and governed Media adoption, with safe replay, partial failure, MCP discovery, tests and reconciled documentation.

**Architecture:** Add one transport capability, `nhk.media.upload-batch` / `nhk-v3/media-upload-batch`, whose single-file case is batch size one. Reuse the existing WordPress image adapter and governed Media boundary, adding a durable transport binding for idempotency and a per-item artifact cleanup journal; never infer or apply semantic relations from upload hints during the transport phase. After canonical Media ingest/read-back, Constitution §20.1 requires bounded reconciliation of justified relations.

**Tech Stack:** PHP 8.x, WordPress native media APIs, existing NHK Core application/domain/repository layers, PHPUnit, WordPress REST/MCP Ability bridge, additive UP-only migration where required.

**Spec:** `docs/superpowers/specs/2026-09-08-multipart-batch-media-upload-design.md`

## Global Constraints

- Multipart batch is PRIMARY; URL import is SECONDARY/IMPORT; base64 is FALLBACK/COMPATIBILITY.
- `nhk-v3/media-ingest` remains the semantic Media boundary and is not a binary uploader.
- WordPress attachments are storage/projection records; Media, MediaAsset and MediaUsage remain separate boundaries.
- Every byte upload uses native WordPress APIs and canonical attachment read-back.
- SHA-256 is calculated from actual bytes; checksum never performs unsafe global semantic deduplication.
- Same idempotency key and different payload is a deterministic conflict.
- Batch items are independently successful or failed; successful items are not rolled back because another item failed.
- Upload transport creates no Knowledge, Source, Evidence, Graph edge or final semantic role during the transport phase; it hands off to governed Media ingest and the mandatory post-ingest reconciliation.
- No production/V2/staging mutation, push, deploy, raw DB semantic write, or legacy repair.

---

### Task 1: Inventory and executable contract surface

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- Modify: plugin service wiring/registrar discovered by `rg`
- Test: existing MCP unit/integration contract tests

**Interfaces:**
- Produces the registered name, schema, capability mapping and handler dispatch for `nhk.media.upload-batch`.
- Consumes the existing `McpTransport` multipart file extraction and `WordPressMediaAttachmentIngestor` contract.

- [ ] Step 1: Write failing catalog, permission and dispatch tests asserting the new tool is present, requires upload capability, accepts `files[]` metadata and preserves old media tools.
- [ ] Step 2: Run the focused MCP tests and record the expected missing-tool failures.
- [ ] Step 3: Add the minimal catalog schema and dispatch registration without changing `nhk.media.ingest` semantics.
- [ ] Step 4: Run focused tests and confirm static catalog/validation behavior passes.
- [ ] Step 5: Commit `feat: register multipart batch media transport`.

### Task 2: Batch request normalization, limits and manifest

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBatchUploadService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/Media/MediaBatchUploadRepository.php`
- Create/modify: focused upload transport value objects or validators under `Application/Media`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBatchUploadServiceTest.php`

**Interfaces:**
- `MediaBatchUploadService::upload(string $idempotencyKey, array $batchMetadata, array $files, array $items): array` returns the ordered manifest shape from the spec.
- The service accepts normalized PHP upload arrays only and never accepts a client filesystem path, URL or data URL.

- [ ] Step 1: Add tests for one file, five ordered files, missing/duplicate client IDs, too many files, per-file and total byte limits, malformed nested files and advisory client MIME.
- [ ] Step 2: Run the focused test file and confirm failures.
- [ ] Step 3: Implement normalization, configured limits based on WordPress limits, safe metadata parsing and deterministic manifest aggregation.
- [ ] Step 4: Run the focused service tests and confirm invalid items fail independently while valid items remain ordered.
- [ ] Step 5: Commit `feat: add batch upload normalization and manifest`.

### Task 3: Durable idempotency and artifact cleanup

**Files:**
- Create: additive upload binding migration under `public/wp-content/plugins/nhk-core/src/Infrastructure/Migration/`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WpdbMediaBatchUploadRepository.php`
- Create: cleanup journal/helper under `Infrastructure/Media`
- Modify: migration registrar and repository wiring
- Test: unit repository contract tests and guarded integration test

**Interfaces:**
- Repository methods: `findByIdempotencyKey(string $key): ?array`, `create(array $binding): array`, `findItem(string $key, string $clientFileId): ?array`.
- Cleanup journal records only artifacts created by the current item attempt and deletes them in reverse order; reused artifacts are never registered for deletion.

- [ ] Step 1: Write red tests for same-key replay, same-key changed fingerprint conflict, item retry lookup, stale binding fail-closed and reverse-order cleanup.
- [ ] Step 2: Run focused tests and verify they fail before schema/repository implementation.
- [ ] Step 3: Add the UP-only control table and repository with exact-key/fingerprint checks and immediate read-back.
- [ ] Step 4: Add cleanup journal compensation and explicit uncertain-cleanup failure.
- [ ] Step 5: Run migration checks only against allowed test/development paths and run focused repository tests.
- [ ] Step 6: Commit `feat: add batch upload idempotency bindings`.

### Task 4: WordPress native attachment lifecycle and canonical read-back

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentIngestor.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php` only where required to preserve one Media boundary
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php`
- Test: focused unit tests for MIME, extension, size, traversal, derivative metadata and cleanup

**Interfaces:**
- Batch service calls one shared item method that validates bytes, computes checksum, uses native sideload/attachment APIs, runs `wp_generate_attachment_metadata`, adopts through the existing governed Media boundary, and returns a reader-safe attachment/Media manifest.

- [ ] Step 1: Add failing integration tests for real JPEG/PNG attachment metadata, generated derivatives, source-original retention, read-back fields and no orphan after injected failure.
- [ ] Step 2: Run with WordPress integration bootstrap when available; otherwise preserve explicit skips/blocker output.
- [ ] Step 3: Replace the current hand-authored empty `sizes` metadata path with native metadata generation, while retaining the existing bounded image processing policy and protected source-original behavior.
- [ ] Step 4: Add strict extension/MIME/size/path validation and cleanup for attachment, source file, derivative, mapping and semantic artifacts created by the current attempt.
- [ ] Step 5: Run integration and focused tests, PHP lint and diff checks.
- [ ] Step 6: Commit `feat: harden native WordPress batch attachment lifecycle`.

### Task 5: Wire MCP callable behavior and Ability discovery

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- Modify: service bootstrap/registrar
- Test: `public/wp-content/plugins/nhk-core/tests/Integration/McpTransportIntegrationTest.php`

**Interfaces:**
- `McpTransport::callTool('nhk.media.upload-batch', ...)` passes normalized `files[]` and metadata to `MediaBatchUploadService`.
- Ability export exposes the same input/output contract and permission callback; custom multipart remains on `/nhk/v1/mcp`.

- [ ] Step 1: Add tests for actual `tools/list`, `tools/call`, WordPress Ability registration/export, permission denial and callable handler resolution.
- [ ] Step 2: Run the tests and confirm discovery/call failures before wiring.
- [ ] Step 3: Wire the service and register the Ability without adding a semantic writer or changing legacy tool schemas.
- [ ] Step 4: Run actual discovery/export tests and old media-ingest/media-get regression tests.
- [ ] Step 5: Commit `feat: expose multipart batch upload through MCP`.

### Task 6: Media ingest integration and semantic non-inference proof

**Files:**
- Modify: current Media/MCP contract docs and integration fixtures only where needed
- Test: focused Media integration tests and `McpTransportIntegrationTest.php`

- [ ] Step 1: Add a test that consumes returned attachment IDs through attachment read-back and then the existing `nhk-v3/media-ingest` binding path.
- [ ] Step 2: Add assertions that the transport phase creates no Knowledge, Source, Evidence or Graph relation beyond the governed Media/Asset attachment mapping, and that canonical Media ingest then enters the mandatory post-ingest reconciliation without weak/speculative edges.
- [ ] Step 3: Verify replay yields one attachment/Media binding and no duplicate semantic record.
- [ ] Step 4: Run focused Media/MCP tests and record unavailable runtime distinctly from empty data.
- [ ] Step 5: Commit `test: prove batch upload media boundary integration`.

### Task 7: Documentation, execution state and final verification

**Files:**
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`
- Modify: `docs/architecture/04_MEDIA_MODEL.md`
- Modify: `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`

- [ ] Step 1: Reconcile primary/secondary/fallback upload paths, contract fields, idempotency, partial failure, security, privacy/EXIF, MCP discovery and Product/Specimen/Source/Evidence future boundaries.
- [ ] Step 2: Run the focused upload/MCP/Media tests, full Unit suite, available guarded integration suite, PHP lint, Composer validation if touched, `git diff --check` and secret review.
- [ ] Step 3: Update execution state with exact test counts and blockers; do not claim live/deployed acceptance if WordPress/Easy MCP runtime is unavailable.
- [ ] Step 4: Review changed files and status for credentials, dumps, tokens or unrelated changes.
- [ ] Step 5: Commit `feat: add multipart batch media upload transport`.
