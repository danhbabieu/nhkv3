import assert from "node:assert/strict";
import { test } from "node:test";
import { assertCaptureArticleReadback, assertMediaArticleReadback, assertUploadManifestCount, assertUploadManifestCounts, buildCaptureAssetInputs, buildWidgetState, buildWidgetUploadArguments, buildWidgetUploadFailureManifest, captureRequiresArticleReadback, extractPayload, extractUploadManifest, extractUploads, inspectToolResult, mergeUploadManifest, normalizeCaptureReadback, normalizeSelectedFiles, shouldProcessToolResultNotification, splitFeatureRequests } from "../src/contract.ts";
import { planSubmissionResume } from "../src/resume-policy.ts";

function committedSubmission(count, overrides = {}) {
  return {
    media_commit_status: "COMPLETE",
    enrichment_status: "PARTIAL",
    intent: "IMAGE_ARTICLE",
    items: Array.from({ length: count }, (_, ordinal) => ({
      ordinal,
      client_file_id: `client-${ordinal}`,
      media_id: `media-${ordinal}`,
      status: "MEDIA_COMMITTED",
    })),
    ...overrides,
  };
}

for (const count of [1, 2, 3, 10]) {
  test(`resumes enrichment without physical uploads for N=${count}`, () => {
    const plan = planSubmissionResume(committedSubmission(count));

    assert.equal(plan.phase, "CONTINUE_ENRICHMENT");
    assert.deepEqual(plan.materializeOrdinals, []);
    assert.deepEqual(plan.canonicalMediaIds, Array.from({ length: count }, (_, ordinal) => `media-${ordinal}`));
    assert.equal(plan.captureAction, "ENSURE_ONE");
    assert.equal(plan.articleAction, "ENSURE_ONE");
  });
}

test("retries only failed media children and preserves canonical order", () => {
  const plan = planSubmissionResume(committedSubmission(10, {
    media_commit_status: "PARTIAL",
    enrichment_status: "NOT_RUN",
    items: Array.from({ length: 10 }, (_, ordinal) => ({
      ordinal,
      client_file_id: `client-${ordinal}`,
      media_id: ordinal === 2 || ordinal === 7 ? undefined : `media-${ordinal}`,
      status: ordinal === 2 || ordinal === 7 ? "FAILED_RETRYABLE" : "MEDIA_COMMITTED",
    })),
  }));

  assert.equal(plan.phase, "MATERIALIZE_MEDIA");
  assert.deepEqual(plan.materializeOrdinals, [2, 7]);
  assert.deepEqual(plan.canonicalMediaIds, ["media-0", "media-1", "media-3", "media-4", "media-5", "media-6", "media-8", "media-9"]);
  assert.equal(plan.articleAction, "ENSURE_ONE");
});

test("builds the widget request with only server-supported file reference fields", () => {
  const request = buildWidgetUploadArguments("operation-1", [{
    download_url: "https://files.example/image",
    file_id: "file-1",
    mime_type: "image/jpeg",
    file_name: "image.jpg",
    ordinal: 0,
    media: { title: "Mặt trước" },
  }], "Bộ ảnh đồng hồ", false, 0);

  assert.deepEqual(request, {
    idempotency_key: "operation-1:media",
    metadata: { description: "Bộ ảnh đồng hồ" },
    items: [{ client_file_id: "file-1", filename: "image.jpg", sort_order: 0, ordinal: 0, media: { title: "Mặt trước" } }],
    files: [{ download_url: "https://files.example/image", file_id: "file-1", mime_type: "image/jpeg", file_name: "image.jpg" }],
  });
});

