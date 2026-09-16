import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const resourcePath = new URL(
  "../../../../public/wp-content/plugins/nhk-core/resources/ui/image-upload.html",
  import.meta.url,
);
const sourcePath = new URL("../src/app.ts", import.meta.url);

async function resource() {
  return readFile(resourcePath, "utf8");
}

async function source() {
  return readFile(sourcePath, "utf8");
}

test("the served resource is a single bundled MCP Apps document", async () => {
  const html = await resource();

  assert.match(html, /<!doctype html>/i);
  assert.match(html, /App/);
  assert.match(html, /\.connect\(\)/);
  assert.doesNotMatch(html, /window\.parent\.postMessage/);
});

test("the View uses the official server-tool call path and live tool name", async () => {
  const html = await resource();
  const view = await source();

  assert.match(html, /callServerTool/);
  assert.match(html, /wp_ability_nhk_v3_media_widget_upload/);
  assert.match(view, /wp_ability_nhk_v3_capture_ingest/);
  assert.doesNotMatch(html, /function\s+callTool/);
  assert.doesNotMatch(html, /openai\.callTool/);
});

test("the final UI exposes exactly the two Vietnamese image actions", async () => {
  const html = await resource();

  assert.match(html, /id="upload"[^>]*>Tải ảnh lên<\/button>/);
  assert.match(html, /id="create"[^>]*>Tạo bài viết<\/button>/);
  assert.doesNotMatch(html, /Dùng ảnh trong chat/);
  assert.doesNotMatch(html, /Tải ảnh đã chọn/);
});

test("the View routes the two actions through one shared ordered Capture handoff", async () => {
  const view = await source();

  assert.match(view, /"MEDIA_ENRICHMENT"/);
  assert.match(view, /"IMAGE_ARTICLE"/);
  assert.match(view, /media_ids:/);
  assert.match(view, /wp_ability_nhk_v3_capture_ingest/);
  assert.doesNotMatch(view, /name:\s*CAPTURE_TOOL_NAME,[\s\S]{0,700}files:/);
});

test("upload controls are gated by the connected lifecycle", async () => {
  const html = await resource();

  assert.match(html, /CONNECTING/);
  assert.match(html, /READY/);
  assert.match(html, /UPLOADING/);
  assert.match(html, /SUCCESS/);
  assert.match(html, /ERROR/);
  assert.match(html, /\.connect\(\)/);
  const view = await source();
  assert.match(view, /await app\.connect\(\)/);
  assert.ok(view.indexOf("app.ontoolresult") < view.indexOf("await app.connect()"));
  assert.match(view, /connected\s*=\s*true/);
});

test("the View keeps signed download URLs out of visible and persisted state", async () => {
  const html = await resource();

  assert.match(html, /setWidgetState/);
  assert.doesNotMatch(html, /download_url[^\n]*setWidgetState/);
  assert.doesNotMatch(html, /downloadUrl[^\n]*setWidgetState/);
});

test("the View requires operator naming context and emits the required diagnostics stages", async () => {
  const html = await resource();
  const view = await source();

  assert.match(html, /id="context"/);
  assert.match(html, /Mô tả ảnh \/ nội dung/);
  for (const stage of [
    "BOOT", "RESOURCE_LOADED", "HOST_CAPABILITIES_READ", "FILE_SELECTED",
    "FILE_PREVIEW_READY", "HOST_FILE_UPLOAD_START", "HOST_FILE_UPLOAD_DONE",
    "TRUSTED_FILE_REF_READY", "SERVER_TOOL_CALL_START", "SERVER_TOOL_CALL_RESULT",
    "ATTACHMENT_READBACK_START", "ATTACHMENT_READBACK_DONE", "MEDIA_READBACK_DONE",
    "READY_FOR_USE", "ERROR",
  ]) assert.match(view, new RegExp(stage));
  assert.match(view, /metadata:\s*\{\s*description:/);
});
