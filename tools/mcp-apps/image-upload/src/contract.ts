export type UploadedItem = {
  ordinal?: number;
  attachment_id?: number;
  media_id?: string;
  public_filename?: string;
  original_filename?: string;
  file_id?: string;
  status?: string;
  canonical_url?: string;
  attachment_readback_status?: string;
  mime?: string;
  filesize?: number;
  width?: number;
  height?: number;
  error_code?: string;
};

export type UploadManifest = {
  status: "success" | "partial_success";
  requested_count: number;
  success_count: number;
  failure_count: number;
  items: UploadedItem[];
  batch_id?: string;
  user_context?: string;
};

export type BatchContext = {
  batch_id?: string;
  ordered_media_ids: string[];
  items: Array<Record<string, unknown>>;
  user_context: string;
  media_commit_status: "NOT_RUN" | "COMPLETE" | "PARTIAL";
  enrichment_status: "NOT_RUN" | "PENDING" | "PARTIAL" | "COMPLETE";
};

export type WidgetDiagnostic = {
  stage: string;
  status: "START" | "DONE" | "ERROR";
  code?: string;
  error?: string;
  uri?: string;
  tool?: string;
};

export type WidgetUploadStatus = "idle" | "partial" | "complete" | "error";

export type SelectedImage =
  | { kind: "local"; clientFileId: string; file: File; name: string; feature: string }
  | { kind: "library"; clientFileId: string; fileId: string; fileName: string; mimeType: string; name: string; feature: string };

export type CaptureAssetInput = {
  client_file_id: string;
  ordinal: number;
  name: string;
  feature_requests: string[];
};

export type CaptureReadback = {
  capture_id: string;
  capture_status?: string;
  article?: { post_id?: number | string | null } | null;
  article_id?: number | string | null;
  content_intent?: string | { intent?: string };
  article_media_plan?: { media_dispositions?: Array<{ media_id?: string; status?: string }> } | null;
  per_media_disposition?: Array<{ media_id?: string; status?: string }>;
  canonical_usage_readback?: Array<{ media_id?: string; endpoint_type?: string; endpoint_key?: string; active?: boolean }>;
};

/**
 * Normalize the two server-owned capture.ingest success projections at the
 * transport boundary. A new ingest returns the readback flat; continuation
 * returns the same CaptureRecord under `capture` with a retry envelope.
 */
export function normalizeCaptureReadback(payload: unknown): CaptureReadback {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new Error("CAPTURE_READBACK_UNAVAILABLE");
  const record = payload as CaptureReadback & { capture?: unknown };
  if (typeof record.capture_id === "string" && record.capture_id !== "") return record;
  if (!record.capture || typeof record.capture !== "object" || Array.isArray(record.capture)) throw new Error("CAPTURE_READBACK_UNAVAILABLE");
  const nested = record.capture as Record<string, unknown>;
  if (typeof nested.capture_id !== "string" || nested.capture_id === "") throw new Error("CAPTURE_READBACK_UNAVAILABLE");
  return { ...record, capture_id: nested.capture_id };
}

export function assertCaptureArticleReadback(payload: unknown, expectedMediaIds: string[]): CaptureReadback {
  const record = normalizeCaptureReadback(payload);
  const intent = typeof record.content_intent === "string" ? record.content_intent : record.content_intent?.intent;
  if (intent?.toUpperCase() !== "IMAGE_ARTICLE") return record;
  const postId = record.article?.post_id ?? record.article_id;
  if (!(Number(postId) > 0)) throw new Error("ARTICLE_READBACK_UNAVAILABLE");
  const dispositions = record.per_media_disposition ?? record.article_media_plan?.media_dispositions ?? [];
  const usages = record.canonical_usage_readback ?? [];
  const byMedia = new Map(dispositions.map((item) => [item.media_id ?? "", item.status?.toUpperCase() ?? ""]));
  const usageMedia = new Set(usages.filter((item) => item.active !== false && item.endpoint_type === "wp_post").map((item) => item.media_id ?? ""));
  for (const mediaId of expectedMediaIds) {
    if (byMedia.get(mediaId) !== "APPLIED") throw new Error("ARTICLE_MEDIA_DISPOSITION_INCOMPLETE");
    if (!usageMedia.has(mediaId)) throw new Error("ARTICLE_MEDIA_USAGE_READBACK_INCOMPLETE");
  }
  if (record.capture_status && !["COMPLETE", "READY_FOR_PUBLICATION", "PUBLISHED"].includes(record.capture_status.toUpperCase())) throw new Error("CAPTURE_ENRICHMENT_INCOMPLETE");
  return record;
}