test("preserves host identities and context when the Media boundary fails before processing", () => {
  const manifest = buildWidgetUploadFailureManifest([
    { download_url: "https://files.example/a", file_id: "file-a", mime_type: "image/jpeg", file_name: "a.jpg", ordinal: 0, media: { title: "Mặt trước" } },
    { download_url: "https://files.example/b", file_id: "file-b", mime_type: "image/jpeg", file_name: "b.jpg", ordinal: 1, media: { title: "Mặt sau" } },
  ], "Bộ ảnh đồng hồ", "PROVIDED_FILE_REFERENCE_FIELDS_INVALID");

  assert.equal(manifest.status, "partial_success");
  assert.equal(manifest.user_context, "Bộ ảnh đồng hồ");
  assert.deepEqual(manifest.items.map((item) => ({ ordinal: item.ordinal, file_id: item.file_id, client_file_id: item.client_file_id, status: item.status, error_code: item.error_code })), [
    { ordinal: 0, file_id: "file-a", client_file_id: "file-a", status: "FAILED_RETRYABLE", error_code: "PROVIDED_FILE_REFERENCE_FIELDS_INVALID" },
    { ordinal: 1, file_id: "file-b", client_file_id: "file-b", status: "FAILED_RETRYABLE", error_code: "PROVIDED_FILE_REFERENCE_FIELDS_INVALID" },
  ]);
  assert.deepEqual(buildWidgetState(manifest.items, [], "complete", manifest).modelContent.batch_context, {
    ordered_media_ids: [],
    items: [
      { position: 1, client_file_id: "file-a", status: "FAILED_RETRYABLE", error_code: "PROVIDED_FILE_REFERENCE_FIELDS_INVALID" },
      { position: 2, client_file_id: "file-b", status: "FAILED_RETRYABLE", error_code: "PROVIDED_FILE_REFERENCE_FIELDS_INVALID" },
    ],
    user_context: "Bộ ảnh đồng hồ",
    media_commit_status: "PARTIAL",
    enrichment_status: "NOT_RUN",
  });
  const plan = planSubmissionResume({ media_commit_status: "PARTIAL", items: manifest.items });
  assert.deepEqual(plan.hostUploadOrdinals, []);
  assert.deepEqual(plan.mediaUploadOrdinals, [0, 1]);
});

test("does not host-upload a failed Media commit when its stable host reference exists", () => {
  const plan = planSubmissionResume(committedSubmission(10, {
    media_commit_status: "PARTIAL",
    enrichment_status: "NOT_RUN",
    items: Array.from({ length: 10 }, (_, ordinal) => ({
      ordinal,
      client_file_id: `client-${ordinal}`,
      file_id: ordinal === 2 || ordinal === 7 ? `file-${ordinal}` : undefined,
      media_id: ordinal === 2 || ordinal === 7 ? undefined : `media-${ordinal}`,
      status: ordinal === 2 || ordinal === 7 ? "FAILED_RETRYABLE" : "MEDIA_COMMITTED",
    })),
  }));

  assert.deepEqual(plan.materializeOrdinals, [2, 7]);
  assert.deepEqual(plan.hostUploadOrdinals, []);
  assert.deepEqual(plan.mediaUploadOrdinals, [2, 7]);
});

test("host materialization is limited to LOCAL children without a stable file reference", () => {
  const plan = planSubmissionResume({
    media_commit_status: "PARTIAL",
    items: [
      { ordinal: 0, client_file_id: "client-a", status: "LOCAL" },
      { ordinal: 1, client_file_id: "client-b", file_id: "file-b", status: "HOST_UPLOADED" },
      { ordinal: 2, client_file_id: "client-c", file_id: "file-c", status: "FAILED_RETRYABLE" },
    ],
  });

  assert.deepEqual(plan.hostUploadOrdinals, [0]);
  assert.deepEqual(plan.mediaUploadOrdinals, [0, 1, 2]);
});

test("repairs only missing MediaUsage for an existing Article", () => {
  const plan = planSubmissionResume(committedSubmission(10, {
    capture: { id: "capture-1", exists: true },
    article: { id: 77, exists: true },
    media_usages: [
      ...Array.from({ length: 8 }, (_, ordinal) => ({ media_id: `media-${ordinal}`, status: "COMPLETE" })),
    ],
  }));

  assert.equal(plan.phase, "REPAIR_MEDIA_USAGE");
  assert.deepEqual(plan.usageMediaIds, ["media-8", "media-9"]);
  assert.equal(plan.captureAction, "REUSE");
  assert.equal(plan.articleAction, "REUSE");
  assert.deepEqual(plan.materializeOrdinals, []);
});

test("retries projection only after canonical MediaUsage is complete", () => {
  const plan = planSubmissionResume(committedSubmission(10, {
    article: { id: 77, exists: true },
    media_usages: Array.from({ length: 10 }, (_, ordinal) => ({ media_id: `media-${ordinal}`, status: "COMPLETE" })),
    projection: { status: "FAILED_RETRYABLE" },
  }));

  assert.equal(plan.phase, "RETRY_PROJECTION");
  assert.deepEqual(plan.materializeOrdinals, []);
  assert.deepEqual(plan.usageMediaIds, []);
  assert.equal(plan.articleAction, "REUSE");
});

