# Media and MCP Documentation Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reconcile all current NHK V3 Media/MCP documentation around the implemented multipart batch transport and the separate governed semantic Media ingest boundary.

**Architecture:** Keep `nhk.media.upload-batch` as the canonical multipart binary transport and route its ordered file results through native WordPress attachment creation, metadata/derivative generation and canonical read-back. Keep `nhk-v3/media-ingest` as the governed semantic Media boundary that may adopt an attachment. Upload transport must not infer or apply Knowledge, Source, Evidence or Graph truth during transport; after canonical Media ingest/read-back, Constitution §20.1 requires bounded reconciliation of every justified useful registered relation.

**Tech Stack:** Markdown contracts/status ledgers, PHP MCP catalog/transport/service code, PHPUnit tests, PHP lint and repository search.

**Spec:** `docs/superpowers/specs/2026-09-08-multipart-batch-media-upload-design.md`

## Global Constraints

- The Constitution remains the only supreme architectural authority.
- Primary upload is `nhk.media.upload-batch` multipart; URL import is secondary/import; base64 is fallback/compatibility.
- `nhk-v3/media-ingest` remains a separate governed semantic boundary.
- WordPress attachment lifecycle uses native APIs and canonical read-back; no raw DB or manual uploads copying.
- SHA-256 is computed from actual bytes; same idempotency key plus different payload fails with `IDEMPOTENCY_CONFLICT`.
- Batch results are per-item and may be `partial_success`; successful items are retained for retry of failed items.
- No upload-only semantic Knowledge, Source, Evidence or Graph mutation is documented or introduced during transport; post-ingest reconciliation is mandatory after the governed Media boundary and must reject weak/speculative edges.
- Runtime acceptance is not claimed unless fresh target discovery/read-back proves it.

### Task 1: Inventory and classify current documentation

**Files:**
- Read: `AGENTS.md`, `docs/constitution/READ_FIRST.md`, `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Read: Media/MCP contracts, specs, plans and execution ledger named in the reconciliation request.

- [x] **Step 1: Search all repository text for Media/MCP transport terms.**

Run the requested case-insensitive search for `base64`, `wp_upload_media`, `wp_upload_media_from_url`, `media-ingest`, `media attachment`, `multipart`, `upload-batch`, `MediaAsset` and `MediaUsage`, excluding vendored WordPress implementation files.

- [x] **Step 2: Map each documentation hit to CURRENT, HISTORICAL, TEST FIXTURE, DEPRECATED or INCORRECT.**

Record the map in the final report and use it to decide whether a hit needs an inline correction or a historical label.

### Task 2: Reconcile canonical Media and MCP contracts

**Files:**
- Modify: `docs/architecture/04_MEDIA_MODEL.md`
- Modify: `docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md`
- Modify: `docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: `docs/architecture/V3_MASTER_PLAN.md`

- [x] **Step 1: Add one shared canonical flow and path classification.**

Use the exact sequence `multipart batch → WordPress attachment lifecycle → canonical attachment read-back / media-attachment-get → media-ingest → MediaAsset → Media → MediaUsage`, and identify the one-file case as a batch of one.

- [x] **Step 2: Normalize batch fields and failure semantics.**

Document `files[]`, `idempotency_key`, ordered manifest, attachment/source/technical fields, `created`/`reused`, canonical read-back, typed per-item errors and `partial_success`.

- [x] **Step 3: Correct stale transport wording.**

Replace any current statement that treats direct `nhk.media.ingest` multipart as the primary binary transport with `nhk.media.upload-batch`; retain direct compatibility/attachment-binding behavior only where code supports it.

- [x] **Step 4: Make Source/Evidence and Product/Specimen boundaries explicit.**

State that upload does not infer semantic truth; only a governed semantic workflow may reconcile Source/Evidence, and Product remains a listing/offer distinct from the physical Specimen. Keep Product–Specimen as `REGISTRY_GAP` where no approved relation exists.

### Task 3: Reconcile bootstrap and status routing

**Files:**
- Modify: `docs/constitution/READ_FIRST.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [x] **Step 1: Add concise bootstrap pointers.**

Route Media/image work to the canonical Media model, P6 foundation, Admin guidance and Media section of the current MCP contract; route MCP/Admin work to the current content operations and control-plane docs.

- [x] **Step 2: Make the status index authoritative as a router, not a second Constitution.**

List canonical operational Media/MCP docs, executable code/runtime sources, dated design/plan history and deprecated/superseded documents. State that multipart live acceptance is `IMPLEMENTED_CODE_SIDE / LIVE_ACCEPTANCE_PENDING` when not freshly verified.

- [x] **Step 3: Append a dated documentation-reconciliation checkpoint.**

Record exact verification results, runtime blockers, Constitution status and the fact that no data or deployment was changed.

### Task 4: Verify and commit

**Files:**
- Test: focused Media/MCP PHPUnit suites and full Unit suite.
- Review: all changed documentation and final stale-reference classification.

- [x] **Step 1: Run focused and full verification.**

Run the repository's focused MCP/Media tests, full Unit suite, PHP lint if code is touched, `git diff --check`, and a secret review.

- [x] **Step 2: Re-run the stale-reference search.**

Confirm no CURRENT documentation says base64 is the default upload path or conflates transport with semantic ingest.

- [ ] **Step 3: Stage only valid changes and commit locally.**

Use `git add` for the reconciled docs/plan and commit with `docs: reconcile media and MCP batch upload contracts`; do not push or deploy.
