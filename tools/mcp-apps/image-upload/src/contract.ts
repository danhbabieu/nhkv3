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

type ToolResult = {
  structuredContent?: unknown;
  content?: Array<{ type?: string; text?: string }>;
  result?: unknown;
};

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

function uploadsFromValue(value: unknown): UploadedItem[] | null {
  if (!value || typeof value !== "object") return null;
  const record = value as { uploads?: unknown; structuredContent?: unknown; result?: unknown };
  if (Array.isArray(record.uploads)) return record.uploads as UploadedItem[];
  for (const nested of [record.structuredContent, record.result]) {
    const uploads = uploadsFromValue(nested);
    if (uploads !== null) return uploads;
  }
  return null;
}

export function extractUploads(result: ToolResult): UploadedItem[] {
  const structuredUploads = uploadsFromValue(extractPayload(result)) ?? uploadsFromValue(result);
  if (structuredUploads !== null) return structuredUploads;

  const text = result.content?.find((item) => item.type === "text")?.text;
  if (!text) return [];
  try {
    const parsed: unknown = JSON.parse(text);
    return uploadsFromValue(parsed) ?? [];
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