export function assertMediaArticleReadback(result: ToolResult, mediaId: string, articleId: number): void {
  const inspection = inspectToolResult(result);
  if (inspection.kind !== "success") throw new Error(inspection.code);
  if (!inspection.payload || typeof inspection.payload !== "object" || Array.isArray(inspection.payload)) throw new Error("MEDIA_READBACK_UNAVAILABLE");
  const record = inspection.payload as { id?: unknown; usages?: unknown };
  if (record.id !== mediaId || !Array.isArray(record.usages)) throw new Error("MEDIA_READBACK_UNAVAILABLE");
  const usage = record.usages.find((item) => {
    if (!item || typeof item !== "object" || Array.isArray(item)) return false;
    const value = item as { media_id?: unknown; target_type?: unknown; target_id?: unknown; active?: unknown };
    const targetId = typeof value.target_id === "string" ? value.target_id : "";
    const articleTarget = targetId === String(articleId) || targetId.endsWith(`:${articleId}`);
    return value.media_id === mediaId && value.target_type === "wp_post" && articleTarget && value.active !== false;
  });
  if (!usage) throw new Error("ARTICLE_MEDIA_USAGE_READBACK_INCOMPLETE");
}

export function normalizeSelectedFiles(value: unknown): SelectedImage[] {
  if (!Array.isArray(value)) return [];

  return value.flatMap((item): SelectedImage[] => {
    if (!item || typeof item !== "object") return [];
    const reference = item as { fileId?: unknown; fileName?: unknown; mimeType?: unknown };
    if (typeof reference.fileId !== "string" || reference.fileId === "") return [];
    if (typeof reference.fileName !== "string" || reference.fileName === "") return [];
    if (typeof reference.mimeType !== "string" || reference.mimeType === "") return [];
    return [{ kind: "library", clientFileId: reference.fileId, fileId: reference.fileId, fileName: reference.fileName, mimeType: reference.mimeType, name: reference.fileName, feature: "" }];
  });
}

export function splitFeatureRequests(value: string): string[] {
  return value.split(/[\n,]/u).map((item) => item.trim()).filter(Boolean);
}

export function buildCaptureAssetInputs(items: SelectedImage[]): CaptureAssetInput[] {
  return items.map((item, ordinal) => ({
    client_file_id: item.clientFileId,
    ordinal,
    name: item.name.trim(),
    feature_requests: splitFeatureRequests(item.feature),
  }));
}

export type ToolResult = {
  isError?: unknown;
  error?: unknown;
  structuredContent?: unknown;
  content?: Array<{ type?: string; text?: string }>;
  result?: unknown;
};

export type ToolResultNotificationSource = "open" | "widget-upload" | "capture";

/**
 * The tool-result notification delivered while mounting an App belongs to the
 * tool that opened the widget. It is not the result of a widget action.
 */
export function shouldProcessToolResultNotification(source: ToolResultNotificationSource | null): boolean {
  return source === "widget-upload" || source === "capture";
}

export type ToolResultInspection =
  | { kind: "success"; payload: unknown }
  | { kind: "error"; code: string }
  | { kind: "malformed"; code: "SERVER_TOOL_RESULT_INVALID" };

const SAFE_ERROR_CODE = /^[A-Z][A-Z0-9_]{2,}$/;

