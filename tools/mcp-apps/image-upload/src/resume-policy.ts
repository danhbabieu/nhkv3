export type ResumeItem = {
  ordinal?: number;
  client_file_id?: string;
  file_id?: string;
  media_id?: string;
  status?: string;
};

export type SubmissionResumeInput = {
  media_commit_status?: string;
  enrichment_status?: string;
  intent?: string;
  items: ResumeItem[];
  capture?: { id?: string; exists?: boolean; status?: string };
  article?: { id?: number | string; exists?: boolean; status?: string };
  media_usages?: Array<{ media_id?: string; status?: string; complete?: boolean }>;
  projection?: { status?: string };
};

export type SubmissionResumePlan = {
  phase: "MATERIALIZE_MEDIA" | "CONTINUE_ENRICHMENT" | "REPAIR_MEDIA_USAGE" | "RETRY_PROJECTION" | "NOOP";
  materializeOrdinals: number[];
  hostUploadOrdinals: number[];
  mediaUploadOrdinals: number[];
  canonicalMediaIds: string[];
  usageMediaIds: string[];
  captureAction: "ENSURE_ONE" | "REUSE";
  articleAction: "ENSURE_ONE" | "REUSE";
};

function normalizedStatus(value: unknown): string {
  return String(value ?? "").trim().toUpperCase();
}

function orderedItems(items: ResumeItem[]): ResumeItem[] {
  return items
    .map((item, index) => ({ item, index }))
    .sort((left, right) => {
      const leftOrdinal = Number.isInteger(left.item.ordinal) ? Number(left.item.ordinal) : Number.MAX_SAFE_INTEGER;
      const rightOrdinal = Number.isInteger(right.item.ordinal) ? Number(right.item.ordinal) : Number.MAX_SAFE_INTEGER;
      if (leftOrdinal !== rightOrdinal) return leftOrdinal - rightOrdinal;
      const leftClient = String(left.item.client_file_id ?? "");
      const rightClient = String(right.item.client_file_id ?? "");
      if (leftClient !== rightClient) return leftClient.localeCompare(rightClient);
      const leftMedia = String(left.item.media_id ?? "");
      const rightMedia = String(right.item.media_id ?? "");
      if (leftMedia !== rightMedia) return leftMedia.localeCompare(rightMedia);
      return left.index - right.index;
    })
    .map(({ item }) => item);
}

function itemNeedsMaterialization(item: ResumeItem): boolean {
  const status = normalizedStatus(item.status);
  return !item.media_id || ["LOCAL", "HOST_UPLOADED", "FAILED_RETRYABLE", "FAILED", "PARTIAL"].includes(status);
}

function ordinalOf(item: ResumeItem, fallback: number): number {
  return Number.isInteger(item.ordinal) ? Number(item.ordinal) : fallback;
}

function usageIsComplete(usage: { status?: string; complete?: boolean } | undefined): boolean {
  return usage?.complete === true || ["COMPLETE", "APPLIED", "VERIFIED"].includes(normalizedStatus(usage?.status));
}

export function planSubmissionResume(input: SubmissionResumeInput): SubmissionResumePlan {
  const items = orderedItems(input.items);
  const canonicalMediaIds = items
    .map((item) => String(item.media_id ?? "").trim())
    .filter((mediaId, index, all) => mediaId !== "" && all.indexOf(mediaId) === index);
  const materializeOrdinals = items
    .filter(itemNeedsMaterialization)
    .map((item, index) => ordinalOf(item, index))
    .sort((left, right) => left - right);
  const materializeItems = items.filter(itemNeedsMaterialization);
  const hostUploadOrdinals = materializeItems
    .filter((item) => String(item.file_id ?? "").trim() === "")
    .map((item, index) => ordinalOf(item, index))
    .sort((left, right) => left - right);
  const mediaUploadOrdinals = [...materializeOrdinals];
  const usages = new Map(
    (input.media_usages ?? [])
      .map((usage) => [String(usage.media_id ?? "").trim(), usage] as const)
      .filter(([mediaId]) => mediaId !== ""),
  );
  const usageMediaIds = canonicalMediaIds.filter((mediaId) => !usageIsComplete(usages.get(mediaId)));
  const mediaComplete = normalizedStatus(input.media_commit_status) === "COMPLETE" && materializeOrdinals.length === 0;
  const captureExists = input.capture?.exists === true || input.capture?.id !== undefined;
  const captureAction = captureExists ? "REUSE" : "ENSURE_ONE";
  const articleExists = input.article?.exists === true || input.article?.id !== undefined;
  const articleAction = articleExists ? "REUSE" : "ENSURE_ONE";
  const projectionStatus = normalizedStatus(input.projection?.status);

  if (!mediaComplete) {
    if (materializeOrdinals.length === 0) {
      return { phase: "CONTINUE_ENRICHMENT", materializeOrdinals: [], hostUploadOrdinals: [], mediaUploadOrdinals: [], canonicalMediaIds, usageMediaIds: [], captureAction, articleAction };
    }
    return { phase: "MATERIALIZE_MEDIA", materializeOrdinals, hostUploadOrdinals, mediaUploadOrdinals, canonicalMediaIds, usageMediaIds: [], captureAction, articleAction };
  }
  if (usageMediaIds.length > 0 && articleExists) {
    return { phase: "REPAIR_MEDIA_USAGE", materializeOrdinals: [], hostUploadOrdinals: [], mediaUploadOrdinals: [], canonicalMediaIds, usageMediaIds, captureAction, articleAction };
  }
  if (["FAILED_RETRYABLE", "FAILED", "PARTIAL"].includes(projectionStatus)) {
    return { phase: "RETRY_PROJECTION", materializeOrdinals: [], hostUploadOrdinals: [], mediaUploadOrdinals: [], canonicalMediaIds, usageMediaIds: [], captureAction, articleAction };
  }
  if (normalizedStatus(input.enrichment_status) !== "COMPLETE" || !articleExists) {
    return { phase: "CONTINUE_ENRICHMENT", materializeOrdinals: [], hostUploadOrdinals: [], mediaUploadOrdinals: [], canonicalMediaIds, usageMediaIds: [], captureAction, articleAction };
  }
  return { phase: "NOOP", materializeOrdinals: [], hostUploadOrdinals: [], mediaUploadOrdinals: [], canonicalMediaIds, usageMediaIds: [], captureAction, articleAction };
}
