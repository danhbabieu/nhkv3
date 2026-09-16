import assert from "node:assert/strict";
import { test } from "node:test";
import { assertUploadManifestCount, assertUploadManifestCounts, buildWidgetState, extractPayload, extractUploadManifest, extractUploads, inspectToolResult, normalizeSelectedFiles, shouldProcessToolResultNotification } from "../src/contract.ts";

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
    { kind: "library", fileId: "file-one", fileName: "one.png", mimeType: "image/png" },
    { kind: "library", fileId: "file-two", fileName: "two.webp", mimeType: "image/webp" },
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
    uri: "ui://nhk/image-upload.html",
    tool: "nhk.media.widget-upload",
  }]), {
    modelContent: { uploaded_media: [{ attachment_id: 41, media_id: "media-one", public_filename: "one.webp", status: "SUCCESS" }] },
    privateContent: {
      upload_status: "complete",
      diagnostics: [{
        stage: "MEDIA_READBACK_DONE",
        status: "DONE",
        code: "MEDIA_READBACK_VERIFIED",
        uri: "ui://nhk/image-upload.html",
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

test("keeps diagnostic error messages safe and never persists signed URLs", () => {
  const state = buildWidgetState([], [{
    stage: "ERROR",
    status: "ERROR",
    code: "SERVER_TOOL_ERROR",
    error: "https://files.openai.test/signed/secret?token=redacted",
    uri: "ui://nhk/image-upload.html",
    tool: "nhk.media.widget-upload",
  }]);

  assert.equal(JSON.stringify(state).includes("files.openai.test"), false);
  assert.equal(JSON.stringify(state).includes("secret"), false);
  assert.equal(state.privateContent.diagnostics[0].error, "[redacted-url]");
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
