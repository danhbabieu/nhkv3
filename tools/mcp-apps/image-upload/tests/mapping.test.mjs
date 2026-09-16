import assert from "node:assert/strict";
import { test } from "node:test";
import { assertUploadManifestCount, buildWidgetState, extractPayload, extractUploadManifest, extractUploads, inspectToolResult, normalizeSelectedFiles } from "../src/contract.ts";

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
    code: "MCP_RESULT_MALFORMED",
  });
  assert.throws(() => extractUploadManifest({ content: [{ type: "text", text: "not-json" }] }), /MCP_RESULT_MALFORMED/);
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
