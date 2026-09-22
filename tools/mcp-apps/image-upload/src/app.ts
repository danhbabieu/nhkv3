import { App } from "@modelcontextprotocol/ext-apps";
import { assertUploadManifestCounts, buildWidgetState, extractUploadManifest, inspectToolResult, mergeUploadManifest, normalizeSelectedFiles, shouldProcessToolResultNotification, type BatchContext, type SelectedImage, type ToolResult, type ToolResultNotificationSource, type UploadedItem, type UploadManifest, type WidgetDiagnostic, type WidgetUploadStatus } from "./contract";

// Easy MCP exposes the internal/admin boundary under the registered
// WordPress Ability name. callServerTool must use that exact runtime name;
// the canonical NHK name remains the server-side catalog name.
const SERVER_TOOL_NAME = "wp_ability_nhk_v3_media_widget_upload";
const CAPTURE_TOOL_NAME = "wp_ability_nhk_v3_capture_ingest";
const DOCUMENTATION_TOOL_NAME = "wp_ability_nhk_v3_documentation_bootstrap";
const RESOURCE_URI = "ui://nhk/image-upload.html";
const IMAGE_TYPES = /^(image\/jpeg|image\/png|image\/gif|image\/webp)$/;
const IMAGE_ACCEPT = ["image/jpeg", "image/png", "image/gif", "image/webp"];
const STATES = ["CONNECTING", "READY", "UPLOADING", "SUCCESS", "PARTIAL", "ERROR"] as const;
type WidgetState = (typeof STATES)[number];

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
    status.textContent = `Không thể khởi tạo trình tải ảnh NHK: ${safeErrorMessage(error)}`;
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
  const create = byId<HTMLButtonElement>("create");
  const summary = byId<HTMLDivElement>("summary");
  const previews = byId<HTMLDivElement>("previews");
  const state = byId<HTMLSpanElement>("state");
  const status = byId<HTMLDivElement>("status");
  const results = byId<HTMLDivElement>("results");
  const context = byId<HTMLInputElement>("context");
  const articleTitle = byId<HTMLInputElement>("article-title");
  const articleText = byId<HTMLTextAreaElement>("article-text");
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

  function renderDiagnostics(): void {
    diagnosticsView.replaceChildren();
    diagnostics.slice(-12).forEach((diagnostic) => {
      const row = document.createElement("div");
      row.textContent = [diagnostic.stage, diagnostic.status, diagnostic.code].filter(Boolean).join(" · ");
      diagnosticsView.append(row);
    });
  }

  function recordDiagnostic(stage: string, statusValue: WidgetDiagnostic["status"], code: string, error?: unknown): void {
    diagnostics.push({
      stage,
      status: statusValue,
      code,
      ...(error ? { error: safeErrorMessage(error) } : {}),
      uri: RESOURCE_URI,
      tool: SERVER_TOOL_NAME,
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
    let total = 0;
    selected.forEach((item) => {
      const file = item.kind === "local" ? item.file : null;
      const fileName = item.kind === "local" ? item.file.name : item.fileName;
      const fileSize = file?.size ?? 0;
      total += fileSize;
      const figure = document.createElement("figure");
      if (file) {
        const image = document.createElement("img");
        image.alt = file.name;
        image.src = URL.createObjectURL(file);
        figure.append(image);
      }
      const caption = document.createElement("figcaption");
      caption.textContent = file
        ? `${fileName} (${(fileSize / 1048576).toFixed(2)} MB)`
        : `${fileName} (ChatGPT Library)`;
      figure.append(caption);
      previews.append(figure);
    });
    summary.textContent = `${selected.length} ảnh, ${(total / 1048576).toFixed(2)} MB`;
    const disabled = !connected || uploading || selected.length === 0 || !supportsFileUpload();
    upload.disabled = disabled;
    create.disabled = disabled;
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

  function assertCaptureResult(result: ToolResult): void {
    const inspection = inspectToolResult(result);
    if (inspection.kind !== "success") throw new Error(inspection.code);
    const payload = inspection.payload;
    if (!payload || typeof payload !== "object") throw new Error("CAPTURE_READBACK_UNAVAILABLE");
    const record = payload as { capture_id?: unknown; capture?: { capture_id?: unknown } };
    if (typeof record.capture_id !== "string" && typeof record.capture?.capture_id !== "string") throw new Error("CAPTURE_READBACK_UNAVAILABLE");
  }

  async function materializeSelectedImages(operationKey: string, namingContext: string, attempt: number): Promise<UploadManifest> {
    if (batchManifest !== null && batchManifest.success_count === batchManifest.requested_count && uploaded.length === selected.length && uploaded.every((item) => Boolean(item.media_id))) return batchManifest;
    const retryingPartialBatch = batchManifest?.status === "partial_success";
    const sourceOrdinals = selected
      .map((_item, index) => index)
      .filter((index) => !retryingPartialBatch || batchManifest?.items[index]?.status !== "SUCCESS");
    if (retryingPartialBatch && sourceOrdinals.length === 0) return batchManifest!;
    const references: Array<{ download_url: string; file_id: string; mime_type: string; file_name: string; ordinal: number; media: Record<string, string> }> = [];
    for (const index of sourceOrdinals) {
      const item = selected[index];
      const fileName = item.kind === "local" ? item.file.name : item.fileName;
      recordDiagnostic("HOST_FILE_UPLOAD_START", "START", "HOST_FILE_UPLOAD_REQUESTED");
      setState("UPLOADING", `Đang tải ${fileName}…`);
      const fileId = item.kind === "local"
        ? (await host!.uploadFile!(item.file, { library: false })).fileId
        : item.fileId;
      if (!fileId) throw new Error(`Upload did not return a file ID for ${fileName}.`);
      recordDiagnostic("HOST_FILE_UPLOAD_DONE", "DONE", "HOST_FILE_UPLOAD_VERIFIED");
      const download = await host!.getFileDownloadUrl!({ fileId });
      if (!download.downloadUrl) throw new Error(`Download URL was not returned for ${fileName}.`);
      references.push({ download_url: download.downloadUrl, file_id: fileId, mime_type: item.kind === "local" ? item.file.type : item.mimeType, file_name: fileName, ordinal: index, media: item.metadata ?? {} });
      recordDiagnostic("TRUSTED_FILE_REF_READY", "DONE", "TRUSTED_FILE_REFERENCE_READY");
    }

    const result = await app.callServerTool({
      name: SERVER_TOOL_NAME,
      arguments: { idempotency_key: `${operationKey}${retryingPartialBatch ? `:retry:${attempt}` : ":media"}`, metadata: { description: namingContext }, items: references.map((item) => ({ client_file_id: item.file_id, filename: item.file_name, sort_order: item.ordinal, ordinal: item.ordinal, media: item.media })), files: references },
    });
    recordDiagnostic("SERVER_TOOL_CALL_RESULT", "DONE", "SERVER_TOOL_RESULT_RECEIVED");
    const manifest = extractUploadManifest(result as ToolResult);
    assertUploadManifestCounts(manifest);
    const logicalManifest = retryingPartialBatch ? mergeUploadManifest(batchManifest!, manifest, sourceOrdinals) : manifest;
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

  async function uploadOnly(): Promise<void> {
    if (!connected || uploading || !supportsFileUpload() || selected.length === 0) return;
    const namingContext = context.value.trim();
    if (namingContext === "") {
      recordDiagnostic("ERROR", "ERROR", "TRUSTWORTHY_FILENAME_CONTEXT_REQUIRED");
      setState("ERROR", "Hãy nhập ngữ cảnh bộ ảnh trước khi tải ảnh lên.");
      return;
    }
    uploading = true;
    uploadStatus = "idle";
    enrichmentStatus = "NOT_RUN";
    renderSelection();
    setState("UPLOADING", "Đang xử lý ảnh đã chọn…");
    const operationKey = retryOperationKey ?? createIdempotencyKey();

    try {
      recordDiagnostic("SERVER_TOOL_CALL_START", "START", "SERVER_TOOL_CALL_REQUESTED");
      const isRetry = batchManifest?.status === "partial_success";
      if (isRetry) retryAttempt += 1;
      const manifest = await materializeSelectedImages(operationKey, namingContext, retryAttempt);
      uploadStatus = manifest.status === "success" ? "complete" : "partial";
      publishBatchContext();
      retryOperationKey = manifest.status === "partial_success" ? operationKey : null;
      if (manifest.status === "success") retryAttempt = 0;
      recordDiagnostic("MEDIA_COMMITTED", "DONE", manifest.status === "success" ? "MEDIA_COMMIT_COMPLETE" : "MEDIA_COMMIT_PARTIAL");
      if (manifest.status === "success") {
        setState("SUCCESS", `Đã tải ${manifest.success_count}/${manifest.requested_count} ảnh. Có thể tạo bài viết ở mục bên dưới.`);
      } else {
        setState("PARTIAL", `Đã tải ${manifest.success_count}/${manifest.requested_count} ảnh. Vui lòng thử lại ảnh lỗi.`);
      }
    } catch (error) {
      uploadStatus = "error";
      retryOperationKey = operationKey;
      recordDiagnostic("ERROR", "ERROR", diagnosticCode(error), error);
      setState("ERROR", `Tải ảnh thất bại: ${safeErrorMessage(error)}`);
    } finally {
      uploading = false;
      renderSelection();
    }
  }

  async function createArticle(): Promise<void> {
    if (!connected || uploading || !supportsFileUpload() || selected.length === 0) return;
    const namingContext = context.value.trim();
    if (namingContext === "") {
      recordDiagnostic("ERROR", "ERROR", "TRUSTWORTHY_FILENAME_CONTEXT_REQUIRED");
      setState("ERROR", "Hãy nhập ngữ cảnh bộ ảnh trước khi tạo bài viết.");
      return;
    }
    uploading = true;
    enrichmentStatus = "PENDING";
    renderSelection();
    setState("UPLOADING", "Đang chuẩn bị bài viết từ Media đã tải…");
    const operationKey = retryOperationKey ?? createIdempotencyKey();
    try {
      if (batchManifest?.status === "partial_success") throw new Error("PARTIAL_BATCH_NOT_READY");
      const manifest = await materializeSelectedImages(operationKey, namingContext, 0);
      if (manifest.status !== "success") throw new Error("PARTIAL_BATCH_NOT_READY");
      const docs = await app.callServerTool({ name: DOCUMENTATION_TOOL_NAME, arguments: {} });
      const checkpoint = checkpointFrom(docs as ToolResult);
      const capture = await app.callServerTool({
        name: CAPTURE_TOOL_NAME,
        arguments: {
          idempotency_key: `${operationKey}:capture`,
          documentation_checkpoint: checkpoint,
          intent: "IMAGE_ARTICLE",
          title: articleTitle.value.trim(),
          text: articleText.value,
          metadata: { image_context: namingContext },
          media_ids: uploaded.map((item) => item.media_id).filter((id): id is string => Boolean(id)),
          publish: false,
        },
      });
      recordDiagnostic("CAPTURE_READBACK_START", "START", "CAPTURE_READBACK_REQUESTED");
      assertCaptureResult(capture as ToolResult);
      recordDiagnostic("CAPTURE_READBACK_DONE", "DONE", "CAPTURE_READBACK_VERIFIED");
      enrichmentStatus = "COMPLETE";
      uploadStatus = "complete";
      publishBatchContext();
      retryOperationKey = null;
      retryAttempt = 0;
      recordDiagnostic("READY_FOR_USE", "DONE", "MEDIA_COMMIT_READY");
      setState("SUCCESS", "Đã tạo bài viết từ Media đã tải.");
    } catch (error) {
      enrichmentStatus = "PARTIAL";
      uploadStatus = uploaded.some((item) => item.status === "SUCCESS") ? "complete" : "error";
      retryOperationKey = operationKey;
      recordDiagnostic("ERROR", "ERROR", diagnosticCode(error), error);
      publishBatchContext();
      setState("ERROR", `Tạo bài viết thất bại: ${safeErrorMessage(error)}`);
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
    setState("ERROR", `Kết nối MCP Apps thất bại: ${safeErrorMessage(error)}`);
  };
  input.addEventListener("change", () => {
    uploaded = [];
    batchManifest = null;
    enrichmentStatus = "NOT_RUN";
    retryOperationKey = null;
    retryAttempt = 0;
    selected = asFiles(input.files)
      .filter((file) => IMAGE_TYPES.test(file.type))
      .map((file) => ({ kind: "local" as const, file }));
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
      setState("ERROR", `Chọn ảnh thất bại: ${safeErrorMessage(error)}`);
    }
  });
  create.addEventListener("click", () => void createArticle());
  upload.addEventListener("click", () => void uploadOnly());

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
    create.disabled = true;
    recordDiagnostic("ERROR", "ERROR", "MCP_APPS_CONNECT_FAILED", error);
    setState("ERROR", `Không thể kết nối MCP Apps: ${safeErrorMessage(error)}`);
  }
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => void boot(), { once: true });
else void boot();
