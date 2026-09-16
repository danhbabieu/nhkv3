export type UploadedItem = {
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
  requested_count: number;
  success_count: number;
  failure_count: number;
  items: UploadedItem[];
};

export type WidgetDiagnostic = {
  stage: string;
  status: "START" | "DONE" | "ERROR";
  code?: string;
  error?: string;
  uri?: string;
  tool?: string;
};

export type WidgetUploadStatus = "idle" | "complete" | "error";

export type SelectedImage =
  | { kind: "local"; file: File }
  | { kind: "library"; fileId: string; fileName: string; mimeType: string };

export function normalizeSelectedFiles(value: unknown): SelectedImage[] {
  if (!Array.isArray(value)) return [];

  return value.flatMap((item): SelectedImage[] => {
    if (!item || typeof item !== "object") return [];
    const reference = item as { fileId?: unknown; fileName?: unknown; mimeType?: unknown };
    if (typeof reference.fileId !== "string" || reference.fileId === "") return [];
    if (typeof reference.fileName !== "string" || reference.fileName === "") return [];
    if (typeof reference.mimeType !== "string" || reference.mimeType === "") return [];
    return [{ kind: "library", fileId: reference.fileId, fileName: reference.fileName, mimeType: reference.mimeType }];
  });
}

export type ToolResult = {
  isError?: unknown;
  structuredContent?: unknown;
  content?: Array<{ type?: string; text?: string }>;
  result?: unknown;
};

export type ToolResultInspection =
  | { kind: "success"; payload: unknown }
  | { kind: "error"; code: string }
  | { kind: "malformed"; code: "MCP_RESULT_MALFORMED" };

const SAFE_ERROR_CODE = /^[A-Z][A-Z0-9_]{2,}$/;