function errorCode(value: unknown): string | null {
  if (typeof value === "string") {
    const code = value.match(/^[A-Z][A-Z0-9_]{2,}/)?.[0];
    return code && SAFE_ERROR_CODE.test(code) ? code : null;
  }
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;
  const record = value as Record<string, unknown>;
  for (const key of ["code", "reason_code", "reasonCode", "error_code"]) {
    const code = errorCode(record[key]);
    if (code) return code;
  }
  return errorCode(record.error);
}

function textErrorCode(value: string): string | null {
  const text = value.trim();
  const typed = text.match(/^(?:Error\s*:\s*)?([A-Z][A-Z0-9_]{2,})(?:\b|:)/)?.[1];
  if (typed && SAFE_ERROR_CODE.test(typed)) return typed;
  return /^error\s*:/i.test(text) ? "SERVER_TOOL_ERROR" : null;
}

function parsedText(result: ToolResult): unknown {
  const text = result.content?.find((item) => item.type === "text")?.text;
  if (!text) return undefined;
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return text;
  }
}

function parsedContent(value: Record<string, unknown>): unknown {
  const content = value.content;
  if (!Array.isArray(content)) return undefined;
  const text = content.find((item) => item && typeof item === "object" && (item as { type?: unknown }).type === "text") as { text?: unknown } | undefined;
  if (typeof text?.text !== "string" || text.text === "") return undefined;
  try {
    return JSON.parse(text.text) as unknown;
  } catch {
    return text.text;
  }
}

function inspectValue(value: unknown, depth = 0): ToolResultInspection {
  if (depth > 6) return { kind: "malformed", code: "SERVER_TOOL_RESULT_INVALID" };
  if (typeof value === "string") {
    try {
      return inspectValue(JSON.parse(value) as unknown, depth + 1);
    } catch {
      const code = textErrorCode(value);
      return code ? { kind: "error", code } : { kind: "malformed", code: "SERVER_TOOL_RESULT_INVALID" };
    }
  }
  if (!value || typeof value !== "object" || Array.isArray(value)) return { kind: "success", payload: value };
  const record = value as Record<string, unknown>;
  if (record.isError === true) return { kind: "error", code: errorCode(record) ?? errorCode(record.structuredContent) ?? errorCode(record.result) ?? textErrorCode(typeof record.content === "string" ? record.content : "") ?? "SERVER_TOOL_ERROR" };
  if (record.error !== undefined) return { kind: "error", code: errorCode(record.error) ?? "SERVER_TOOL_ERROR" };
  const status = typeof record.status === "string" ? record.status.trim().toLowerCase() : "";
  if (status === "error") {
    const item = Array.isArray(record.items) ? record.items.find((candidate) => candidate && typeof candidate === "object" && !Array.isArray(candidate) && ((candidate as Record<string, unknown>).error !== undefined || (candidate as Record<string, unknown>).error_code !== undefined)) : undefined;
    return { kind: "error", code: errorCode(record.items) ?? errorCode(item) ?? "SERVER_TOOL_ERROR" };
  }
  if (Array.isArray(record.content)) {
    const text = record.content.find((item) => item && typeof item === "object" && (item as { type?: unknown }).type === "text") as { text?: unknown } | undefined;
    if (typeof text?.text === "string") {
      const code = textErrorCode(text.text);
      if (code) return { kind: "error", code };
    }
  }
  let firstSuccess: ToolResultInspection | null = null;
  let firstMalformed: ToolResultInspection | null = null;
  for (const nested of [record.structuredContent, record.result]) {
    if (nested === undefined) continue;
    const inspection = inspectValue(nested, depth + 1);
    if (inspection.kind === "error") return inspection;
    if (inspection.kind === "success" && firstSuccess === null) firstSuccess = inspection;
    if (inspection.kind === "malformed" && firstMalformed === null) firstMalformed = inspection;
  }
  const content = parsedContent(record);
  if (content !== undefined) {
    const inspection = inspectValue(content, depth + 1);
    if (inspection.kind === "error") return inspection;
    if (inspection.kind === "success" && firstSuccess === null) firstSuccess = inspection;
    if (inspection.kind === "malformed" && firstMalformed === null) firstMalformed = inspection;
  }
  return firstSuccess ?? firstMalformed ?? { kind: "success", payload: value };
}

