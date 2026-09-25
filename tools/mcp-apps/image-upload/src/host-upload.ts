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
  onAttempt?: (input: HostUploadInput, attempt: number) => void;
};
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

async function uploadOne(input: HostUploadInput, options: Options): Promise<HostUploadOutcome> {
  const attempts = 1;
  options.onAttempt?.(input, attempts);
  try {
    const uploaded = input.file ? await options.uploadFile(input.file, { library: false }) : { fileId: input.fileId };
    const fileId = typeof uploaded?.fileId === "string" && uploaded.fileId !== "" ? uploaded.fileId : null;
    if (!fileId) throw Object.assign(new Error("HOST_FILE_UPLOAD_INVALID_REFERENCE"), { reference: true });
    const download = await options.getFileDownloadUrl({ fileId });
    const downloadUrl = typeof download?.downloadUrl === "string" ? download.downloadUrl : "";
    if (!downloadUrl || !/^https:\/\//i.test(downloadUrl)) throw Object.assign(new Error("HOST_FILE_UPLOAD_INVALID_REFERENCE"), { reference: true });
    return { ordinal: input.ordinal, status: "HOST_UPLOADED", fileId, downloadUrl, file: input.file, fileName: input.file?.name ?? input.fileName ?? "", mimeType: input.file?.type ?? input.mimeType ?? "", attempts };
  } catch (error) {
    const classified = (error && typeof error === "object" && "reference" in error)
      ? invalidReference("HOST_FILE_UPLOAD_INVALID_REFERENCE")
      : classify(error);
    return { ordinal: input.ordinal, status: classified.retryable ? "FAILED_RETRYABLE" : "FAILED_PERMANENT", file: input.file, fileName: input.file?.name ?? input.fileName ?? "", mimeType: input.file?.type ?? input.mimeType ?? "", attempts, error: classified };
  }
}

export async function uploadSelectedFiles(inputs: HostUploadInput[], options: Options): Promise<HostUploadOutcome[]> {
  const outcomes: HostUploadOutcome[] = [];
  for (const [index, input] of inputs.entries()) {
    outcomes[index] = await uploadOne(input, options);
  }
  return outcomes;
}
