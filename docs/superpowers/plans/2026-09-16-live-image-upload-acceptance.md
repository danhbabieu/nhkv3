# NHK V3 Live Image Upload Acceptance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repair the bounded NHK ChatGPT image-upload flow, deploy the coherent `main` release to staging through the canonical wrapper, and complete one real JPEG `Tải ảnh lên` acceptance with canonical Attachment, Media, Capture and public WebP read-back.

**Architecture:** Preserve the existing centralized trusted-file policy, `ImageIngestEntrypoint`, native WordPress attachment lifecycle, canonical Media adoption and `nhk.capture.ingest` `MEDIA_ENRICHMENT` path. Make the widget inspect nested MCP result envelopes before extracting uploads, make the transport return an ordered safe per-item manifest with authoritative counts, and regenerate all served artifacts from one `main` HEAD before deployment.

**Tech Stack:** PHP 8.1+, PHPUnit, WordPress NHK Core, TypeScript/Vite MCP Apps widget, Easy MCP compatibility adapter, canonical staging deployment wrapper.

**Spec:** `docs/superpowers/specs/2026-09-16-live-image-create-root-error-design.md` and the owner request in `/Users/imac24-2125d/.codex/attachments/8c8d8708-922d-460a-b3d3-f2e755441bff/pasted-text.txt`.

## Global Constraints

- Use exact trusted host `sdmntprjapaneast.oaiusercontent.com` only; preserve HTTPS, DNS/IP, private-range, redirect revalidation and no-wildcard rules.
- Keep `nhk.capture.ingest` as the canonical new-submission boundary; `MEDIA_ENRICHMENT` is media-only and must not create an Article/Post.
- Keep source-original PRIVATE/protected under the canonical Media identity and public derivative WebP at max long edge 1200px without upscale/crop/stretch.
- Never return signed URLs, sediment/session URIs, local paths, credentials or raw transport references in model-visible result content.
- Use tests-first for behavior changes; no staging mutation, deployment or live upload occurs until local tests and generated artifacts pass.
- Deploy only with the repository's canonical wrapper and only after its clean committed-checkout/configuration gates pass.

### Task 1: Lock typed result precedence and safe ordered mapping

**Files:**
- Modify: `tools/mcp-apps/image-upload/src/contract.ts`
- Modify: `tools/mcp-apps/image-upload/src/app.ts`
- Test: `tools/mcp-apps/image-upload/tests/mapping.test.mjs`

**Interfaces:**
- Add a pure `inspectToolResult(result)` contract returning a typed server error, transport failure, malformed result or success payload.
- Add a pure `extractUploadManifest(result)` contract that reads authoritative `requested_count`, `success_count`, `failure_count` and ordered `items[]`, with compatibility fallback to `uploads[]` only for older successful envelopes.
- `handleToolResult()` must throw the safe server reason before count validation; `MEDIA_READBACK_COUNT_MISMATCH` is legal only for a valid success manifest with inconsistent counts.

- [ ] Write failing tests for direct, nested and JSON-text typed server errors, plus malformed/empty results not becoming count mismatch.
- [ ] Run `npm test -- tests/mapping.test.mjs` and confirm the new assertions fail for the current outer-only `isError` handling.
- [ ] Write failing tests for ordered `items[]` mapping, stripped `sediment://` IDs, authoritative counts and `media_id` continuation without returned `file_id`.
- [ ] Implement the smallest pure inspector/manifest mapping and update app handling to preserve typed reasons and map ordered successful Media IDs.
- [ ] Run the mapping tests and the widget typecheck; confirm they pass before moving on.