export function inspectToolResult(result: ToolResult): ToolResultInspection {
  if (result.isError === true) return { kind: "error", code: errorCode(result) ?? errorCode(result.error) ?? errorCode(result.structuredContent) ?? errorCode(result.result) ?? errorCode(parsedText(result)) ?? "SERVER_TOOL_ERROR" };
  if (result.error !== undefined) return { kind: "error", code: errorCode(result.error) ?? "SERVER_TOOL_ERROR" };
  let firstSuccess: ToolResultInspection | null = null;
  let firstMalformed: ToolResultInspection | null = null;
  for (const candidate of [result.structuredContent, result.result, parsedText(result)]) {
    if (candidate === undefined) continue;
    const inspection = inspectValue(candidate);
    if (inspection.kind === "error") return inspection;
    if (inspection.kind === "success" && firstSuccess === null) firstSuccess = inspection;
    if (inspection.kind === "malformed" && firstMalformed === null) firstMalformed = inspection;
  }
  if (firstSuccess !== null) return firstSuccess;
  if (firstMalformed !== null) return firstMalformed;
  const direct = inspectValue(result);
  return direct.kind === "success" && direct.payload === result
    ? { kind: "malformed", code: "SERVER_TOOL_RESULT_INVALID" }
    : direct;
}

export function extractPayload(result: ToolResult): unknown {
  const nested = result.structuredContent ?? result.result;
  if (nested !== undefined) {
    if (nested && typeof nested === "object" && !Array.isArray(nested)) {
      const envelope = nested as { structuredContent?: unknown; result?: unknown };
      if (envelope.structuredContent !== undefined) return extractPayload(envelope as ToolResult);
      if (envelope.result !== undefined) return extractPayload(envelope as ToolResult);
      const content = parsedContent(nested as Record<string, unknown>);
      if (content !== undefined) return content;
    }
    return nested;
  }
  const text = result.content?.find((item) => item.type === "text")?.text;
  if (!text) return undefined;
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return undefined;
  }
}

function safeUploadedItem(value: unknown, fallbackStatus = "SUCCESS"): UploadedItem | null {
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;
  const record = value as Record<string, unknown>;
  const statusValue = typeof record.status === "string" ? record.status : record.upload_status;
  const normalizedStatus = typeof statusValue === "string" ? statusValue.trim().toUpperCase() : "";
  const status = normalizedStatus === "CREATED" || normalizedStatus === "SUCCESS" ? "SUCCESS" : normalizedStatus !== "" ? normalizedStatus : fallbackStatus;
  const item: UploadedItem = {
    ...(Number.isInteger(record.ordinal) && (record.ordinal as number) >= 0 ? { ordinal: record.ordinal as number } : {}),
    ...(typeof record.attachment_id === "number" ? { attachment_id: record.attachment_id } : {}),
    ...(typeof record.media_id === "string" ? { media_id: record.media_id } : {}),
    ...(typeof record.public_filename === "string" ? { public_filename: record.public_filename } : typeof record.filename === "string" ? { public_filename: record.filename } : {}),
    ...(typeof record.original_filename === "string" ? { original_filename: record.original_filename } : {}),
    ...(typeof record.mime === "string" ? { mime: record.mime } : typeof record.mime_type === "string" ? { mime: record.mime_type } : {}),
    ...(typeof record.filesize === "number" ? { filesize: record.filesize } : typeof record.byte_size === "number" ? { filesize: record.byte_size } : {}),
    ...(typeof record.width === "number" ? { width: record.width } : {}),
    ...(typeof record.height === "number" ? { height: record.height } : {}),
    ...(typeof record.canonical_url === "string" ? { canonical_url: record.canonical_url } : typeof record.source_url === "string" ? { canonical_url: record.source_url } : {}),
    ...(typeof record.attachment_readback_status === "string" ? { attachment_readback_status: record.attachment_readback_status } : {}),
    ...(typeof record.error_code === "string" ? { error_code: record.error_code } : typeof record.code === "string" && SAFE_ERROR_CODE.test(record.code) ? { error_code: record.code } : errorCode(record.error) ? { error_code: errorCode(record.error)! } : {}),
    status,
  };
  const fileId = typeof record.file_id === "string" ? record.file_id : typeof record.client_file_id === "string" ? record.client_file_id : undefined;
  if (fileId && !fileId.includes("://")) item.file_id = fileId;
  return item;
}

