import assert from "node:assert/strict";
import { test } from "node:test";
import { buildWidgetState, extractUploads } from "../src/contract.ts";

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
    file_id: "file-one",
    download_url: "https://files.openai.test/signed/one",
  }]);
  assert.deepEqual(buildWidgetState(result), {
    modelContent: { uploaded_media: [{ attachment_id: 41, media_id: "media-one", public_filename: "one.webp", status: "uploaded" }] },
    privateContent: { upload_status: "complete" },
    imageIds: ["file-one"],
  });
  assert.equal(JSON.stringify(buildWidgetState(result)).includes("download"), false);
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
