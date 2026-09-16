# NHK V3 Live Image Create Failure — Root Error Preservation Design

## Status and scope

This design covers the local runtime repair for the ChatGPT image widget and
its existing Easy MCP / NHK MCP boundaries. It addresses result-envelope error
precedence, safe upload-manifest mapping, the observed Japan East trusted-file
host, serialized Capture schema parity, and the two-button Capture handoff.

It does not authorize deployment, connector reconnection, staging semantic
mutation, production mutation, legacy-data repair, a new transport, a second
host allowlist, or a generic WordPress writer.

## Observed root cause

The widget currently checks `result.isError` only on the outer object. The
MCP/Ability bridge can return a server result whose typed failure is nested in
`result.isError` and `result.structuredContent.error`, or represented as JSON
text. The widget then extracts zero uploads and raises
`MEDIA_READBACK_COUNT_MISMATCH`, replacing the server's safe machine reason.

The current widget server adapter also emits a success-only `uploads[]` list
and correlates it by the ChatGPT `file_id`. Session-scoped identifiers such as
`sediment://...` must remain request-only, so they cannot be the sole mapping
key or be echoed into model-visible result content. The underlying batch
service already preserves bounded request order and per-item failures; the
widget response must project that information explicitly.

The staging trusted-file filter contains exact observed OpenAI hosts but does
not contain `sdmntprjapaneast.oaiusercontent.com`. The allowlist must remain
exact-host and centralized at the existing filter boundary.

The canonical local Capture registry already contains
`MEDIA_ENRICHMENT`, but the Easy MCP serialized connector surface has a
separate projection path. Its final serialized Capture descriptor must be
asserted, not inferred from the PHP enum/catalog test.

## Design

### 1. Result envelope and failure precedence

Add a pure widget-contract result inspection boundary that recognizes the
bounded bridge shapes actually used by the runtime:

1. direct `structuredContent`;
2. direct `result` containing `structuredContent` or another `result`;
3. direct/nested `isError`;
4. a text content block containing JSON with the same shapes.

The inspector returns either a safe typed application error, a transport
failure, a successful payload, or an unavailable/malformed result. A typed
error reads only a safe `reason_code`, `code`, or equivalent machine-code field
matching the existing uppercase diagnostic vocabulary. Human text is sanitized
for URL leakage before display or persistence. Signed URLs, sediment URIs,
credentials, local paths, and arbitrary nested payloads are never copied into
widget state.

`handleToolResult()` must inspect in this order:

`transport failure → typed server application error → malformed result →
successful upload packet → upload-count validation → attachment/media
read-back validation`.

The widget throws the typed server reason before upload extraction. A count
mismatch is permitted only for a structurally valid successful packet whose
bounded manifest says success but whose verified successful items do not match
the expected physical selection. It is never emitted for a typed failure or an
empty/malformed result.

### 2. Bounded physical upload manifest

The widget upload response becomes a deterministic safe manifest with:

```json
{
  "requested_count": 1,
  "success_count": 1,
  "failure_count": 0,
  "items": [
    {
      "ordinal": 0,
      "status": "SUCCESS",
      "attachment_id": 489,
      "media_id": "canonical-media-uuid",
      "public_filename": "safe-name.webp",
      "original_filename": "IMG_0001.jpg",
      "canonical_url": "/anh/safe-name.webp",
      "attachment_readback_status": "verified",
      "mime": "image/webp",
      "filesize": 100,
      "width": 100,
      "height": 100
    }
  ]
}
```

Every requested ordinal appears exactly once in request order. Successful
items contain the safe continuation/read-back identity above. Failed items
contain `ordinal`, `status: "FAILED"`, and a safe error code only. The result
may retain a compatibility `uploads[]` success projection if existing clients
require it, but `items[]` and the three counts are authoritative.

The server first reconciles by an opaque `file_id` only when that identifier is
safe to retain. If the server intentionally strips a session-scoped transport
identifier, it reconciles by the explicit bounded ordinal/result index from
the request and manifest. It never guesses across unequal counts. No signed
URL, sediment URI, temporary path, or local filename path is returned.

