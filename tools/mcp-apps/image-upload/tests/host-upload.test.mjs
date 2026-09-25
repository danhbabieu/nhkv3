import assert from "node:assert/strict";
import { test } from "node:test";
import { uploadSelectedFiles } from "../src/host-upload.ts";

function file(name, type = "image/jpeg", size = 12) {
  return { name, type, size };
}

test("uploads one image with the legacy host transport sequence", async () => {
  const events = [];
  const result = await uploadSelectedFiles([{ ordinal: 0, file: file("one.jpg") }], {
    uploadFile: async (input) => {
      events.push(`upload:${input.name}`);
      return { fileId: "file-one" };
    },
    getFileDownloadUrl: async ({ fileId }) => {
      events.push(`download:${fileId}`);
      return { downloadUrl: "https://files.example.test/file-one" };
    },
  });

  assert.deepEqual(events, ["upload:one.jpg", "download:file-one"]);
  assert.equal(result[0].status, "HOST_UPLOADED");
  assert.equal(result[0].attempts, 1);
});

test("uploads three files sequentially with max host concurrency one", async () => {
  let active = 0;
  let peak = 0;
  const calls = [];
  const result = await uploadSelectedFiles([
    { ordinal: 0, file: file("one.jpg") },
    { ordinal: 1, file: file("two.jpg") },
    { ordinal: 2, file: file("three.jpg") },
  ], {
    uploadFile: async (input) => {
      active += 1;
      peak = Math.max(peak, active);
      calls.push(input.name);
      await new Promise((resolve) => setTimeout(resolve, 2));
      active -= 1;
      if (input.name === "two.jpg") throw new Error("HOST_TEMPORARY_UNAVAILABLE");
      return { fileId: `file-${input.name}` };
    },
    getFileDownloadUrl: async ({ fileId }) => ({ downloadUrl: `https://files.example.test/${fileId}` }),
  });

  assert.equal(peak, 1);
  assert.deepEqual(calls, ["one.jpg", "two.jpg", "three.jpg"]);
  assert.deepEqual(result.map((item) => item.status), ["HOST_UPLOADED", "FAILED_RETRYABLE", "HOST_UPLOADED"]);
  assert.deepEqual(result.map((item) => item.ordinal), [0, 1, 2]);
  assert.equal(result[1].error.code, "HOST_TEMPORARY_UNAVAILABLE");
});

test("isolates image two failure while images one and three succeed", async () => {
  const result = await uploadSelectedFiles([
    { ordinal: 0, file: file("one.jpg") },
    { ordinal: 1, file: file("two.jpg") },
    { ordinal: 2, file: file("three.jpg") },
  ], {
    uploadFile: async (input) => {
      if (input.name === "two.jpg") throw new Error("HOST_TEMPORARY_UNAVAILABLE");
      return { fileId: `file-${input.name}` };
    },
    getFileDownloadUrl: async ({ fileId }) => ({ downloadUrl: `https://files.example.test/${fileId}` }),
  });

  assert.deepEqual(result.map((item) => item.status), ["HOST_UPLOADED", "FAILED_RETRYABLE", "HOST_UPLOADED"]);
});

test("does not retry a rejected host upload during one execution", async () => {
  const selected = file("retry.jpg", "image/jpeg", 42);
  const seen = [];
  let attempts = 0;
  const result = await uploadSelectedFiles([{ ordinal: 4, file: selected }], {
    uploadFile: async (input) => {
      seen.push(input);
      attempts += 1;
      throw new Error("HOST_NETWORK_ERROR");
    },
    getFileDownloadUrl: async () => ({ downloadUrl: "https://files.example.test/retry" }),
  });

  assert.equal(result[0].status, "FAILED_RETRYABLE");
  assert.equal(attempts, 1);
  assert.deepEqual(seen, [selected]);
  assert.equal(result[0].attempts, 1);
});

test("explicit retry can upload only the previously failed ordinal", async () => {
  const failed = file("two.jpg");
  const uploaded = [];
  const result = await uploadSelectedFiles([{ ordinal: 1, file: failed }], {
    uploadFile: async (input) => {
      uploaded.push(input.name);
      return { fileId: "file-two" };
    },
    getFileDownloadUrl: async () => ({ downloadUrl: "https://files.example.test/two" }),
  });

  assert.deepEqual(uploaded, ["two.jpg"]);
  assert.equal(result[0].ordinal, 1);
  assert.equal(result[0].status, "HOST_UPLOADED");
});

test("does not upload a successful ordinal again when retry input excludes it", async () => {
  let attempts = 0;
  const result = await uploadSelectedFiles([{ ordinal: 1, file: file("two.jpg") }], {
    uploadFile: async () => {
      attempts += 1;
      return { fileId: "file-two" };
    },
    getFileDownloadUrl: async () => ({ downloadUrl: "https://files.example.test/two" }),
  });

  assert.equal(attempts, 1);
  assert.equal(result[0].ordinal, 1);
});

test("rejects malformed host references without calling the server boundary", async () => {
  let downloadCalls = 0;
  const result = await uploadSelectedFiles([{ ordinal: 0, file: file("bad.jpg") }], {
    uploadFile: async () => ({ fileId: "file-bad" }),
    getFileDownloadUrl: async () => {
      downloadCalls += 1;
      return { downloadUrl: "not-a-url" };
    },
    maxRetries: 0,
  });

  assert.equal(downloadCalls, 1);
  assert.equal(result[0].status, "FAILED_RETRYABLE");
  assert.equal(result[0].error.code, "HOST_FILE_UPLOAD_INVALID_REFERENCE");
});
