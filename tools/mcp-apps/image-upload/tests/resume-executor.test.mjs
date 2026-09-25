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

for (const phase of ["CONTINUE_ENRICHMENT", "RETRY_PROJECTION"]) {
  test(`executes ${phase} through its supplied downstream handler`, async () => {
    const calls = [];
    const result = await executeResumePlan({
      phase,
      materializeOrdinals: [],
      hostUploadOrdinals: [],
      mediaUploadOrdinals: [],
      canonicalMediaIds: ["media-1"],
      usageMediaIds: [],
      captureAction: "REUSE",
      articleAction: "REUSE",
    }, {
      captureId: "capture-1",
      captureKey: "operation-1:capture",
      callCapture: async () => { throw new Error("unexpected capture fallback"); },
      ...(phase === "CONTINUE_ENRICHMENT"
        ? { continueEnrichment: async () => { calls.push("enrichment"); return { status: "COMPLETE" }; } }
        : { retryProjection: async () => { calls.push("projection"); return { status: "COMPLETE" }; } }),
    });

    assert.deepEqual(calls, [phase === "CONTINUE_ENRICHMENT" ? "enrichment" : "projection"]);
    assert.deepEqual(result, { status: "COMPLETE" });
  });
}

for (const count of [1, 2, 3, 10]) {
  test(`continues durable Media for N=${count} without physical re-upload`, async () => {
    const calls = { host: 0, media: 0, attachment: 0, enrichment: 0 };
    const result = await executeResumePlan({
      phase: "CONTINUE_ENRICHMENT",
      materializeOrdinals: [],
      hostUploadOrdinals: [],
      mediaUploadOrdinals: [],
      canonicalMediaIds: Array.from({ length: count }, (_, index) => `media-${index}`),
      usageMediaIds: [],
      captureAction: "REUSE",
      articleAction: "REUSE",
    }, {
      captureId: "capture-durable",
      captureKey: "submission:capture",
      callCapture: async () => { throw new Error("unexpected capture fallback"); },
      continueEnrichment: async () => { calls.enrichment++; return { status: "COMPLETE" }; },
    });

    assert.deepEqual(result, { status: "COMPLETE" });
    assert.deepEqual(calls, { host: 0, media: 0, attachment: 0, enrichment: 1 });
  });
}
