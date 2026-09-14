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

  assert.match(html, /callServerTool/);
  assert.match(html, /nhk\.media\.widget-upload/);
  assert.doesNotMatch(html, /function\s+callTool/);
  assert.doesNotMatch(html, /openai\.callTool/);
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
