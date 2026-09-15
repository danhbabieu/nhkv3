import { App } from "@modelcontextprotocol/ext-apps";
import { buildWidgetState, extractUploads, normalizeSelectedFiles, type SelectedImage, type UploadedItem } from "./contract";

const SERVER_TOOL_NAME = "nhk.media.widget-upload";
const IMAGE_TYPES = /^(image\/jpeg|image\/png|image\/gif|image\/webp)$/;
const IMAGE_ACCEPT = ["image/jpeg", "image/png", "image/gif", "image/webp"];
const STATES = ["CONNECTING", "READY", "UPLOADING", "SUCCESS", "ERROR"] as const;
type WidgetState = (typeof STATES)[number];

type ToolResult = {
  isError?: boolean;
  structuredContent?: unknown;
  content?: Array<{ type?: string; text?: string }>;
};

type ChatGptFileApi = {
  selectFiles?: () => Promise<unknown>;
  uploadFile?: (file: File, options?: { library?: boolean }) => Promise<{ fileId?: string }>;
  getFileDownloadUrl?: (options: { fileId: string }) => Promise<{ downloadUrl?: string }>;
  setWidgetState?: (state: unknown) => void | Promise<void>;
  sendFollowUpMessage?: (message: { role: "user"; content: Array<{ type: "text"; text: string }> }) => void | Promise<void>;
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

function asFiles(value: File[] | FileList | null | undefined): File[] {
  return Array.from(value ?? []);
}

function createIdempotencyKey(): string {
  return `chatgpt-widget-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

async function start(): Promise<void> {
  const input = byId<HTMLInputElement>("files");
  const select = byId<HTMLButtonElement>("select");
  const upload = byId<HTMLButtonElement>("upload");
  const use = byId<HTMLButtonElement>("use");
  const summary = byId<HTMLDivElement>("summary");
  const previews = byId<HTMLDivElement>("previews");
  const state = byId<HTMLSpanElement>("state");
  const status = byId<HTMLDivElement>("status");
  const results = byId<HTMLDivElement>("results");
  const host = window.openai;
  const app = new App({ name: "NHK Image Upload", version: "1.0.0" });
  let connected = false;
  let uploading = false;
  let selected: SelectedImage[] = [];
  let uploaded: UploadedItem[] = [];

  function setState(next: WidgetState, message: string): void {
    state.textContent = next;
    status.dataset.state = next;
    status.className = next === "ERROR" ? "failure" : next === "SUCCESS" ? "success" : "";
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
    summary.textContent = `${selected.length} image(s), ${(total / 1048576).toFixed(2)} MB`;
    upload.disabled = !connected || uploading || selected.length === 0 || !supportsFileUpload();
  }

  function renderUploads(items: UploadedItem[]): void {
    results.replaceChildren();
    items.forEach((item) => {
      const row = document.createElement("div");
      row.className = "success";
      const filename = item.public_filename || item.original_filename || "(unnamed image)";
      row.textContent = `Attachment ${item.attachment_id ?? "—"} · Media ${item.media_id ?? "—"} · ${filename} · ${item.status || "uploaded"}`;
      results.append(row);
    });
  }

  function saveWidgetState(): void {
    if (!connected || typeof host?.setWidgetState !== "function") return;
    void host.setWidgetState(buildWidgetState(uploaded));
  }

  function handleToolResult(result: ToolResult): void {
    if (result.isError) {
      setState("ERROR", "The NHK image tool returned an error.");
      return;
    }
    const items = extractUploads(result);
    if (items.length > 0) {
      uploaded = items;
      renderUploads(uploaded);
    }
  }

  async function uploadFiles(): Promise<void> {
    if (!connected || uploading || !supportsFileUpload() || selected.length === 0) return;
    uploading = true;
    uploaded = [];
    renderUploads([]);
    renderSelection();
    setState("UPLOADING", "Uploading selected images…");
    const references: Array<{ download_url: string; file_id: string; mime_type: string; file_name: string }> = [];

    try {
      for (const item of selected) {
        const fileName = item.kind === "local" ? item.file.name : item.fileName;
        setState("UPLOADING", `Uploading ${fileName}…`);
        const fileId = item.kind === "local"
          ? (await host!.uploadFile!(item.file, { library: false })).fileId
          : item.fileId;
        if (!fileId) throw new Error(`Upload did not return a file ID for ${fileName}.`);
        const download = await host!.getFileDownloadUrl!({ fileId });
        if (!download.downloadUrl) throw new Error(`Download URL was not returned for ${fileName}.`);
        references.push({ download_url: download.downloadUrl, file_id: fileId, mime_type: item.kind === "local" ? item.file.type : item.mimeType, file_name: fileName });
      }

      const result = await app.callServerTool({
        name: SERVER_TOOL_NAME,
        arguments: { idempotency_key: createIdempotencyKey(), files: references },
      });
      handleToolResult(result as ToolResult);
      if (uploaded.length === 0) throw new Error("The NHK image tool returned no uploaded images.");
      use.disabled = typeof host?.sendFollowUpMessage !== "function";
      saveWidgetState();
      setState("SUCCESS", `${uploaded.length} image(s) uploaded successfully.`);
    } catch (error) {
      setState("ERROR", `Image upload failed: ${errorMessage(error)}`);
    } finally {
      uploading = false;
      renderSelection();
    }
  }

  async function useImagesInChat(): Promise<void> {
    if (!connected || uploaded.length === 0 || typeof host?.sendFollowUpMessage !== "function") {
      setState("ERROR", "This ChatGPT host does not support sending a follow-up message.");
      return;
    }
    await host.sendFollowUpMessage({
      role: "user",
      content: [{ type: "text", text: `Use these uploaded NHK images in the next Capture: ${uploaded.map((item) => item.media_id).filter(Boolean).join(", ")}` }],
    });
  }

  app.ontoolresult = (result) => handleToolResult(result as ToolResult);
  app.onerror = (error) => setState("ERROR", `MCP Apps connection error: ${errorMessage(error)}`);
  input.addEventListener("change", () => {
    selected = asFiles(input.files)
      .filter((file) => IMAGE_TYPES.test(file.type))
      .map((file) => ({ kind: "local" as const, file }));
    renderSelection();
  });
  select.addEventListener("click", async () => {
    if (!connected || typeof host?.selectFiles !== "function") return;
    try {
      selected = normalizeSelectedFiles(await host.selectFiles());
      renderSelection();
    } catch (error) {
      setState("ERROR", `File selection failed: ${errorMessage(error)}`);
    }
  });
  upload.addEventListener("click", () => void uploadFiles());
  use.addEventListener("click", () => void useImagesInChat());

  setState("CONNECTING", "Connecting to the MCP Apps host…");
  try {
    await app.connect();
    connected = true;
    input.disabled = false;
    select.hidden = typeof host?.selectFiles !== "function";
    select.disabled = typeof host?.selectFiles !== "function";
    use.disabled = true;
    setState("READY", supportsFileUpload()
      ? "Ready. Select one or more images to upload."
      : "Connected, but this host does not support uploadFile and getFileDownloadUrl.");
    renderSelection();
  } catch (error: unknown) {
    connected = false;
    input.disabled = true;
    select.disabled = true;
    upload.disabled = true;
    use.disabled = true;
    setState("ERROR", `Could not connect to the MCP Apps host: ${errorMessage(error)}`);
  }
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => void start(), { once: true });
else void start();