### Task 2: Project the authoritative server upload manifest

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpWidgetUploadTest.php`

**Interfaces:**
- `nhk.media.widget-upload` returns `requested_count`, `success_count`, `failure_count` and one safe `items[]` row per requested ordinal.
- Successful rows contain attachment/Media read-back fields; failed rows contain only ordinal, failure status and safe reason code. Compatibility `uploads[]` may remain, but `items[]` and counts are authoritative.

- [ ] Add failing one-item and multi-item manifest assertions, including `status=SUCCESS`, ordinal, counts and no signed/session URI.
- [ ] Add a failing partial-result assertion proving failed ordinals are retained with safe error codes and are not silently dropped.
- [ ] Add a failing stripped-transport-ID assertion proving ordinal mapping still returns a safe Media row.
- [ ] Run the focused PHP tests and observe the current success-only `uploads[]` projection fail.
- [ ] Implement one ordered manifest projection from the existing `ImageIngestEntrypoint` result without adding a writer or changing materialization policy.
- [ ] Run the focused PHP tests and existing widget Ability/transport contract tests.

### Task 3: Close the exact Japan East allowlist and serialized schema proof

**Files:**
- Modify: `public/wp-content/mu-plugins/nhk-chatgpt-file-transport.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ChatGptFileAllowlistConfigTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ChatGptMcpGatewayTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EasyMcpNativeFileCompatibilityAdapterTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php`

**Interfaces:**
- The existing `nhk_chatgpt_file_allowed_hosts` staging filter accepts the exact Japan East host and rejects sibling/private/loopback/redirect/wildcard cases.
- Easy MCP's final serialized `nhk.capture.ingest` descriptor projects the canonical intent enum `VIDEO`, `IMAGE_ARTICLE`, `TEXT_ARTICLE`, `KNOWLEDGE_DELTA`, `MEDIA_ENRICHMENT`.

- [ ] Add failing exact-host and redirect assertions against the existing allowlist/materializer helper without introducing another policy.
- [ ] Add failing final Easy MCP serialized descriptor assertion for the five-intent enum and current widget result contract.
- [ ] Run focused allowlist, gateway, adapter and MCP contract tests to record the expected red state.
- [ ] Append only `sdmntprjapaneast.oaiusercontent.com` to the staging filter and make the adapter projection consume the canonical catalog descriptor for the final serialized surface.
- [ ] Run all focused security/schema/Ability tests and verify no wildcard or broad parent host was added.

### Task 4: Regenerate and verify the single release tuple

**Files:**
- Modify through the existing build only: `public/wp-content/plugins/nhk-core/resources/ui/image-upload.html`
- Read/verify: `tools/mcp-apps/image-upload/image-upload.html`, canonical docs snapshot/manifest and deployment inventory.
- Modify only if required by failing tests: existing deployment wrapper/verifier files.

**Interfaces:**
- Source widget, served resource, Capture catalog/schema, Ability projection, canonical docs snapshot/manifest and release inventory must identify the same committed HEAD.

- [ ] Run widget production build and assert `image-upload.html` contains both Vietnamese buttons, both intents, one physical upload path and no forbidden transport leak.
- [ ] Run canonical docs generation and release/preflight verification commands documented by the repository; do not hand-edit generated artifacts.
- [ ] Run the relevant PHP unit/contract suites, PHP lint, JS syntax checks, `git diff --check` and scoped secret review.
- [ ] Update `V3_EXECUTION_STATE.md` with the local repair evidence and exact pre-deploy gate results, preserving the user-owned untracked plan.
- [ ] Commit only the bounded repair, tests, generated artifacts, plan and execution-state checkpoint on `main`.

### Task 5: Canonical staging deploy and live acceptance loop

**Files:**
- No additional source changes unless a live failure is reproduced and fixed through a new TDD cycle.

**Interfaces:**
- Canonical wrapper deploys the committed release; live read-back must verify documentation bootstrap, Capture five-intent schema, widget resource, descriptor, Attachment, Media, Capture and public derivative.

- [ ] Resolve the existing deployment configuration through repository documentation without inventing credentials; stop fail-closed if it is unavailable.
- [ ] Run the canonical wrapper for staging and verify deployed commit/manifest before upload.
- [ ] Refresh/reconnect only the existing `@V3-18` connector if stale schemas are observed.
- [ ] Audit exact pre-existing target state and upload exactly one real JPEG with description `ODO 1962` using only `Tải ảnh lên`.
- [ ] Read back exactly one Attachment, one canonical Media, source-original PRIVATE/protected, public WebP, dimensions/MIME/state, Capture intent `MEDIA_ENRICHMENT`, and zero Article/Post creation.
- [ ] If the live attempt fails, preserve the exact stage/reason/envelope/audit, add a failing regression test, fix one root cause at a time, rerun local gates, redeploy through the same wrapper and retry idempotently without duplicate physical uploads.
- [ ] Update `V3_EXECUTION_STATE.md` with the verified acceptance receipt and return the required completion fields only after `UPLOAD_FINAL_STATUS=COMPLETE`.

## Self-review

- The plan covers the owner request's root error, safe manifest, exact host, schema parity, generated artifacts, local gates, canonical deployment, connector refresh, one-image acceptance, read-back and retry/idempotency requirements.
- No new allowlist, transport, entity, relation, Article flow, migration, legacy repair or unrelated Clock Type work is introduced.
- All implementation changes have a failing-test step before production code, and generated files are updated only by the existing build.