export function extractUploadManifest(result: ToolResult): UploadManifest {
  const inspection = inspectToolResult(result);
  if (inspection.kind !== "success") throw new Error(inspection.code);
  if (!inspection.payload || typeof inspection.payload !== "object" || Array.isArray(inspection.payload)) throw new Error("SERVER_TOOL_RESULT_INVALID");
  const record = inspection.payload as Record<string, unknown>;
  const rawItems = Array.isArray(record.items) ? record.items : Array.isArray(record.uploads) ? record.uploads : null;
  if (!rawItems) throw new Error("SERVER_TOOL_RESULT_INVALID");
  const authoritativeItems = Array.isArray(record.items);
  const items = rawItems.flatMap((item, index) => {
    const safeItem = safeUploadedItem(item, "SUCCESS");
    if (safeItem && authoritativeItems && safeItem.ordinal === undefined) safeItem.ordinal = index;
    return safeItem ? [safeItem] : [];
  });
  if (items.length !== rawItems.length) throw new Error("SERVER_TOOL_RESULT_INVALID");
  const requested = typeof record.requested_count === "number" ? record.requested_count : rawItems.length;
  const success = typeof record.success_count === "number" ? record.success_count : items.filter((item) => item.status === "SUCCESS").length;
  const failure = typeof record.failure_count === "number" ? record.failure_count : Math.max(0, requested - success);
  if (!Number.isInteger(requested) || !Number.isInteger(success) || !Number.isInteger(failure) || requested < 0 || success < 0 || failure < 0) throw new Error("SERVER_TOOL_RESULT_INVALID");
  const statusValue = typeof record.status === "string" ? record.status.trim().toLowerCase() : "";
  const status = statusValue === "" ? (failure > 0 ? "partial_success" : "success") : statusValue;
  if (status !== "success" && status !== "partial_success") throw new Error("SERVER_TOOL_RESULT_INVALID");
  if (authoritativeItems) {
    const ordinals = items.map((item) => item.ordinal);
    if (new Set(ordinals).size !== ordinals.length || ordinals.some((ordinal) => ordinal === undefined || ordinal < 0 || ordinal >= requested)) throw new Error("SERVER_TOOL_RESULT_INVALID");
  }
  const batchId = typeof record.batch_id === "string" && record.batch_id !== "" ? record.batch_id : undefined;
  const userContext = typeof record.user_context === "string"
    ? record.user_context
    : typeof record.context === "string"
      ? record.context
      : "";
  return { status, requested_count: requested, success_count: success, failure_count: failure, items, ...(batchId ? { batch_id: batchId } : {}), user_context: userContext };
}

export function assertUploadManifestCounts(manifest: UploadManifest): void {
  const successItems = manifest.items.filter((item) => item.status === "SUCCESS").length;
  const failureItems = manifest.items.filter((item) => item.status !== "SUCCESS").length;
  if (manifest.requested_count !== manifest.success_count + manifest.failure_count || manifest.items.length !== manifest.requested_count || successItems !== manifest.success_count || failureItems !== manifest.failure_count) throw new Error("MEDIA_READBACK_COUNT_MISMATCH");
}

export function assertUploadManifestCount(manifest: UploadManifest, expected: number): void {
  assertUploadManifestCounts(manifest);
  if (manifest.status !== "success" || manifest.requested_count !== expected || manifest.success_count !== expected || manifest.failure_count !== 0 || manifest.items.some((item) => item.status !== "SUCCESS")) throw new Error("MEDIA_READBACK_COUNT_MISMATCH");
}

