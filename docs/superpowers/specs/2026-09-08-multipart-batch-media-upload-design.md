# NHK V3 Multipart Batch Media Upload Design

> This design is subordinate to `docs/constitution/NHK_V3_CONSTITUTION.md`.

## Decision

Add `nhk.media.upload-batch` / `nhk-v3/media-upload-batch` as the canonical
multipart transport capability. It accepts one or more files in one request,
while a single file uses the same batch pipeline with a batch size of one.

The transport creates WordPress attachment projections through native
WordPress APIs, reads them back canonically, and converges on the existing
governed Media V3 boundary. `nhk-v3/media-ingest` remains the semantic Media
ingest/binding boundary; it is not changed into a binary uploader and no
parallel semantic writer is introduced.

## Flow and boundaries

```text
multipart request
  -> batch transport validation and per-item limits
  -> WordPress native attachment creation and metadata generation
  -> attachment read-back
  -> governed Media/MediaAsset adoption and mapping
  -> per-item manifest
  -> caller may perform media-attachment-get and media-ingest semantic steps
```

The batch context stores only workflow provenance, uploader, source/context,
count and status. It is not an Authority entity, Graph endpoint, Knowledge,
Source, Evidence or Media identity. Filename, OCR, EXIF, intended role and
subject hints do not infer or apply semantic relations or create MediaUsage
during the transport phase. After governed Media ingest/read-back,
Constitution §20.1 requires bounded reconciliation of justified usages and
registered relations.

## Request and response

The multipart form supports `batch_id` or `idempotency_key`, `files[]`,
optional metadata JSON and optional per-file item metadata. Per-file metadata
may include `client_file_id`, filename override, title, alt text, caption,
intended-role hint and sort order. Batch metadata may include description,
source kind, client context, intended subject and upload origin.

The response is a reader-safe manifest with batch id/key, totals, success and
failure counts, partial-success state, ordered items and typed errors. Each
successful item includes client id, attachment id, Media id, canonical source
URL, filename, MIME, byte size, dimensions, SHA-256, read-back status, reuse
state and upload status. Binary and base64 are never returned.

## Integrity, replay and failure

Actual bytes are inspected; client MIME is advisory. MIME/extension/size,
filename/path safety, image structure, dimensions, upload count and total
batch size are bounded by configurable policy and WordPress canonical limits.
SHA-256 is calculated over the real bytes. The idempotency fingerprint covers
the key, ordered item metadata, checksum, byte size, detected MIME, dimensions
and policy version. Same key and fingerprint returns the existing manifest;
same key with a different fingerprint returns a deterministic conflict.
Checksum is used for verification/retry only, never unsafe global semantic
deduplication. A network retry first attempts canonical binding/read-back.

Items are isolated. A failed item is reported while successful attachments and
Media bindings remain. Retry can target the failed `client_file_id`. Partial
artifacts are journaled and only artifacts created by the current attempt are
removed in reverse order when that item fails. Existing reused artifacts are
never deleted. Uncertain cleanup is a non-success, not a false pass.

## WordPress and Media lifecycle

Each item uses `wp_handle_sideload()` or the appropriate native upload API,
`wp_insert_attachment()`, `wp_generate_attachment_metadata()` and metadata
read-back. The source-original is retained as a private/protected MediaAsset;
WordPress derivatives are derivatives under the same Media and never new
semantic identities. Storage containment and public delivery policy are
verified. No raw DB write or arbitrary client filesystem path is accepted.

The transport enters the existing Media application boundary for exactly one
Media create-or-resolve per uploaded item. It does not infer or apply
MediaUsage, Knowledge, Source, Evidence or Graph edges during transport.
Semantic role/target resolution continues through the registered
`nhk-v3/media-ingest` contract and its mandatory bounded post-ingest
reconciliation; weak/speculative relations remain unapplied.

## MCP and security

The custom MCP HTTP transport preserves JSON-RPC arguments separately from
multipart file parts. The batch capability is registered in the executable
catalog, permission map, Ability bridge and discovery/export tests. It requires
authenticated access and `upload_files`/the registered upload capability.
The implementation rejects unsupported MIME, disguised extensions, path
traversal, executable content, oversized/too-many files, unsafe SVG/HEIC when
runtime support is absent, SSRF inputs and arbitrary paths. It does not log
binary, base64, credentials, tokens or unnecessary EXIF GPS data.

## Documentation and verification

Canonical Media, MCP, current-status and execution-state documents will state
multipart batch as PRIMARY, URL import as SECONDARY/IMPORT, base64 as FALLBACK,
and preserve the transport/semantic boundary. Tests cover valid single/batch,
ordering, partial failure/retry, idempotency/conflict, security, WordPress
attachment metadata/derivatives/read-back, Media ingest integration, no
semantic inference, existing tool regression and actual MCP discovery.
