import { App } from "@modelcontextprotocol/ext-apps";
import { assertCaptureArticleReadback, assertMediaArticleReadback, assertUploadManifestCounts, buildCaptureAssetInputs, buildWidgetState, buildWidgetUploadArguments, buildWidgetUploadFailureManifest, captureRequiresArticleReadback, extractUploadManifest, inspectToolResult, mergeUploadManifest, normalizeCaptureReadback, normalizeSelectedFiles, orderUploadedItems, shouldProcessToolResultNotification, type BatchContext, type SelectedImage, type ToolResult, type ToolResultNotificationSource, type UploadedItem, type UploadManifest, type WidgetDiagnostic, type WidgetUploadReference, type WidgetUploadStatus } from "./contract";
import { uploadSelectedFiles, type HostUploadOutcome } from "./host-upload";
import { planSubmissionResume } from "./resume-policy";
import { executeResumePlan } from "./resume-executor";

// Easy MCP exposes the internal/admin boundary under the registered
// WordPress Ability name. callServerTool must use that exact runtime name;
// the canonical NHK name remains the server-side catalog name.
const SERVER_TOOL_NAME = "wp_ability_nhk_v3_media_widget_upload";
const CAPTURE_TOOL_NAME = "wp_ability_nhk_v3_capture_ingest";
const MEDIA_GET_TOOL_NAME = "wp_ability_nhk_v3_media_get";
const DOCUMENTATION_TOOL_NAME = "wp_ability_nhk_v3_documentation_bootstrap";
const RESOURCE_URI = "ui://nhk/image-upload/v3.html";
const IMAGE_TYPES = /^(image\/jpeg|image\/png|image\/gif|image\/webp)$/;
const IMAGE_ACCEPT = ["image/jpeg", "image/png", "image/gif", "image/webp"];
const STATES = ["CONNECTING", "READY", "UPLOADING", "SUCCESS", "PARTIAL", "ERROR"] as const;
type WidgetState = (typeof STATES)[number];
const HOST_UPLOAD_ERROR_MESSAGE = "Chưa tải được ảnh lên hệ thống. Bạn có thể thử lại.";
const MEDIA_SAVED_ERROR_MESSAGE = "Ảnh đã được lưu, nhưng phần tạo bài viết chưa hoàn tất. Có thể thử lại mà không tải lại ảnh.";

type ChatGptFileApi = {
  selectFiles?: () => Promise<unknown>;
  uploadFile?: (file: File, options?: { library?: boolean }) => Promise<{ fileId?: string }>;
  getFileDownloadUrl?: (options: { fileId: string }) => Promise<{ downloadUrl?: string }>;
  setWidgetState?: (state: unknown) => void | Promise<void>;
};

declare global {
  interface Window { openai?: ChatGptFileApi; }
}

function byId<T extends HTMLElement>(id: string): T {
  const element = document.getElementById(id);
  if (!element) throw new Error(`Missing widget element: ${id}`);
  return element as T;
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

function safeErrorMessage(error: unknown): string {
  return errorMessage(error).replace(/https?:\/\/[^\s)]+/gi, "[redacted-url]");
}

function diagnosticCode(error: unknown): string {
  const match = errorMessage(error).match(/^[A-Z][A-Z0-9_]{2,}/);
  return match?.[0] ?? "UPLOAD_FAILED";
}

function downstreamCode(error: unknown, phase: string): string {
  const code = diagnosticCode(error);
  if (code !== "UPLOAD_FAILED") return code;
  if (phase === "CAPTURE") return "CAPTURE_FAILED";
  if (phase === "ARTICLE_MEDIA_USAGE") return "MEDIA_USAGE_INCOMPLETE";
  if (phase === "PROJECTION") return "PROJECTION_FAILED";
  return code;
}

function asFiles(value: File[] | FileList | null | undefined): File[] {
  return Array.from(value ?? []);
}