test("returns idempotent NOOP when every owner and projection is complete", () => {
  const plan = planSubmissionResume(committedSubmission(3, {
    enrichment_status: "COMPLETE",
    article: { id: 77, exists: true },
    media_usages: Array.from({ length: 3 }, (_, ordinal) => ({ media_id: `media-${ordinal}`, status: "COMPLETE" })),
    projection: { status: "COMPLETE" },
  }));

  assert.equal(plan.phase, "NOOP");
  assert.deepEqual(plan.materializeOrdinals, []);
  assert.deepEqual(plan.usageMediaIds, []);
  assert.equal(plan.articleAction, "REUSE");
});

test("orders canonical Media by stable ordinal instead of arrival order", () => {
  const plan = planSubmissionResume(committedSubmission(3, {
    items: [
      { ordinal: 2, client_file_id: "client-c", media_id: "media-c", status: "MEDIA_COMMITTED" },
      { ordinal: 0, client_file_id: "client-a", media_id: "media-a", status: "MEDIA_COMMITTED" },
      { ordinal: 1, client_file_id: "client-b", media_id: "media-b", status: "MEDIA_COMMITTED" },
    ],
  }));

  assert.deepEqual(plan.canonicalMediaIds, ["media-a", "media-b", "media-c"]);
});

test("normalizes the canonical capture.ingest continuation envelope", () => {
  const readback = normalizeCaptureReadback({
    capture: { capture_id: "capture-canonical" },
    content_intent: "IMAGE_ARTICLE",
    article: { post_id: 711 },
    per_media_disposition: [{ media_id: "media-one", status: "APPLIED" }],
    canonical_usage_readback: [{ media_id: "media-one", endpoint_type: "wp_post", active: true }],
  });

  assert.equal(readback.capture_id, "capture-canonical");
  assert.doesNotThrow(() => assertCaptureArticleReadback(readback, ["media-one"]));
});

test("fails closed when the canonical capture identity is missing", () => {
  assert.throws(() => normalizeCaptureReadback({ content_intent: "IMAGE_ARTICLE" }), /CAPTURE_READBACK_UNAVAILABLE/);
});

test("rejects an Article capture readback without per-media canonical Article usages", () => {
  assert.throws(() => assertCaptureArticleReadback({
    capture_id: "capture-article-media",
    capture_status: "COMPLETE",
    content_intent: "IMAGE_ARTICLE",
    article: { post_id: 711 },
    per_media_disposition: [
      { media_id: "media-717", status: "APPLIED" },
      { media_id: "media-718", status: "APPLIED" },
      { media_id: "media-719", status: "APPLIED" },
    ],
    canonical_usage_readback: [],
  }, ["media-717", "media-718", "media-719"]), /ARTICLE_MEDIA_USAGE_READBACK_INCOMPLETE/);
});

test("requires nhk.media.get to read back the Article usage for each Media", () => {
  assert.doesNotThrow(() => assertMediaArticleReadback({ structuredContent: {
    id: "media-one",
    usages: [{ media_id: "media-one", target_type: "wp_post", target_id: "7:711", active: true }],
  } }, "media-one", 711));
  assert.throws(() => assertMediaArticleReadback({ structuredContent: {
    id: "media-one",
    usages: [],
  } }, "media-one", 711), /ARTICLE_MEDIA_USAGE_READBACK_INCOMPLETE/);
});

test("treats MEDIA_ENRICHMENT as durable Media completion without an Article", () => {
  const readback = normalizeCaptureReadback({
    capture_id: "capture-media-only",
    content_intent: { intent: "MEDIA_ENRICHMENT", article_required: false },
    article: null,
  });

  assert.equal(captureRequiresArticleReadback(readback), false);
  assert.doesNotThrow(() => assertCaptureArticleReadback(readback, ["media-one"]));
});

test("does not parse the tool result that opened the widget as an upload result", () => {
  assert.equal(shouldProcessToolResultNotification("open"), false);
  assert.equal(shouldProcessToolResultNotification(null), false);
  assert.equal(shouldProcessToolResultNotification("widget-upload"), true);
  assert.equal(shouldProcessToolResultNotification("capture"), true);
});