function errorCode(value: unknown): string | null {
  if (typeof value === "string") {
    const code = value.match(/^[A-Z][A-Z0-9_]{2,}/)?.[0];
    return code && SAFE_ERROR_CODE.test(code) ? code : null;
  }
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;
  const record = value as Record<string, unknown>;
  for (const key of ["code", "reason_code", "reasonCode"]) {
    const code = errorCode(record[key]);
    if (code) return code;
  }
  return errorCode(record.error);
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

function inspectValue(value: unknown, depth = 0): ToolResultInspection {
  if (depth > 6) return { kind: "malformed", code: "MCP_RESULT_MALFORMED" };
  if (typeof value === "string") {
    try {
      return inspectValue(JSON.parse(value) as unknown, depth + 1);
    } catch {
      const code = errorCode(value);
      return code ? { kind: "error", code } : { kind: "malformed", code: "MCP_RESULT_MALFORMED" };
    }
  }
  if (!value || typeof value !== "object" || Array.isArray(value)) return { kind: "success", payload: value };
  const record = value as Record<string, unknown>;
  if (record.isError === true) return { kind: "error", code: errorCode(record) ?? errorCode(record.structuredContent) ?? errorCode(record.result) ?? "SERVER_TOOL_ERROR" };
  const nestedError = errorCode(record.error);
  if (record.error !== undefined && nestedError) return { kind: "error", code: nestedError };
  for (const nested of [record.structuredContent, record.result]) {
    if (nested === undefined) continue;
    const inspection = inspectValue(nested, depth + 1);
    if (inspection.kind !== "success") return inspection;
    return inspection;
  }
  return { kind: "success", payload: value };
}

export function inspectToolResult(result: ToolResult): ToolResultInspection {
  if (result.isError === true) return { kind: "error", code: errorCode(result) ?? errorCode(result.structuredContent) ?? errorCode(result.result) ?? errorCode(parsedText(result)) ?? "SERVER_TOOL_ERROR" };
  for (const candidate of [result.structuredContent, result.result, parsedText(result)]) {
    if (candidate === undefined) continue;
    return inspectValue(candidate);
  }
  return { kind: "malformed", code: "MCP_RESULT_MALFORMED" };
}

export function extractPayload(result: ToolResult): unknown {
  const nested = result.structuredContent ?? result.result;
  if (nested !== undefined) {
    if (nested && typeof nested === "object" && !Array.isArray(nested)) {
      const envelope = nested as { structuredContent?: unknown; result?: unknown };
      if (envelope.structuredContent !== undefined) return extractPayload(envelope as ToolResult);
      if (envelope.result !== undefined) return extractPayload(envelope as ToolResult);
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
  const status = statusValue === "CREATED" ? "SUCCESS" : typeof statusValue === "string" && statusValue !== "" ? statusValue : fallbackStatus;
  const item: UploadedItem = {
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
    ...(typeof record.error_code === "string" ? { error_code: record.error_code } : typeof record.code === "string" && SAFE_ERROR_CODE.test(record.code) ? { error_code: record.code } : {}),
    status,
  };
  const fileId = typeof record.file_id === "string" ? record.file_id : typeof record.client_file_id === "string" ? record.client_file_id : undefined;
  if (fileId && !fileId.includes("://")) item.file_id = fileId;
  return item;
}

export function extractUploadManifest(result: ToolResult): UploadManifest {
  const inspection = inspectToolResult(result);
  if (inspection.kind !== "success") throw new Error(inspection.code);
  if (!inspection.payload || typeof inspection.payload !== "object" || Array.isArray(inspection.payload)) throw new Error("MCP_RESULT_MALFORMED");
  const record = inspection.payload as Record<string, unknown>;
  const rawItems = Array.isArray(record.items) ? record.items : Array.isArray(record.uploads) ? record.uploads : null;
  if (!rawItems) throw new Error("MCP_RESULT_MALFORMED");
  const items = rawItems.flatMap((item) => {
    const safeItem = safeUploadedItem(item, "SUCCESS");
    return safeItem ? [safeItem] : [];
  });
  const requested = typeof record.requested_count === "number" ? record.requested_count : rawItems.length;
  const success = typeof record.success_count === "number" ? record.success_count : items.filter((item) => item.status === "SUCCESS").length;
  const failure = typeof record.failure_count === "number" ? record.failure_count : Math.max(0, requested - success);
  if (!Number.isInteger(requested) || !Number.isInteger(success) || !Number.isInteger(failure) || requested < 0 || success < 0 || failure < 0) throw new Error("MCP_RESULT_MALFORMED");
  return { requested_count: requested, success_count: success, failure_count: failure, items };
}

export function assertUploadManifestCount(manifest: UploadManifest, expected: number): void {
  if (manifest.requested_count !== expected || manifest.success_count !== expected || manifest.failure_count !== 0 || manifest.items.length !== expected || manifest.items.some((item) => item.status !== "SUCCESS")) throw new Error("MEDIA_READBACK_COUNT_MISMATCH");
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

export function buildWidgetState(items: UploadedItem[], diagnostics: WidgetDiagnostic[] = [], uploadStatus: WidgetUploadStatus = items.length > 0 ? "complete" : "idle"): {
  modelContent: { uploaded_media: Array<Record<string, unknown>> };
  privateContent: { upload_status: WidgetUploadStatus; diagnostics: WidgetDiagnostic[] };
  imageIds: string[];
} {
  return {
    modelContent: {
      uploaded_media: items.map((item) => ({
        media_id: item.media_id,
        attachment_id: item.attachment_id,
        public_filename: item.public_filename,
        status: item.status || "uploaded",
      })),
    },
    privateContent: {
      upload_status: uploadStatus,
      diagnostics: diagnostics.map((diagnostic) => ({
        ...diagnostic,
        ...(diagnostic.error ? { error: redactDiagnostic(diagnostic.error) } : {}),
      })),
    },
    imageIds: items.map((item) => item.file_id).filter((id): id is string => Boolean(id)),
  };
}
