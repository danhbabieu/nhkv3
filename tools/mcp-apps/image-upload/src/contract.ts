export type UploadedItem = {
  attachment_id?: number;
  media_id?: string;
  public_filename?: string;
  original_filename?: string;
  file_id?: string;
  status?: string;
  download_url?: string;
};

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