test("normalizes ChatGPT library selections as authorized file references", () => {
  assert.deepEqual(normalizeSelectedFiles([
    { fileId: "file-one", fileName: "one.png", mimeType: "image/png" },
    { fileId: "file-two", fileName: "two.webp", mimeType: "image/webp" },
  ]), [
    { kind: "library", clientFileId: "file-one", fileId: "file-one", fileName: "one.png", mimeType: "image/png", name: "one.png", feature: "" },
    { kind: "library", clientFileId: "file-two", fileId: "file-two", fileName: "two.webp", mimeType: "image/webp", name: "two.webp", feature: "" },
  ]);
});

test("keeps each asset name and feature requests structured and ordered", () => {
  assert.deepEqual(splitFeatureRequests("Mặt trước, Odo 24\nOdo 1962"), ["Mặt trước", "Odo 24", "Odo 1962"]);
  assert.deepEqual(buildCaptureAssetInputs([
    { kind: "library", clientFileId: "a", fileId: "a", fileName: "a.jpg", mimeType: "image/jpeg", name: "Mặt trước", feature: "Odo 24, Odo 1962" },
    { kind: "library", clientFileId: "b", fileId: "b", fileName: "b.jpg", mimeType: "image/jpeg", name: "Mặt sau", feature: "Odo 24" },
  ]), [
    { client_file_id: "a", ordinal: 0, name: "Mặt trước", feature_requests: ["Odo 24", "Odo 1962"] },
    { client_file_id: "b", ordinal: 1, name: "Mặt sau", feature_requests: ["Odo 24"] },
  ]);
});

test("maps one widget-upload result without exposing its signed URL", () => {
  const result = extractUploads({
    structuredContent: {
      uploads: [{
        attachment_id: 41,
        media_id: "media-one",
        public_filename: "one.webp",
        file_id: "file-one",
        download_url: "https://files.openai.test/signed/one",
      }],
    },
  });

  assert.deepEqual(result, [{
    attachment_id: 41,
    media_id: "media-one",
    public_filename: "one.webp",
    status: "SUCCESS",
    file_id: "file-one",
  }]);
  assert.deepEqual(buildWidgetState(result, [{
    stage: "MEDIA_READBACK_DONE",
    status: "DONE",
    code: "MEDIA_READBACK_VERIFIED",
    uri: "ui://nhk/image-upload/v3.html",
    tool: "nhk.media.widget-upload",
    }]), {
    modelContent: {
      uploaded_media: [{ attachment_id: 41, media_id: "media-one", public_filename: "one.webp", status: "SUCCESS" }],
      batch_context: {
        ordered_media_ids: ["media-one"],
        items: [{ position: 1, media_id: "media-one", attachment_id: 41, status: "SUCCESS" }],
        user_context: "",
        media_commit_status: "NOT_RUN",
        enrichment_status: "NOT_RUN",
      },
    },
    privateContent: {
      upload_status: "complete",
      media_commit_status: "NOT_RUN",
      enrichment_status: "NOT_RUN",
      diagnostics: [{
        stage: "MEDIA_READBACK_DONE",
        status: "DONE",
        code: "MEDIA_READBACK_VERIFIED",
        uri: "ui://nhk/image-upload/v3.html",
        tool: "nhk.media.widget-upload",
      }],
    },
    imageIds: ["file-one"],
  });
  assert.equal(JSON.stringify(buildWidgetState(result)).includes("download"), false);
});

test("maps the server result envelope returned by the Ability bridge", () => {
  const result = extractUploads({
    result: {
      structuredContent: {
        uploads: [{
          attachment_id: 42,
          media_id: "media-envelope",
          public_filename: "envelope.webp",
          file_id: "file-envelope",
        }],
      },
    },
  });

  assert.deepEqual(result, [{
    attachment_id: 42,
    media_id: "media-envelope",
    public_filename: "envelope.webp",
    status: "SUCCESS",
    file_id: "file-envelope",
  }]);
});

