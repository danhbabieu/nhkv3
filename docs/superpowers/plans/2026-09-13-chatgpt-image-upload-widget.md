# ChatGPT In-Chat Image Upload Widget Implementation Plan

> **For agentic workers:** execute this plan in the current NHK V3 workspace with TDD and verification gates. Preserve unrelated worktree changes.

**Goal:** Add a bounded ChatGPT MCP Apps image-upload adapter that materializes trusted structured file references through the existing transport, reuses `ImageIngestEntrypoint`/WordPress attachment/Media adoption, and lets Capture reuse canonical `media_ids` without re-uploading bytes.

**Architecture:** Keep `ImageIngestEntrypoint` as the only physical image-ingest owner and `nhk.capture.ingest` as the semantic owner. Extract the existing gateway materializer into a reusable transport service, add one structured-reference media tool, expose one MCP Apps resource/render tool through the existing MCP transport, and add a read-back-only Media resolver seam for Capture `media_ids`. No new storage, writer, schema migration, or semantic owner.

**Tech Stack:** PHP 8.2+, WordPress REST/MCP transport, PHPUnit 11, dependency-free HTML/JavaScript MCP Apps component, existing NHK V3 repositories and attachment bridge.

## Constraints and acceptance gates

- TDD: each production change starts with a focused failing test, then the smallest green implementation.
- Reuse `ChatGptMcpGateway` validation behavior, `ImageIngestEntrypoint`, `MediaBatchUploadService`, `WordPressMediaAttachmentIngestor`, canonical Media read-back and existing Capture reconciliation.
- Reject opaque strings, local paths, base64, arbitrary URLs, untrusted hosts and redirects leaving the trusted boundary; never log signed URL material.
- Keep native multipart behavior unchanged and preserve file order/cardinality.
- Do not add Capture/Post/Article/Video/Graph/Knowledge/Governance semantics beyond the requested `media_ids` handoff; do not publish or mutate staging/production.
- Use only `nhk_v3_test` for guarded integration and clean disposable fixtures through an existing owned cleanup path.

## Task 1: Record the implementation baseline

**Read-only evidence:** confirm the current execution state, active Media/Capture/MCP/visual contracts, current `McpToolCatalog`, `McpTransport`, `McpAbilityRegistration`, `ChatGptMcpGateway`, `ImageIngestEntrypoint`, `MediaBatchUploadService`, attachment ingestor and Capture continuation path.

**Tests/checks:** run the focused gateway, ImageIngest, MCP transport/catalog and existing Capture media tests before changes; record unavailable guarded-database status without weakening tests.

## Task 2: Extract the trusted materializer without behavior drift

**Tests first:** add `TrustedProvidedFileMaterializerTest` covering the current structured OpenAI file object, exact host allowlist, MIME/size checks, redirect revalidation, malformed/opaque/path/base64 rejection, order and temporary-file cleanup. Add a gateway regression assertion that the gateway delegates to the reusable service and preserves its existing result/error contract. Run the new tests first and capture RED because the service does not yet exist.

**Implementation:** move the existing `materializeReferences`, URL/redirect validation, download, MIME sniffing, filename and temporary-file handling into `Application/Media/TrustedProvidedFileMaterializer` (or the closest existing transport namespace selected by the source layout). Keep `ChatGptMcpGateway` as the compatibility boundary and delegate to the service. Change the plugin composition root to inject the service into `ImageIngestEntrypoint`; do not change allowlists or downloader policy.

**Verification:** focused materializer/gateway/ImageIngest tests and PHP lint. Confirm no signed URL, query, token or byte logging was introduced.

## Task 3: Add the structured-reference widget upload data tool

**Tests first:** add catalog/schema and transport tests for `nhk.media.widget-upload`: one trusted reference, ordered multi-file references, 1..20 bounds, idempotent replay, per-file `file_id` result mapping, attachment and Media read-back delegation, and rejection of opaque/arbitrary/untrusted inputs. Add assertions that existing `nhk.media.upload-batch`, native multipart and text-only calls remain unchanged.

**Implementation:** register the transport-only tool with `idempotency_key` and structured `files` references, reuse the existing 50 MB/20-file limits, call the injected `ImageIngestEntrypoint`, and project only safe read-back fields plus `file_id` into `uploads`. Do not return signed URLs or create a second upload path. Keep the existing batch tool and its Ability mapping intact.

## Task 4: Add the MCP Apps UI resource and render tool on the existing MCP transport

**Tests first:** add tests for catalog `_meta.ui.resourceUri`, `resources/list`, `resources/read`, render-tool output, and a static UI contract covering feature detection, `uploadFile`, `getFileDownloadUrl`, standard `tools/call`, optional compatibility alias, structured widget state, model-visible Media IDs only, per-file status, and absence of signed URL persistence/display. Run RED before adding the resource/handler.

**Implementation:** add `resources/ui/image-upload.html` as a dependency-free iframe component. Extend the existing `McpTransport` protocol handler with the standard resource list/read methods and resource capability while retaining existing tool behavior. Register `nhk.media.upload-widget.open` with `_meta.ui.resourceUri = ui://nhk/image-upload.html`; keep the upload data tool decoupled. The component uses the ChatGPT file APIs when available, posts standard MCP Apps JSON-RPC over `window.parent.postMessage`, persists `{modelContent, privateContent, imageIds}`, displays IDs/status, and sends a follow-up containing Media IDs—not temporary URLs.

## Task 5: Add Capture `media_ids` reuse through existing reconciliation

**Tests first:** add Capture schema/transport/coordinator tests for new Capture and `ATTACH_ASSETS` reuse, active Media and attachment read-back, order preservation, unknown/inactive Media rejection, idempotency, no attachment/Media duplication, no redownload, duplicate physical identity fail-closed, and unchanged legacy `files[]` behavior.

**Implementation:** extend the canonical Capture input contract with optional UUID `media_ids`. Reuse an existing Media/asset/attachment read-back service or add only a transport/read adapter if no suitable resolver exists. Convert verified canonical records into the existing asset manifest consumed by Capture and pass that manifest into the current reconciliation path. Keep `ImageIngestEntrypoint` for new physical files and reject ambiguous simultaneous duplicate descriptions.

## Task 6: Guarded WordPress integration

**Tests first:** extend the existing guarded integration suite with a structured-reference fixture that uses an injected test downloader/materializer, then proves materializer → `ImageIngestEntrypoint` → native attachment metadata/derivatives → canonical Media adoption/read-back. Add a second fixture proving an existing Media ID enters Capture and reaches existing MediaUsage reconciliation. Use only `nhk_v3_test`, exact test guards and an owned cleanup path.

**Implementation:** only wire existing services in the plugin composition root; do not add direct database writes or generic WordPress writers.

## Task 7: Documentation and final verification

Update active contracts minimally for the widget adapter, structured references, physical owner, Capture `media_ids` reuse, duplicate-upload law and widget state. Update `docs/architecture/V3_EXECUTION_STATE.md` with fresh local/guarded evidence; do not amend the Constitution.

Run focused tests, full Unit, Contract, guarded Integration where the database is available, PHP lint, composer lint, `git diff --check`, deterministic docs generation and secret review. Inspect the final diff for unrelated files and report `READY_FOR_SINGLE_DEPLOY=YES` only when all required local/guarded checks pass. Do not commit, push, pull, deploy or perform live mutation in this task.

