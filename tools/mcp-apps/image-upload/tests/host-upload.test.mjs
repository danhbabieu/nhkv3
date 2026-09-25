import assert from "node:assert/strict";
import { test } from "node:test";
import { uploadSelectedFiles } from "../src/host-upload.ts";

function file(name, type = "image/jpeg", size = 12) {
  return { name, type, size };
}

test("uploads files independently with bounded concurrency and preserves ordinal", async () => {
  let active = 0;
  let peak = 0;
  const calls = [];
  const result = await uploadSelectedFiles([
    { ordinal: 0, file: file("one.jpg") },
    { ordinal: 1, file: file("two.jpg") },
    { ordinal: 2, file: file("three.jpg") },
  ], {
    concurrency: 2,
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
    maxRetries: 0,
  });

  assert.equal(peak, 2);
  assert.deepEqual(calls, ["one.jpg", "two.jpg", "three.jpg"]);
  assert.deepEqual(result.map((item) => item.status), ["HOST_UPLOADED", "FAILED_RETRYABLE", "HOST_UPLOADED"]);
  assert.deepEqual(result.map((item) => item.ordinal), [0, 1, 2]);
  assert.equal(result[1].error.code, "HOST_TEMPORARY_UNAVAILABLE");
});

test("retries a transient host failure and reuses the selected File", async () => {
  const selected = file("retry.jpg", "image/jpeg", 42);
  const seen = [];
  let attempts = 0;
  const result = await uploadSelectedFiles([{ ordinal: 4, file: selected }], {
    uploadFile: async (input) => {
      seen.push(input);
      attempts += 1;
      if (attempts < 3) throw new Error("HOST_NETWORK_ERROR");
      return { fileId: "file-retry" };
    },
    getFileDownloadUrl: async () => ({ downloadUrl: "https://files.example.test/retry" }),
    maxRetries: 2,
  });

  assert.equal(result[0].status, "HOST_UPLOADED");
  assert.equal(attempts, 3);
  assert.deepEqual(seen, [selected, selected, selected]);
  assert.equal(result[0].attempts, 3);
});

test("does not retry a permanent host rejection", async () => {
  let attempts = 0;
  const result = await uploadSelectedFiles([{ ordinal: 0, file: file("blocked.jpg") }], {
    uploadFile: async () => {
      attempts += 1;
      throw new Error("HOST_FILE_UPLOAD_REJECTED");
    },
    getFileDownloadUrl: async () => ({ downloadUrl: "https://files.example.test/blocked" }),
    maxRetries: 2,
  });

  assert.equal(attempts, 1);
  assert.equal(result[0].status, "FAILED_PERMANENT");
  assert.equal(result[0].error.code, "HOST_FILE_UPLOAD_REJECTED");
});

test("classifies a hung host upload as a retryable timeout", async () => {
  const result = await uploadSelectedFiles([{ ordinal: 0, file: file("slow.jpg") }], {
    uploadFile: () => new Promise(() => {}),
    getFileDownloadUrl: async () => ({ downloadUrl: "https://files.example.test/slow" }),
    timeoutMs: 5,
    maxRetries: 0,
  });

  assert.equal(result[0].status, "FAILED_RETRYABLE");
  assert.equal(result[0].error.code, "HOST_FILE_UPLOAD_TIMEOUT");
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