test("maps an Ability envelope whose result only contains content JSON", () => {
  const result = extractUploadManifest({
    result: {
      isError: false,
      content: [{ type: "text", text: JSON.stringify({
        requested_count: 1,
        success_count: 1,
        failure_count: 0,
        items: [{
          ordinal: 0,
          status: "success",
          attachment_id: 44,
          media_id: "media-content-envelope",
          filename: "content-envelope.webp",
          canonical_url: "/anh/content-envelope.webp",
          attachment_readback_status: "verified",
          mime_type: "image/webp",
          byte_size: 120,
          width: 1200,
          height: 900,
        }],
      }) }],
    },
  });

  assert.equal(result.items[0].media_id, "media-content-envelope");
  assert.equal(result.items[0].status, "SUCCESS");
  assertUploadManifestCount(result, 1);
});

test("unwraps a documentation checkpoint from the Ability result envelope", () => {
  assert.deepEqual(extractPayload({
    result: {
      structuredContent: {
        manifest_hash: "manifest-hash",
        documentation_version: "documentation-version",
      },
    },
  }), {
    manifest_hash: "manifest-hash",
    documentation_version: "documentation-version",
  });
});

test("keeps diagnostic state typed and never persists signed URLs or raw errors", () => {
  const state = buildWidgetState([], [{
    stage: "ERROR",
    status: "ERROR",
    code: "SERVER_TOOL_ERROR",
    error: "https://files.openai.test/signed/secret?token=redacted",
    uri: "ui://nhk/image-upload/v3.html",
    tool: "nhk.media.widget-upload",
  }]);

  assert.equal(JSON.stringify(state).includes("files.openai.test"), false);
  assert.equal(JSON.stringify(state).includes("secret"), false);
  assert.equal(state.privateContent.diagnostics[0].error, undefined);
});

test("preserves multi-image upload order in rendered and persisted mapping", () => {
  const result = extractUploads({
    content: [{ type: "text", text: JSON.stringify({ uploads: [
      { attachment_id: 41, media_id: "media-one", public_filename: "one.webp", file_id: "file-one" },
      { attachment_id: 42, media_id: "media-two", public_filename: "two.webp", file_id: "file-two" },
    ] }) }],
  });

  assert.deepEqual(result.map((item) => item.media_id), ["media-one", "media-two"]);
  assert.deepEqual(buildWidgetState(result).imageIds, ["file-one", "file-two"]);
});

test("preserves durable batch context and excludes transport URLs", () => {
  const manifest = extractUploadManifest({ structuredContent: {
    batch_id: "batch-one",
    user_context: "máy, mặt trước, logo",
    status: "success",
    requested_count: 2,
    success_count: 2,
    failure_count: 0,
    items: [
      { ordinal: 0, status: "success", media_id: "media-one", attachment_id: 41 },
      { ordinal: 1, status: "success", media_id: "media-two", attachment_id: 42, download_url: "https://signed.invalid/x" },
    ],
  } });
  const state = buildWidgetState(manifest.items, [], "complete", manifest);
  assert.deepEqual(state.modelContent.batch_context, {
    batch_id: "batch-one",
    ordered_media_ids: ["media-one", "media-two"],
    items: [
      { position: 1, media_id: "media-one", attachment_id: 41, status: "SUCCESS" },
      { position: 2, media_id: "media-two", attachment_id: 42, status: "SUCCESS" },
    ],
    user_context: "máy, mặt trước, logo",
    media_commit_status: "COMPLETE",
    enrichment_status: "NOT_RUN",
  });
  assert.equal(JSON.stringify(state).includes("signed.invalid"), false);
});

test("retries only failed children while retaining successful batch items", () => {
  const merged = mergeUploadManifest({
    batch_id: "batch-retry",
    user_context: "máy ảnh",
    status: "partial_success",
    requested_count: 3,
    success_count: 2,
    failure_count: 1,
    items: [
      { ordinal: 0, status: "SUCCESS", media_id: "media-one", attachment_id: 41 },
      { ordinal: 1, status: "FAILED", error_code: "PROVIDED_FILE_HTTP_STATUS" },
      { ordinal: 2, status: "SUCCESS", media_id: "media-three", attachment_id: 43 },
    ],
  }, {
    status: "success",
    requested_count: 1,
    success_count: 1,
    failure_count: 0,
    items: [{ ordinal: 1, status: "SUCCESS", media_id: "media-two", attachment_id: 42 }],
    user_context: "máy ảnh",
  }, [1]);

  assert.equal(merged.status, "success");
  assert.deepEqual(merged.items.map((item) => item.media_id), ["media-one", "media-two", "media-three"]);
  assert.deepEqual(merged.items.map((item) => item.ordinal), [0, 1, 2]);
});

