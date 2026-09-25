export type HostFileApi = {
  uploadFile: (file: File, options?: { library?: boolean }) => Promise<{ fileId?: unknown }>;
  getFileDownloadUrl: (options: { fileId: string }) => Promise<{ downloadUrl?: unknown }>;
};

export type HostUploadInput = {
  ordinal: number;
  file: File | null;
  fileId?: string;
  fileName?: string;
  mimeType?: string;
};

export type HostUploadError = {
  code: string;
  class: "TIMEOUT" | "REJECTED" | "FAILED" | "INVALID_REFERENCE";
  retryable: boolean;
};

export type HostUploadOutcome =
  | { ordinal: number; status: "HOST_UPLOADED"; fileId: string; downloadUrl: string; file: File | null; fileName: string; mimeType: string; attempts: number }
  | { ordinal: number; status: "FAILED_RETRYABLE" | "FAILED_PERMANENT"; file: File | null; fileName: string; mimeType: string; attempts: number; error: HostUploadError };

type Options = HostFileApi & {
  concurrency?: number;
  timeoutMs?: number;
  maxRetries?: number;
  onAttempt?: (input: HostUploadInput, attempt: number) => void;
};

const DEFAULT_CONCURRENCY = 2;
const DEFAULT_TIMEOUT_MS = 30_000;
const DEFAULT_MAX_RETRIES = 2;
const SAFE_CODE = /^[A-Z][A-Z0-9_]{2,}$/;

function errorText(error: unknown): string {
  if (error instanceof Error) return error.message;
  if (typeof error === "string") return error;
  if (error && typeof error === "object") {
    const value = error as Record<string, unknown>;
    return [value.code, value.reason_code, value.reasonCode, value.error_code, value.name, value.message]
      .find((candidate): candidate is string => typeof candidate === "string" && candidate !== "") ?? "HOST_FILE_UPLOAD_FAILED";
  }
  return "HOST_FILE_UPLOAD_FAILED";
}

function codeFrom(error: unknown): string {
  const text = errorText(error);
  const match = text.match(/[A-Z][A-Z0-9_]{2,}/);
  return match && SAFE_CODE.test(match[0]) ? match[0] : "HOST_FILE_UPLOAD_FAILED";
}

function classify(error: unknown): HostUploadError {
  const code = codeFrom(error);
  const upper = `${code} ${errorText(error)}`.toUpperCase();
  if (upper.includes("TIMEOUT") || upper.includes("TIMED_OUT") || upper.includes("ABORT")) {
    return { code: "HOST_FILE_UPLOAD_TIMEOUT", class: "TIMEOUT", retryable: true };
  }
  if (upper.includes("REJECT") || upper.includes("NOT_ALLOWED") || upper.includes("PERMISSION_DENIED") || upper.includes("UNSUPPORTED") || upper.includes("INVALID_FILE") || upper.includes("QUOTA") || upper.includes("SIZE_LIMIT") || upper.includes("FILE_TOO_LARGE")) {
    return { code, class: "REJECTED", retryable: false };
  }
  return { code, class: "FAILED", retryable: true };
}

function invalidReference(code: string): HostUploadError {
  return { code, class: "INVALID_REFERENCE", retryable: true };
}

async function withTimeout<T>(operation: Promise<T>, timeoutMs: number): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    return await Promise.race([
      operation,
      new Promise<T>((_resolve, reject) => {
        timer = setTimeout(() => reject(new Error("HOST_FILE_UPLOAD_TIMEOUT")), timeoutMs);
      }),
    ]);
  } finally {
    if (timer !== undefined) clearTimeout(timer);
  }
}

async function uploadOne(input: HostUploadInput, options: Options): Promise<HostUploadOutcome> {
  const maxAttempts = (options.maxRetries ?? DEFAULT_MAX_RETRIES) + 1;
  let attempts = 0;
  while (attempts < maxAttempts) {
    attempts += 1;
    options.onAttempt?.(input, attempts);
    try {
      const uploaded = input.file
        ? await withTimeout(options.uploadFile(input.file, { library: false }), options.timeoutMs ?? DEFAULT_TIMEOUT_MS)
        : { fileId: input.fileId };
      const fileId = typeof uploaded?.fileId === "string" && uploaded.fileId !== "" ? uploaded.fileId : null;
      if (!fileId) throw Object.assign(new Error("HOST_FILE_UPLOAD_INVALID_REFERENCE"), { reference: true });
      const download = await withTimeout(options.getFileDownloadUrl({ fileId }), options.timeoutMs ?? DEFAULT_TIMEOUT_MS);
      const downloadUrl = typeof download?.downloadUrl === "string" ? download.downloadUrl : "";
      if (!downloadUrl || !/^https:\/\//i.test(downloadUrl)) throw Object.assign(new Error("HOST_FILE_UPLOAD_INVALID_REFERENCE"), { reference: true });
      return { ordinal: input.ordinal, status: "HOST_UPLOADED", fileId, downloadUrl, file: input.file, fileName: input.file?.name ?? input.fileName ?? "", mimeType: input.file?.type ?? input.mimeType ?? "", attempts };
    } catch (error) {
      const classified = (error && typeof error === "object" && "reference" in error)
        ? invalidReference("HOST_FILE_UPLOAD_INVALID_REFERENCE")
        : classify(error);
      if (!classified.retryable || attempts >= maxAttempts) {
        return { ordinal: input.ordinal, status: classified.retryable ? "FAILED_RETRYABLE" : "FAILED_PERMANENT", file: input.file, fileName: input.file?.name ?? input.fileName ?? "", mimeType: input.file?.type ?? input.mimeType ?? "", attempts, error: classified };
      }
    }
  }
  throw new Error("HOST_FILE_UPLOAD_INTERNAL_UNREACHABLE");
}

export async function uploadSelectedFiles(inputs: HostUploadInput[], options: Options): Promise<HostUploadOutcome[]> {
  const concurrency = Math.max(1, Math.min(4, Math.floor(options.concurrency ?? DEFAULT_CONCURRENCY)));
  const outcomes: HostUploadOutcome[] = [];
  let cursor = 0;
  async function worker(): Promise<void> {
    while (true) {
      const index = cursor++;
      if (index >= inputs.length) return;
      outcomes[index] = await uploadOne(inputs[index], options);
    }
  }
  await Promise.allSettled(Array.from({ length: Math.min(concurrency, inputs.length) }, () => worker()));
  return outcomes;
}