export function mergeUploadManifest(previous: UploadManifest, retry: UploadManifest, retriedOrdinals: number[]): UploadManifest {
  if (retry.items.length !== retriedOrdinals.length) throw new Error("MEDIA_READBACK_COUNT_MISMATCH");
  const merged = previous.items.map((item, index) => ({ ...item, ordinal: item.ordinal ?? index }));
  retry.items.forEach((item, index) => {
    const ordinal = retriedOrdinals[index];
    if (!Number.isInteger(ordinal) || ordinal < 0 || ordinal >= previous.requested_count) throw new Error("MEDIA_READBACK_COUNT_MISMATCH");
    merged[ordinal] = { ...item, ordinal };
  });
  const successCount = merged.filter((item) => item.status === "SUCCESS").length;
  const failureCount = merged.length - successCount;
  return {
    status: failureCount === 0 ? "success" : "partial_success",
    requested_count: previous.requested_count,
    success_count: successCount,
    failure_count: failureCount,
    items: merged,
    ...(previous.batch_id || retry.batch_id ? { batch_id: previous.batch_id ?? retry.batch_id } : {}),
    user_context: retry.user_context || previous.user_context,
  };
}

export function extractUploads(result: ToolResult): UploadedItem[] {
  try {
    return extractUploadManifest(result).items;
  } catch {
    return [];
  }
}

function redactDiagnostic(value: string): string {
  return value.replace(/https?:\/\/[^\s)]+/gi, "[redacted-url]");
}

export function buildBatchContext(items: UploadedItem[], manifest: Pick<UploadManifest, "batch_id" | "user_context" | "requested_count" | "failure_count"> | null = null, enrichmentStatus: BatchContext["enrichment_status"] = "NOT_RUN"): BatchContext {
  return {
    ...(manifest?.batch_id ? { batch_id: manifest.batch_id } : {}),
    ordered_media_ids: items.filter((item) => item.status === "SUCCESS" && typeof item.media_id === "string").map((item) => item.media_id as string),
    items: items.map((item, index) => ({
      position: (item.ordinal ?? index) + 1,
      ...(item.media_id ? { media_id: item.media_id } : {}),
      ...(item.attachment_id ? { attachment_id: item.attachment_id } : {}),
      status: item.status || "SUCCESS",
      ...(item.error_code ? { error_code: item.error_code } : {}),
    })),
    user_context: manifest?.user_context ?? "",
    media_commit_status: manifest === null ? "NOT_RUN" : (manifest.failure_count ?? 0) > 0 ? "PARTIAL" : "COMPLETE",
    enrichment_status: enrichmentStatus,
  };
}

export function buildWidgetState(items: UploadedItem[], diagnostics: WidgetDiagnostic[] = [], uploadStatus: WidgetUploadStatus = items.length > 0 ? "complete" : "idle", manifest: Pick<UploadManifest, "batch_id" | "user_context" | "requested_count" | "failure_count"> | null = null, enrichmentStatus: BatchContext["enrichment_status"] = "NOT_RUN"): {
  modelContent: { uploaded_media: Array<Record<string, unknown>>; batch_context: BatchContext };
  privateContent: { upload_status: WidgetUploadStatus; media_commit_status: BatchContext["media_commit_status"]; enrichment_status: BatchContext["enrichment_status"]; diagnostics: WidgetDiagnostic[] };
  imageIds: string[];
} {
  const batchContext = buildBatchContext(items, manifest, enrichmentStatus);
  return {
    modelContent: {
      uploaded_media: items.map((item) => ({
        media_id: item.media_id,
        attachment_id: item.attachment_id,
        public_filename: item.public_filename,
        status: item.status || "uploaded",
      })),
      batch_context: batchContext,
    },
    privateContent: {
      upload_status: uploadStatus,
      media_commit_status: batchContext.media_commit_status,
      enrichment_status: batchContext.enrichment_status,
      diagnostics: diagnostics.map((diagnostic) => ({
        ...diagnostic,
        ...(diagnostic.error ? { error: redactDiagnostic(diagnostic.error) } : {}),
      })),
    },
    imageIds: items.map((item) => item.file_id).filter((id): id is string => Boolean(id)),
  };
}