test("merges a partial retry by explicit returned ordinal rather than subset position", () => {
  const merged = mergeUploadManifest({
    status: "partial_success",
    requested_count: 3,
    success_count: 1,
    failure_count: 2,
    items: [
      { ordinal: 0, status: "SUCCESS", media_id: "media-zero" },
      { ordinal: 1, status: "FAILED", error_code: "HOST_FILE_UPLOAD_FAILED" },
      { ordinal: 2, status: "FAILED", error_code: "HOST_FILE_UPLOAD_FAILED" },
    ],
  }, {
    status: "partial_success",
    requested_count: 2,
    success_count: 1,
    failure_count: 1,
    items: [
      { ordinal: 2, status: "SUCCESS", media_id: "media-two" },
      { ordinal: 1, status: "FAILED", error_code: "HOST_FILE_UPLOAD_FAILED" },
    ],
  }, [1, 2]);

  assert.deepEqual(merged.items.map((item) => item.media_id), ["media-zero", undefined, "media-two"]);
  assert.deepEqual(merged.items.map((item) => item.ordinal), [0, 1, 2]);
});

test("preserves a typed error from a nested Ability result envelope", () => {
  const result = {
    result: {
      isError: true,
      structuredContent: { error: { code: "CHATGPT_FILE_HOST_NOT_ALLOWED", message: "blocked" } },
    },
  };

  assert.deepEqual(inspectToolResult(result), {
    kind: "error",
    code: "CHATGPT_FILE_HOST_NOT_ALLOWED",
  });
  assert.throws(() => extractUploadManifest(result), /CHATGPT_FILE_HOST_NOT_ALLOWED/);
});

test("rejects a malformed upload result before cardinality validation", () => {
  assert.deepEqual(inspectToolResult({ content: [{ type: "text", text: "not-json" }] }), {
    kind: "malformed",
    code: "SERVER_TOOL_RESULT_INVALID",
  });
  assert.throws(() => extractUploadManifest({ content: [{ type: "text", text: "not-json" }] }), /SERVER_TOOL_RESULT_INVALID/);
});

test("maps the authoritative per-item manifest by ordinal without a transport file id", () => {
  const manifest = extractUploadManifest({
    structuredContent: {
      requested_count: 1,
      success_count: 1,
      failure_count: 0,
      items: [{
        ordinal: 0,
        status: "SUCCESS",
        attachment_id: 43,
        media_id: "media-ordinal",
        public_filename: "ordinal.webp",
        canonical_url: "/anh/ordinal.webp",
        attachment_readback_status: "verified",
        mime: "image/webp",
        filesize: 100,
        width: 100,
        height: 80,
      }],
    },
  });

  assert.equal(manifest.items[0].media_id, "media-ordinal");
  assert.equal(manifest.items[0].file_id, undefined);
  assertUploadManifestCount(manifest, 1);
});

test("uses the typed server code instead of a misleading readback count mismatch", () => {
  assert.throws(() => extractUploadManifest({
    isError: true,
    structuredContent: { error: { code: "TRUSTED_FILE_HOST_NOT_ALLOWED" } },
}), /TRUSTED_FILE_HOST_NOT_ALLOWED/);
});

test("top-level server errors win over count validation", () => {
  assert.throws(() => extractUploadManifest({
    isError: true,
    structuredContent: { requested_count: 1, success_count: 0, failure_count: 1, items: [] },
    content: [{ type: "text", text: "PROVIDED_FILE_CONNECT_FAILED" }],
  }), /PROVIDED_FILE_CONNECT_FAILED/);
});

test("nested result.isError wins over an apparent empty success payload", () => {
  assert.throws(() => extractUploadManifest({
    result: { isError: true, structuredContent: { error: { code: "PROVIDED_FILE_HTTP_STATUS" } } },
  }), /PROVIDED_FILE_HTTP_STATUS/);
});

test("Error TextContent is an application error, never an empty manifest", () => {
  assert.deepEqual(inspectToolResult({ content: [{ type: "text", text: "Error: The provided file could not be materialized." }] }), {
    kind: "error",
    code: "SERVER_TOOL_ERROR",
  });
  assert.throws(() => extractUploadManifest({ content: [{ type: "text", text: "Error: The provided file could not be materialized." }] }), /SERVER_TOOL_ERROR/);
});

