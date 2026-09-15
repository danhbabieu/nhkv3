export type UploadedItem = {
  attachment_id?: number;
  media_id?: string;
  public_filename?: string;
  original_filename?: string;
  file_id?: string;
  status?: string;
  download_url?: string;
};

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
};

export function extractUploads(result: ToolResult): UploadedItem[] {
  const structured = result.structuredContent;
  if (structured && typeof structured === "object" && Array.isArray((structured as { uploads?: unknown }).uploads)) {
    return (structured as { uploads: UploadedItem[] }).uploads;
  }

  const text = result.content?.find((item) => item.type === "text")?.text;
  if (!text) return [];
  try {
    const parsed: unknown = JSON.parse(text);
    return parsed && typeof parsed === "object" && Array.isArray((parsed as { uploads?: unknown }).uploads)
      ? (parsed as { uploads: UploadedItem[] }).uploads
      : [];
  } catch {
    return [];
  }
}

export function buildWidgetState(items: UploadedItem[]): {
  modelContent: { uploaded_media: Array<Record<string, unknown>> };
  privateContent: { upload_status: "complete" };
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
    privateContent: { upload_status: "complete" },
    imageIds: items.map((item) => item.file_id).filter((id): id is string => Boolean(id)),
  };
}
