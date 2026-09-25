import assert from "node:assert/strict";
import { test } from "node:test";
import { executeResumePlan } from "../src/resume-executor.ts";

test("executes REPAIR_MEDIA_USAGE through the canonical Capture retry without media upload", async () => {
  const calls = [];
  const result = await executeResumePlan({
    phase: "REPAIR_MEDIA_USAGE",
    materializeOrdinals: [],
    hostUploadOrdinals: [],
    mediaUploadOrdinals: [],
    canonicalMediaIds: ["media-1", "media-2"],
    usageMediaIds: ["media-2"],
    captureAction: "REUSE",
    articleAction: "REUSE",
  }, {
    captureId: "capture-1",
    captureKey: "operation-1:capture",
    callCapture: async (arguments_) => {
      calls.push(arguments_);
      return { capture_id: "capture-1", capture_status: "COMPLETE" };
    },
  });

  assert.equal(result.capture_id, "capture-1");
  assert.deepEqual(calls, [{
    capture_id: "capture-1",
    idempotency_key: "operation-1:capture",
    resume_mode: "RETRY",
  }]);
});