test("typed failure manifests stop before readback count validation", () => {
  const result = {
    structuredContent: {
      status: "error",
      requested_count: 1,
      success_count: 0,
      failure_count: 1,
      items: [{ ordinal: 0, status: "error", error: { code: "PROVIDED_FILE_IMAGE_DECODE_FAILED" } }],
    },
  };
  assert.deepEqual(inspectToolResult(result), { kind: "error", code: "PROVIDED_FILE_IMAGE_DECODE_FAILED" });
  assert.throws(() => extractUploadManifest(result), /PROVIDED_FILE_IMAGE_DECODE_FAILED/);
});

test("malformed success status is SERVER_TOOL_RESULT_INVALID", () => {
  assert.throws(() => extractUploadManifest({ structuredContent: {
    status: "successfully",
    requested_count: 1,
    success_count: 1,
    failure_count: 0,
    items: [],
  } }), /SERVER_TOOL_RESULT_INVALID/);
});

test("valid success with zero returned items is a count mismatch", () => {
  const manifest = extractUploadManifest({ structuredContent: {
    status: "success",
    requested_count: 1,
    success_count: 1,
    failure_count: 0,
    items: [],
  } });
  assert.throws(() => assertUploadManifestCount(manifest, 1), /MEDIA_READBACK_COUNT_MISMATCH/);
});

test("valid success with one item passes count validation", () => {
  const manifest = extractUploadManifest({ structuredContent: {
    status: "success",
    requested_count: 1,
    success_count: 1,
    failure_count: 0,
    items: [{ ordinal: 0, status: "success", media_id: "media-one", attachment_id: 1 }],
  } });
  assert.doesNotThrow(() => assertUploadManifestCount(manifest, 1));
});

test("partial success validates declared counts independently of all-success handoff", () => {
  const manifest = extractUploadManifest({ structuredContent: {
    status: "partial_success",
    requested_count: 2,
    success_count: 1,
    failure_count: 1,
    items: [
      { ordinal: 0, status: "success", media_id: "media-one", attachment_id: 1 },
      { ordinal: 1, status: "error", error: { code: "PROVIDED_FILE_HTTP_STATUS" } },
    ],
  } });
  assert.doesNotThrow(() => assertUploadManifestCounts(manifest));
  assert.throws(() => assertUploadManifestCount(manifest, 2), /MEDIA_READBACK_COUNT_MISMATCH/);
});

test("JSON error text preserves the typed code and strips signed URLs from widget state", () => {
  const signed = "https://oaiusercontent.test/blob?sig=secret-token";
  const result = { content: [{ type: "text", text: `Error: PROVIDED_FILE_HTTP_STATUS ${signed}` }] };
  assert.deepEqual(inspectToolResult(result), { kind: "error", code: "PROVIDED_FILE_HTTP_STATUS" });
  const state = buildWidgetState([], [{ stage: "ERROR", status: "ERROR", code: "PROVIDED_FILE_HTTP_STATUS", error: signed }]);
  assert.equal(JSON.stringify(state).includes("secret-token"), false);
  assert.equal(JSON.stringify(state).includes("oaiusercontent.test"), false);
});

test("wrapped error property wins over an apparent success manifest", () => {
  const result = {
    result: {
      structuredContent: { status: "success", requested_count: 1, success_count: 1, failure_count: 0, items: [] },
      error: { code: "PROVIDED_FILE_TLS_FAILED" },
    },
  };
  assert.deepEqual(inspectToolResult(result), { kind: "error", code: "PROVIDED_FILE_TLS_FAILED" });
  assert.throws(() => extractUploadManifest(result), /PROVIDED_FILE_TLS_FAILED/);
});

test("a typed error manifest never reaches count mismatch", () => {
  const result = {
    structuredContent: {
      status: "error",
      requested_count: 1,
      success_count: 0,
      failure_count: 1,
      items: [],
    },
  };
  assert.deepEqual(inspectToolResult(result), { kind: "error", code: "SERVER_TOOL_ERROR" });
  assert.throws(() => extractUploadManifest(result), /SERVER_TOOL_ERROR/);
});