The widget maps `media_id` from the ordered successful manifest items for the
Capture call. It does not require a returned `file_id`; `file_id` is optional
result metadata and is never the sole identity for continuation.

### 3. Trusted host policy

Append exactly `sdmntprjapaneast.oaiusercontent.com` to the existing staging
`nhk_chatgpt_file_allowed_hosts` filter. Do not add a wildcard or introduce a
second policy. Existing HTTPS-only validation, exact host matching,
DNS/IP public-range validation, private/loopback/link-local rejection,
`wp_safe_remote_get` safety, zero automatic redirects, and per-hop redirect
revalidation remain unchanged.

Regression coverage must prove:

- the exact Japan East host is accepted by the existing policy helper;
- an unknown sibling host is rejected;
- wildcard entries remain absent;
- a redirect to a non-allowlisted host is rejected.

### 4. Serialized Capture schema

Use the canonical `McpToolCatalog` Capture descriptor as the source for both
the direct MCP `tools/list` projection and the Easy MCP final serialized
descriptor. Add an exact serialized regression assertion that the Capture
intent enum is:

```json
["VIDEO", "IMAGE_ARTICLE", "TEXT_ARTICLE", "KNOWLEDGE_DELTA", "MEDIA_ENRICHMENT"]
```

The compatibility adapter may preserve Easy MCP's runtime tool name,
annotations, filtering, and authentication boundary, but it must not serve a
stale intent enum or weaken the native Capture file contract.

### 5. Two-button flow

The widget has one physical upload operation and one ordered successful
`media_ids` array. After that operation:

- `Tải ảnh lên` calls the canonical Capture with `intent: MEDIA_ENRICHMENT`
  and no Article publication intent;
- `Tạo bài viết` calls the same Capture handoff with `intent: IMAGE_ARTICLE`
  and `publish: true` according to the existing publication gate.

The frontend artifact tests must assert both intent references, the single
shared physical upload path, and reference-host ordering. A second button
click after physical success must reuse the same ordered Media IDs rather than
uploading the selected file again.

## Components and expected files

- `tools/mcp-apps/image-upload/src/contract.ts`: pure result/error/manifest
  inspection and safe ordered mapping.
- `tools/mcp-apps/image-upload/src/app.ts`: precedence-aware handling and
  Capture handoff using successful `media_id` values.
- `tools/mcp-apps/image-upload/tests/mapping.test.mjs`: typed-error envelope,
  sediment-stripping, ordinal mapping, and count-precedence regressions.
- `tools/mcp-apps/image-upload/tests/image-upload-artifact.test.mjs`: exact
  two-button and single-upload artifact assertions.
- `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`:
  safe per-item widget manifest projection.
- `public/wp-content/plugins/nhk-core/tests/Unit/McpWidgetUploadTest.php`:
  one-item, batch, failure, order, and stripped-transport-ID response tests.
- `public/wp-content/plugins/nhk-core/src/Infrastructure/Mcp/TrustedProvidedFileMaterializer.php`
  and its tests: exact-host/redirect security coverage only if needed by the
  existing helper boundary.
- `public/wp-content/mu-plugins/nhk-chatgpt-file-transport.php`: one exact
  staging host addition.
- `public/wp-content/plugins/nhk-core/tests/Unit/EasyMcpNativeFileCompatibilityAdapterTest.php`
  and MCP contract tests: final serialized `MEDIA_ENRICHMENT` proof.
- `public/wp-content/plugins/nhk-core/resources/ui/image-upload.html`: the
  regenerated served widget artifact, only through the existing build.

## Verification contract

The implementation is accepted only when focused RED/GREEN tests cover all
four error shapes, the server manifest and ordinal fallback, Japan East and
redirect security, exact serialized Capture schema, and both buttons. Then
run the requested widget suite, TypeScript check, production widget build,
trusted-file materializer tests, MCP widget transport tests, Ability
result-envelope tests, PHP lint, `git diff --check`, and a scoped secret
review. No live deployment or staging mutation is implied by local green
tests.

