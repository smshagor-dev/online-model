"use client";
import { useEffect, useRef, useState } from "react";
import { api, type Page } from "@/lib/api";
import Dialog from "./dialog";
import ResponseMarkdown from "./response-markdown";

type Kind =
  "image" | "vision" | "transcription" | "speech" | "video" | "document";
type Task = {
  id: number;
  kind: Kind;
  prompt: string;
  status: string;
  result_text: string | null;
  output_mime: string | null;
  error: string | null;
};
const labels: Record<Kind, string> = {
  document: "Read PDF or DOCX",
  image: "Create image",
  vision: "Ask about an image",
  transcription: "Transcribe audio",
  speech: "Create speech",
  video: "Create short video",
};

export default function MediaDialog({
  onClose,
  onUse,
}: {
  onClose: () => void;
  onUse: (text: string) => void;
}) {
  const [kinds, setKinds] = useState<Kind[]>([]);
  const [kind, setKind] = useState<Kind>("image");
  const [tasks, setTasks] = useState<Task[]>([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [selected, setSelected] = useState<number | null>(null);
  const [prompt, setPrompt] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [preview, setPreview] = useState("");
  const [revision, setRevision] = useState(0);
  const controller = useRef<AbortController | null>(null);
  const task = tasks.find((t) => t.id === selected);
  const needsFile =
    kind === "vision" || kind === "transcription" || kind === "document";

  useEffect(() => () => controller.current?.abort(), []);
  useEffect(() => {
    const c = new AbortController();
    api<{ media: Kind[] }>("capabilities", "GET", undefined, c.signal)
      .then((caps) => {
        setKinds(caps.media || []);
        if (caps.media?.length) setKind(caps.media[0]);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, []);
  useEffect(() => {
    const c = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    async function load() {
      try {
        const result = await api<Page<Task>>(
          `media?page=${page}`,
          "GET",
          undefined,
          c.signal,
        );
        if (c.signal.aborted) return;
        setTasks(result.data);
        setPages(result.last_page);
        if (result.data.some((t) => ["queued", "running"].includes(t.status)))
          timer = setTimeout(load, 4000);
      } catch (e) {
        if (!c.signal.aborted) setError((e as Error).message);
      }
    }
    void load();
    return () => {
      c.abort();
      clearTimeout(timer);
    };
  }, [page, revision]);
  useEffect(() => {
    const c = new AbortController();
    let url = "";
    setPreview("");
    if (task?.status === "complete" && task.output_mime) {
      fetch(`/api/backend/media/${task.id}/output`, {
        signal: c.signal,
        cache: "no-store",
      })
        .then(async (response) => {
          if (!response.ok)
            throw new Error("Unable to load this private result.");
          const blob = await response.blob();
          if (!c.signal.aborted) {
            url = URL.createObjectURL(blob);
            setPreview(url);
          }
        })
        .catch((e) => {
          if (!c.signal.aborted) setError(e.message);
        });
    }
    return () => {
      c.abort();
      if (url) URL.revokeObjectURL(url);
    };
  }, [task?.id, task?.status, task?.output_mime]);

  async function create() {
    const c = new AbortController();
    controller.current = c;
    setBusy(true);
    setError("");
    try {
      let input: string | undefined;
      if (needsFile) {
        if (!file || file.size > 8_000_000)
          throw new Error(
            "Choose a file up to 8 MB. Audio must be at most 2 minutes.",
          );
        input = await new Promise<string>((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(String(reader.result).split(",")[1]);
          reader.onerror = () => reject(new Error("Unable to read this file."));
          reader.readAsDataURL(file);
        });
      }
      if (c.signal.aborted) return;
      const result = await api<Task>(
        "media",
        "POST",
        { kind, prompt, input },
        c.signal,
      );
      if (!c.signal.aborted) {
        setSelected(result.id);
        setPage(1);
        setRevision((r) => r + 1);
        setFile(null);
      }
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  async function remove(id: number) {
    const c = new AbortController();
    controller.current = c;
    setBusy(true);
    setError("");
    try {
      await api(`media/${id}`, "DELETE", undefined, c.signal);
      if (!c.signal.aborted) {
        setSelected(null);
        setRevision((r) => r + 1);
      }
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  return (
    <Dialog title="Media studio" onClose={onClose} wide>
      <p className="muted">
        Private self-hosted generation. Uploaded inputs are deleted after
        processing. Results remain until you delete them. Closing this window
        keeps queued tasks running.
      </p>
      {error && (
        <p role="alert" className="form-error">
          {error}
        </p>
      )}
      {kinds.length ? (
        <form
          className="media-form"
          onSubmit={(e) => {
            e.preventDefault();
            void create();
          }}
        >
          <label>
            Tool
            <select
              value={kind}
              onChange={(e) => {
                setKind(e.target.value as Kind);
                setFile(null);
              }}
              disabled={busy}
            >
              {kinds.map((k) => (
                <option key={k} value={k}>
                  {labels[k]}
                </option>
              ))}
            </select>
          </label>
          <label>
            {kind === "speech" ? "Text to speak" : "Prompt"}
            <textarea
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              maxLength={kind === "speech" ? 1000 : 4000}
              required={kind !== "transcription" && kind !== "document"}
              rows={3}
              disabled={busy}
            />
          </label>
          {needsFile && (
            <label>
              {kind === "document"
                ? "Document (PDF or DOCX, up to 8 MB)"
                : kind === "vision"
                  ? "Image (PNG, JPEG, WebP; up to 16 MP)"
                  : "Audio (WAV, MP3, FLAC, OGG, WebM; up to 2 minutes)"}
              <input
                key={kind}
                type="file"
                accept={
                  kind === "document"
                    ? ".pdf,.docx"
                    : kind === "vision"
                      ? "image/png,image/jpeg,image/webp"
                      : "audio/*,.webm"
                }
                onChange={(e) => setFile(e.target.files?.[0] || null)}
                disabled={busy}
              />
              {file?.name}
            </label>
          )}
          <button className="primary-button" disabled={busy}>
            {busy ? "Submitting…" : "Start task"}
          </button>
        </form>
      ) : (
        <p role="status">
          No media engine is enabled. An administrator must provision a
          compatible Hugging Face model and connect the private runtime.
        </p>
      )}
      <div className="media-history">
        <label>
          Saved tasks
          <select
            value={selected ?? ""}
            onChange={(e) => setSelected(Number(e.target.value) || null)}
          >
            <option value="">Select a task</option>
            {tasks.map((t) => (
              <option key={t.id} value={t.id}>
                {labels[t.kind]} · {t.status} ·{" "}
                {t.prompt.slice(0, 45) || `#${t.id}`}
              </option>
            ))}
          </select>
        </label>
        <div className="media-pagination">
          <button
            disabled={page <= 1}
            onClick={() => {
              setPage((p) => p - 1);
              setSelected(null);
            }}
          >
            Previous
          </button>
          <span>
            {page} / {pages}
          </span>
          <button
            disabled={page >= pages}
            onClick={() => {
              setPage((p) => p + 1);
              setSelected(null);
            }}
          >
            Next
          </button>
        </div>
        {task && (
          <section aria-label="Task result" className="media-result">
            <p role="status">
              {labels[task.kind]} · {task.status}
            </p>
            <p>{task.prompt}</p>
            {task.error && <p role="alert">{task.error}</p>}
            {task.result_text && (
              <>
                <ResponseMarkdown content={task.result_text} />
                <button onClick={() => onUse(task.result_text!)}>
                  Use result in chat
                </button>
              </>
            )}
            {preview && task.output_mime === "image/png" && (
              <img className="media-preview" src={preview} alt={task.prompt} />
            )}
            {preview && task.output_mime === "audio/wav" && (
              <audio controls src={preview} />
            )}
            {preview && task.output_mime === "video/mp4" && (
              <video className="media-preview" controls src={preview} />
            )}
            {preview && (
              <a
                href={preview}
                download={`openmindai-${task.id}.${task.kind === "image" ? "png" : task.kind === "speech" ? "wav" : "mp4"}`}
              >
                Download result
              </a>
            )}
            <button
              disabled={busy}
              onClick={() => {
                if (
                  confirm(
                    "Delete this task and its private files? Running inference may finish, but its result will be discarded.",
                  )
                )
                  void remove(task.id);
              }}
            >
              Delete task & files
            </button>
          </section>
        )}
      </div>
    </Dialog>
  );
}