function createIdempotencyKey(): string {
  return `chatgpt-widget-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function showBootstrapError(error: unknown): void {
  const state = document.getElementById("state");
  const status = document.getElementById("status");
  const diagnostics = document.getElementById("diagnostics");
  if (state) state.textContent = "ERROR";
  if (status) {
    status.dataset.state = "ERROR";
    status.className = "failure";
    status.textContent = "Không thể khởi tạo trình tải ảnh NHK. Bạn có thể thử lại.";
  }
  if (diagnostics) {
    const row = document.createElement("div");
    row.textContent = `ERROR · ERROR · BOOTSTRAP_INIT_FAILED · ${safeErrorMessage(error)}`;
    diagnostics.append(row);
  }
}

async function boot(): Promise<void> {
  try {
    await start();
  } catch (error: unknown) {
    showBootstrapError(error);
  }
}

async function start(): Promise<void> {
  const input = byId<HTMLInputElement>("files");
  const select = byId<HTMLButtonElement>("select");
  const upload = byId<HTMLButtonElement>("upload");
  const summary = byId<HTMLDivElement>("summary");
  const previews = byId<HTMLDivElement>("previews");
  const state = byId<HTMLSpanElement>("state");
  const status = byId<HTMLDivElement>("status");
  const results = byId<HTMLDivElement>("results");
  const context = byId<HTMLTextAreaElement>("description");
  const diagnosticsView = byId<HTMLDivElement>("diagnostics");
  const host = window.openai;
  const app = new App({ name: "NHK Image Upload", version: "1.0.0" });
  let connected = false;
  let uploading = false;
  let selected: SelectedImage[] = [];
  let uploaded: UploadedItem[] = [];
  let batchManifest: UploadManifest | null = null;
  let enrichmentStatus: BatchContext["enrichment_status"] = "NOT_RUN";
  let diagnostics: WidgetDiagnostic[] = [];
  let uploadStatus: WidgetUploadStatus = "idle";
  let retryOperationKey: string | null = null;
  let retryAttempt = 0;
  let captureReadback: ReturnType<typeof assertCaptureArticleReadback> | null = null;
  let submissionId: string | null = null;
  let attemptId: string | null = null;
  let phase = "IDLE";

  function renderDiagnostics(): void {
    diagnosticsView.replaceChildren();
    diagnostics.slice(-12).forEach((diagnostic) => {
      const row = document.createElement("div");
      const attempt = diagnostic.attempt_number === undefined ? "" : `Lần ${diagnostic.attempt_number}`;
      row.textContent = [attempt, diagnostic.stage, diagnostic.status, diagnostic.code].filter(Boolean).join(" · ");
      diagnosticsView.append(row);
    });
  }

  function recordDiagnostic(stage: string, statusValue: WidgetDiagnostic["status"], code: string, error?: unknown, context: Partial<WidgetDiagnostic> = {}): void {
    void error;
    diagnostics.push({
      stage,
      status: statusValue,
      code,
      ...context,
      uri: RESOURCE_URI,
      tool: SERVER_TOOL_NAME,
      ...(submissionId ? { submission_id: submissionId } : {}),
      ...(attemptId ? { attempt_id: attemptId } : {}),
      phase,
    });
    renderDiagnostics();
    if (connected) saveWidgetState();
  }

  function setState(next: WidgetState, message: string): void {
    state.textContent = next;
    status.dataset.state = next;
    status.className = next === "ERROR" || next === "PARTIAL" ? "failure" : next === "SUCCESS" ? "success" : "";
    status.textContent = message;
  }

  function supportsFileUpload(): boolean {
    return typeof host?.uploadFile === "function" && typeof host.getFileDownloadUrl === "function";
  }

  function renderSelection(): void {
    previews.replaceChildren();
    selected.forEach((item, ordinal) => {
      const file = item.kind === "local" ? item.file : null;
      const fileName = item.kind === "local" ? item.file.name : item.fileName;
      const figure = document.createElement("figure");
      figure.dataset.clientFileId = item.clientFileId;
      if (file) {
        const image = document.createElement("img");
        image.alt = item.name || file.name;
        image.src = URL.createObjectURL(file);
        figure.append(image);
      }
      const label = document.createElement("div");
      label.className = "asset-label";
      label.textContent = `Ảnh ${ordinal + 1}`;
      figure.append(label);
      const name = document.createElement("input");
      name.type = "text";
      name.className = "asset-name";
      name.placeholder = "Tên ảnh";
      name.value = item.name;
      name.required = true;
      name.dataset.field = "name";
      figure.append(name);
      const feature = document.createElement("input");
      feature.type = "text";
      feature.className = "asset-feature";
      feature.placeholder = "Feature (nhiều mục cách nhau bằng dấu phẩy)";
      feature.value = item.feature;
      feature.dataset.field = "feature";
      figure.append(feature);
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "remove-asset";
      remove.dataset.clientFileId = item.clientFileId;
      remove.textContent = "Bỏ ảnh này";
      figure.append(remove);
      const caption = document.createElement("figcaption");
      caption.textContent = file ? fileName : `${fileName} (Thư viện ChatGPT)`;
      figure.append(caption);
      previews.append(figure);
    });
    summary.textContent = selected.length ? `${selected.length} ảnh đã chọn.` : "Chưa chọn ảnh.";
    const disabled = !connected || uploading || selected.length === 0 || !supportsFileUpload();
    upload.disabled = disabled;
  }

  function renderUploads(items: UploadedItem[]): void {
    results.replaceChildren();
    items.forEach((item) => {
      const row = document.createElement("div");
      row.className = "success";
      const filename = item.public_filename || item.original_filename || "(unnamed image)";
      row.textContent = `${filename} · ${item.status || "đã tải"}`;
      results.append(row);
    });
  }

  function saveWidgetState(): void {
    if (!connected || typeof host?.setWidgetState !== "function") return;
    void host.setWidgetState(buildWidgetState(uploaded, diagnostics, uploadStatus, batchManifest, enrichmentStatus));
  }

  function publishBatchContext(): void {
    const state = buildWidgetState(uploaded, diagnostics, uploadStatus, batchManifest, enrichmentStatus);
    if (connected && app.getHostCapabilities()?.updateModelContext) {
      void app.updateModelContext({ structuredContent: state.modelContent.batch_context });
    }
    saveWidgetState();
  }

  function handleToolResult(result: ToolResult, expectedCount?: number, source: ToolResultNotificationSource = "widget-upload"): UploadedItem[] {
    if (!shouldProcessToolResultNotification(source)) return [];
    const manifest = extractUploadManifest(result);
    if (expectedCount !== undefined && manifest.requested_count !== expectedCount) throw new Error("MEDIA_READBACK_COUNT_MISMATCH");
    uploaded = manifest.items;
    renderUploads(uploaded);
    return uploaded;
  }

  function checkpointFrom(result: ToolResult): { manifest_hash: string; documentation_version: string } {
    const inspection = inspectToolResult(result);
    if (inspection.kind !== "success") throw new Error(inspection.code);
    const payload = inspection.payload;
    if (!payload || typeof payload !== "object") throw new Error("DOCUMENTATION_CHECKPOINT_UNAVAILABLE");
    const value = payload as { manifest_hash?: unknown; documentation_version?: unknown };
    if (typeof value.manifest_hash !== "string" || typeof value.documentation_version !== "string") throw new Error("DOCUMENTATION_CHECKPOINT_UNAVAILABLE");
    return { manifest_hash: value.manifest_hash, documentation_version: value.documentation_version };
  }

  function assertCaptureResult(result: ToolResult): ReturnType<typeof assertCaptureArticleReadback> {
    const inspection = inspectToolResult(result);
    if (inspection.kind !== "success") throw new Error(inspection.code);
    return assertCaptureArticleReadback(inspection.payload, orderUploadedItems(uploaded).map((item) => item.media_id).filter((id): id is string => Boolean(id)));
  }

  async function assertArticleMediaReadback(capture: ReturnType<typeof assertCaptureArticleReadback>): Promise<void> {
    if (!captureRequiresArticleReadback(capture)) return;
    const articleId = Number(capture.article?.post_id ?? capture.article_id ?? 0);
    if (!(articleId > 0)) throw new Error("ARTICLE_READBACK_UNAVAILABLE");
    for (const mediaId of orderUploadedItems(uploaded).map((item) => item.media_id).filter((id): id is string => Boolean(id))) {
      recordDiagnostic("ARTICLE_MEDIA_READBACK_START", "START", "MEDIA_GET_READBACK_REQUESTED");
      const result = await app.callServerTool({ name: MEDIA_GET_TOOL_NAME, arguments: { id: mediaId } });
      assertMediaArticleReadback(result as ToolResult, mediaId, articleId);
      recordDiagnostic("ARTICLE_MEDIA_READBACK_DONE", "DONE", "MEDIA_GET_ARTICLE_USAGE_VERIFIED");
    }
  }

  function captureResumeInput(): Parameters<typeof planSubmissionResume>[0] {
    const capture = captureReadback;
    const usages = capture?.canonical_usage_readback ?? [];
    return {
      media_commit_status: "COMPLETE",
      enrichment_status: enrichmentStatus,
      intent: typeof capture?.content_intent === "string" ? capture.content_intent : capture?.content_intent?.intent,
      items: orderUploadedItems(uploaded),
      capture: { id: capture?.capture_id, exists: Boolean(capture?.capture_id) },
      article: { id: capture?.article?.post_id ?? capture?.article_id ?? undefined, exists: Boolean(capture?.article?.post_id ?? capture?.article_id) },
      media_usages: usages.map((usage) => ({ media_id: usage.media_id, complete: usage.active !== false, status: usage.active === false ? "RETIRED" : "COMPLETE" })),
    };
  }

  async function retryCaptureConvergence(): Promise<void> {
    if (!captureReadback?.capture_id || !retryOperationKey) throw new Error("CAPTURE_RETRY_CONTEXT_UNAVAILABLE");
    const plan = planSubmissionResume(captureResumeInput());
    phase = plan.phase === "REPAIR_MEDIA_USAGE" ? "ARTICLE_MEDIA_USAGE" : plan.phase === "RETRY_PROJECTION" ? "PROJECTION" : "CAPTURE";
    recordDiagnostic("RESUME_PLAN", "DONE", `RESUME_${plan.phase}`);
    const result = await executeResumePlan(plan, {
      captureId: captureReadback.capture_id,
      captureKey: `${retryOperationKey}:capture`,
      callCapture: async (arguments_) => {
        const docs = await app.callServerTool({ name: DOCUMENTATION_TOOL_NAME, arguments: {} });
        const checkpoint = checkpointFrom(docs as ToolResult);
        return app.callServerTool({ name: CAPTURE_TOOL_NAME, arguments: { ...arguments_, documentation_checkpoint: checkpoint } });
      },
    });
    if (result === null) return;
    captureReadback = assertCaptureResult(result as ToolResult);
    await assertArticleMediaReadback(captureReadback);
  }

  async function materializeSelectedImages(operationKey: string, namingContext: string, attempt: number): Promise<UploadManifest> {
    phase = "MATERIALIZE_MEDIA";
    const priorItems = batchManifest?.items ?? [];
    const resumePlan = planSubmissionResume({
      media_commit_status: batchManifest === null ? "NOT_RUN" : batchManifest.failure_count > 0 ? "PARTIAL" : "COMPLETE",
      enrichment_status: enrichmentStatus,
      items: priorItems,
    });
    if (batchManifest !== null && resumePlan.phase !== "MATERIALIZE_MEDIA") {
      uploaded = orderUploadedItems(batchManifest.items);
      return batchManifest;
    }
    const retryingPartialBatch = resumePlan.materializeOrdinals.length > 0 && batchManifest?.status === "partial_success";
    const sourceOrdinals = resumePlan.mediaUploadOrdinals.length > 0
      ? resumePlan.mediaUploadOrdinals
      : selected.map((_item, index) => index);
    const hostUploadOrdinals = new Set(
      resumePlan.hostUploadOrdinals.length > 0
        ? resumePlan.hostUploadOrdinals
        : sourceOrdinals,
    );
    if (batchManifest !== null && sourceOrdinals.length === 0) return batchManifest;
    const outcomes = await uploadSelectedFiles(sourceOrdinals.map((index) => {
      const item = selected[index];
      const previous = priorItems.find((candidate) => candidate.ordinal === index);
      if (!hostUploadOrdinals.has(index) && previous?.file_id) {
        return { ordinal: index, file: null, fileId: previous.file_id, fileName: previous.original_filename ?? previous.public_filename, mimeType: previous.mime };
      }
      return item.kind === "local"
        ? { ordinal: index, file: item.file }
        : { ordinal: index, file: null, fileId: item.fileId, fileName: item.fileName, mimeType: item.mimeType };
    }), {
      uploadFile: host!.uploadFile!,
      getFileDownloadUrl: host!.getFileDownloadUrl!,
      onAttempt: (input, number) => {
        const item = selected[input.ordinal];
        const fileName = item.kind === "local" ? item.file.name : item.fileName;
        recordDiagnostic("HOST_FILE_UPLOAD_START", "START", "HOST_FILE_UPLOAD_REQUESTED", undefined, { file_ordinal: input.ordinal, mime_type: item.kind === "local" ? item.file.type : item.mimeType, byte_size: item.kind === "local" ? item.file.size : undefined, attempt_number: number });
        setState("UPLOADING", `Đang tải ${fileName}…`);
      },
    });
    const references: WidgetUploadReference[] = [];
    const hostFailures: UploadedItem[] = [];
    outcomes.forEach((outcome: HostUploadOutcome) => {
      const item = selected[outcome.ordinal];
      const diagnosticContext = { file_ordinal: outcome.ordinal, mime_type: outcome.mimeType, byte_size: outcome.file?.size, attempt_number: outcome.attempts };
      if (outcome.status === "HOST_UPLOADED") {
        recordDiagnostic("HOST_FILE_UPLOAD_DONE", "DONE", "HOST_FILE_UPLOAD_VERIFIED", undefined, diagnosticContext);
        references.push({ download_url: outcome.downloadUrl, file_id: outcome.fileId, mime_type: outcome.mimeType, file_name: outcome.fileName, ordinal: outcome.ordinal, media: { title: item.name.trim() } });
        recordDiagnostic("TRUSTED_FILE_REF_READY", "DONE", "TRUSTED_FILE_REFERENCE_READY", undefined, diagnosticContext);
      } else {
        recordDiagnostic("HOST_FILE_UPLOAD_FAILED", "ERROR", outcome.error.code, undefined, diagnosticContext);
        hostFailures.push({ ordinal: outcome.ordinal, status: outcome.status, error_code: outcome.error.code, original_filename: outcome.fileName, mime: outcome.mimeType, filesize: outcome.file?.size });
      }
    });

    let manifest: UploadManifest = { status: "success", requested_count: references.length, success_count: 0, failure_count: 0, items: [] };
    if (references.length > 0) {
      recordDiagnostic("SERVER_TOOL_CALL_START", "START", "SERVER_TOOL_CALL_REQUESTED");
      const result = await app.callServerTool({ name: SERVER_TOOL_NAME, arguments: buildWidgetUploadArguments(operationKey, references, namingContext, retryingPartialBatch, attempt) });
      const inspection = inspectToolResult(result as ToolResult);
      if (inspection.kind === "error") {
        recordDiagnostic("SERVER_TOOL_CALL_RESULT", "ERROR", inspection.code);
        manifest = buildWidgetUploadFailureManifest(references, namingContext, inspection.code);
      } else {
        recordDiagnostic("SERVER_TOOL_CALL_RESULT", "DONE", "SERVER_TOOL_RESULT_RECEIVED");
        manifest = extractUploadManifest(result as ToolResult);
        assertUploadManifestCounts(manifest);
      }
    }
    const serverItems = manifest.items.map((item) => {
      const referenceOrdinal = typeof item.file_id === "string"
        ? references.find((reference) => reference.file_id === item.file_id)?.ordinal
        : undefined;
      const ordinal = item.ordinal ?? referenceOrdinal;
      if (!Number.isInteger(ordinal)) throw new Error("MEDIA_READBACK_COUNT_MISMATCH");
      return { ...item, ordinal };
    });
    const combinedItems = [...serverItems, ...hostFailures].sort((left, right) => (left.ordinal ?? 0) - (right.ordinal ?? 0));
    const combinedSuccess = combinedItems.filter((item) => item.status === "SUCCESS").length;
    const combinedFailure = combinedItems.length - combinedSuccess;
    const combined: UploadManifest = { status: combinedFailure === 0 && combinedItems.length === selected.length ? "success" : "partial_success", requested_count: selected.length, success_count: combinedSuccess, failure_count: selected.length - combinedSuccess, items: combinedItems, user_context: namingContext };
    const logicalManifest = retryingPartialBatch ? mergeUploadManifest(batchManifest!, combined, sourceOrdinals) : combined;
    const returned = handleToolResult({ structuredContent: logicalManifest }, undefined, "widget-upload");
    batchManifest = logicalManifest;
    enrichmentStatus = "NOT_RUN";
    recordDiagnostic("ATTACHMENT_READBACK_START", "START", "ATTACHMENT_READBACK_REQUESTED");
    if (!returned.filter((item) => item.status === "SUCCESS").every((item) => (item.attachment_id ?? 0) > 0 && item.attachment_readback_status === "verified")) throw new Error("ATTACHMENT_READBACK_UNVERIFIED");
    recordDiagnostic("ATTACHMENT_READBACK_DONE", "DONE", "ATTACHMENT_READBACK_VERIFIED");
    if (!returned.filter((item) => item.status === "SUCCESS").every((item) => Boolean(item.media_id) && Boolean(item.canonical_url) && Boolean(item.public_filename) && (item.width ?? 0) > 0 && (item.height ?? 0) > 0 && Boolean(item.mime) && (item.filesize ?? 0) > 0)) throw new Error("MEDIA_READBACK_UNVERIFIED");
    recordDiagnostic("MEDIA_READBACK_DONE", "DONE", "MEDIA_READBACK_VERIFIED");
    return logicalManifest;
  }

  async function createArticle(): Promise<void> {
    if (!connected || uploading || !supportsFileUpload() || selected.length === 0) return;
    const namingContext = context.value.trim();
    uploading = true;
    enrichmentStatus = "PENDING";
    renderSelection();
    setState("UPLOADING", "Đang chuẩn bị bài viết từ Media đã tải…");
    const operationKey = retryOperationKey ?? createIdempotencyKey();
    submissionId = operationKey;
    retryAttempt = 0;
    attemptId = `${operationKey}:attempt:1`;
    try {
      if (batchManifest?.status === "partial_success") throw new Error("PARTIAL_BATCH_NOT_READY");
      const manifest = await materializeSelectedImages(operationKey, namingContext, 0);
      if (manifest.status !== "success") throw new Error("PARTIAL_BATCH_NOT_READY");
      const docs = await app.callServerTool({ name: DOCUMENTATION_TOOL_NAME, arguments: {} });
      const checkpoint = checkpointFrom(docs as ToolResult);
      const canonicalMediaIds = planSubmissionResume({
        media_commit_status: "COMPLETE",
        enrichment_status: enrichmentStatus,
        items: orderUploadedItems(uploaded),
      }).canonicalMediaIds;
      if (canonicalMediaIds.length !== selected.length) throw new Error("MEDIA_CANONICAL_READBACK_INCOMPLETE");
      phase = "CAPTURE";
      const capture = await app.callServerTool({
        name: CAPTURE_TOOL_NAME,
        arguments: {
          idempotency_key: `${operationKey}:capture`,
          documentation_checkpoint: checkpoint,
          text: namingContext,
          asset_inputs: buildCaptureAssetInputs(selected),
          media_ids: canonicalMediaIds,
          publish: false,
        },
      });
      recordDiagnostic("CAPTURE_READBACK_START", "START", "CAPTURE_READBACK_REQUESTED");
      try {
        captureReadback = assertCaptureResult(capture as ToolResult);
      } catch (error) {
        const inspection = inspectToolResult(capture as ToolResult);
        if (inspection.kind === "success") {
          try { captureReadback = normalizeCaptureReadback(inspection.payload); } catch { /* preserve the original fail-closed error */ }
        }
        throw error;
      }
      phase = "ARTICLE_MEDIA_USAGE";
      await assertArticleMediaReadback(captureReadback);
      recordDiagnostic("CAPTURE_READBACK_DONE", "DONE", "CAPTURE_READBACK_VERIFIED");
      enrichmentStatus = "COMPLETE";
      uploadStatus = "complete";
      publishBatchContext();
      retryOperationKey = null;
      retryAttempt = 0;
      recordDiagnostic("READY_FOR_USE", "DONE", "MEDIA_COMMIT_READY");
      setState("SUCCESS", "Đã gửi một submission gồm toàn bộ ảnh.");
    } catch (error) {
      enrichmentStatus = "PARTIAL";
      uploadStatus = uploaded.some((item) => item.status === "SUCCESS") ? "complete" : "error";
      retryOperationKey = operationKey;
      recordDiagnostic("ERROR", "ERROR", downstreamCode(error, phase), error, { attempt_number: retryAttempt + 1 });
      publishBatchContext();
      if (batchManifest?.status === "partial_success") {
        setState("PARTIAL", `Đã tải được ${batchManifest.success_count}/${batchManifest.requested_count} ảnh. Các ảnh còn lỗi có thể thử lại.`);
      } else if (uploaded.some((item) => item.status === "SUCCESS" && item.media_id)) {
        setState("ERROR", MEDIA_SAVED_ERROR_MESSAGE);
      } else {
        setState("ERROR", HOST_UPLOAD_ERROR_MESSAGE);
      }
    } finally {
      uploading = false;
      renderSelection();
    }
  }

  async function retryPartialSubmission(): Promise<void> {
    if (!connected || uploading || !batchManifest || batchManifest.status !== "partial_success") return;
    uploading = true;
    retryAttempt += 1;
    attemptId = `${retryOperationKey}:attempt:${retryAttempt + 1}`;
    setState("UPLOADING", "Đang thử lại các ảnh chưa hoàn tất…");
    try {
      const key = retryOperationKey ?? createIdempotencyKey();
      retryOperationKey = key;
      const next = await materializeSelectedImages(key, context.value.trim(), retryAttempt + 1);
      if (next.status !== "success") throw new Error("PARTIAL_BATCH_NOT_READY");
      retryAttempt += 1;
      uploadStatus = "complete";
      publishBatchContext();
      setState("READY", "Đã khôi phục đủ ảnh. Bấm TẢI LÊN để gửi submission.");
    } catch (error) {
      recordDiagnostic("ERROR", "ERROR", downstreamCode(error, phase), error, { attempt_number: retryAttempt + 1 });
      setState("PARTIAL", HOST_UPLOAD_ERROR_MESSAGE);
    } finally {
      uploading = false;
      renderSelection();
    }
  }

  async function retryEnrichmentSubmission(): Promise<void> {
    if (!connected || uploading || !captureReadback) return;
    uploading = true;
    setState("UPLOADING", "Đang hoàn tất liên kết ảnh với bài viết…");
    try {
      await retryCaptureConvergence();
      enrichmentStatus = "COMPLETE";
      uploadStatus = "complete";
      publishBatchContext();
      retryOperationKey = null;
      retryAttempt = 0;
      recordDiagnostic("READY_FOR_USE", "DONE", "MEDIA_COMMIT_READY");
      setState("SUCCESS", "Đã hoàn tất bài viết từ Media đã lưu.");
    } catch (error) {
      enrichmentStatus = "PARTIAL";
      retryAttempt += 1;
      recordDiagnostic("ERROR", "ERROR", diagnosticCode(error), error);
      publishBatchContext();
      setState("ERROR", MEDIA_SAVED_ERROR_MESSAGE);
    } finally {
      uploading = false;
      renderSelection();
    }
  }

  app.ontoolresult = (_result) => {
    // The notification emitted while this App is mounted is the result of
    // nhk.media.upload-widget.open. App.callServerTool returns the actual
    // widget-upload/capture result directly, so the open result must never be
    // parsed as an upload manifest.
    if (!shouldProcessToolResultNotification("open")) {
      recordDiagnostic("OPEN_TOOL_RESULT_IGNORED", "DONE", "OPEN_TOOL_RESULT_NOT_UPLOAD");
      return;
    }
  };
  app.onerror = (error) => {
    recordDiagnostic("ERROR", "ERROR", "MCP_APPS_CONNECTION_ERROR", error);
    setState("ERROR", HOST_UPLOAD_ERROR_MESSAGE);
  };
  input.addEventListener("change", () => {
    uploaded = [];
    batchManifest = null;
    enrichmentStatus = "NOT_RUN";
    retryOperationKey = null;
    retryAttempt = 0;
    selected = asFiles(input.files)
      .filter((file) => IMAGE_TYPES.test(file.type))
      .map((file) => ({ kind: "local" as const, clientFileId: `${file.name}:${file.size}:${file.lastModified}`, file, name: file.name, feature: "" }));
    recordDiagnostic("FILE_SELECTED", "DONE", "LOCAL_FILE_SELECTED");
    recordDiagnostic("FILE_PREVIEW_READY", "DONE", "LOCAL_FILE_PREVIEW_READY");
    renderSelection();
  });
  select.addEventListener("click", async () => {
    if (!connected || typeof host?.selectFiles !== "function") return;
    try {
      uploaded = [];
      batchManifest = null;
      enrichmentStatus = "NOT_RUN";
      retryOperationKey = null;
      retryAttempt = 0;
      selected = normalizeSelectedFiles(await host.selectFiles());
      recordDiagnostic("FILE_SELECTED", "DONE", "LIBRARY_FILE_SELECTED");
      recordDiagnostic("FILE_PREVIEW_READY", "DONE", "LIBRARY_FILE_PREVIEW_READY");
      renderSelection();
    } catch (error) {
      recordDiagnostic("ERROR", "ERROR", "FILE_SELECTION_FAILED", error);
      setState("ERROR", HOST_UPLOAD_ERROR_MESSAGE);
    }
  });
  previews.addEventListener("input", (event) => {
    const target = event.target as HTMLInputElement;
    const figure = target.closest<HTMLElement>("figure");
    const item = selected.find((candidate) => candidate.clientFileId === figure?.dataset.clientFileId);
    if (!item || !target.dataset.field) return;
    if (target.dataset.field === "name") item.name = target.value;
    if (target.dataset.field === "feature") item.feature = target.value;
  });
  previews.addEventListener("click", (event) => {
    const target = event.target as HTMLElement;
    if (!target.matches(".remove-asset")) return;
    selected = selected.filter((item) => item.clientFileId !== target.dataset.clientFileId);
    renderSelection();
  });
  upload.addEventListener("click", () => void (
    batchManifest?.status === "partial_success"
      ? retryPartialSubmission()
      : enrichmentStatus === "PARTIAL" && captureReadback !== null
        ? retryEnrichmentSubmission()
        : createArticle()
  ));

  setState("CONNECTING", "Đang kết nối tới MCP Apps host…");
  recordDiagnostic("BOOT", "DONE", "WIDGET_BOOT");
  recordDiagnostic("RESOURCE_LOADED", "DONE", "RESOURCE_READY");
  recordDiagnostic("HOST_CAPABILITIES_READ", "DONE", supportsFileUpload() ? "HOST_FILE_APIS_AVAILABLE" : "HOST_FILE_APIS_UNAVAILABLE");
  try {
    await app.connect();
    connected = true;
    input.disabled = false;
    select.hidden = typeof host?.selectFiles !== "function";
    select.disabled = typeof host?.selectFiles !== "function";
    setState("READY", supportsFileUpload()
      ? "Sẵn sàng. Chọn ảnh và nhập ngữ cảnh bộ ảnh."
      : "Đã kết nối nhưng host không hỗ trợ uploadFile và getFileDownloadUrl.");
    renderSelection();
  } catch (error: unknown) {
    connected = false;
    input.disabled = true;
    select.disabled = true;
    upload.disabled = true;
    recordDiagnostic("ERROR", "ERROR", "MCP_APPS_CONNECT_FAILED", error);
    setState("ERROR", HOST_UPLOAD_ERROR_MESSAGE);
  }
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => void boot(), { once: true });
else void boot();
