"use client";
import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import Dialog from "./dialog";
export default function FilePreview({
  file,
  onClose,
  onTask,
}: {
  file: File;
  onClose: () => void;
  onTask: (id: number) => void;
}) {
  const action = useRef<AbortController | null>(null);
  const [url, setUrl] = useState("");
  const [kind, setKind] = useState(
    file.type.startsWith("image/")
      ? "vision"
      : file.type.startsWith("audio/") || file.name.endsWith(".webm")
        ? "transcription"
        : "document",
  );
  const [prompt, setPrompt] = useState("Describe this file");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [kinds, setKinds] = useState<string[]>([]);
  useEffect(() => {
    const u = URL.createObjectURL(file);
    setUrl(u);
    const c = new AbortController();
    api<{ media: string[] }>("capabilities", "GET", undefined, c.signal)
      .then((x) => setKinds(x.media))
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => {
      action.current?.abort();
      URL.revokeObjectURL(u);
      c.abort();
    };
  }, [file]);
  return (
    <Dialog
      title={file.name}
      onClose={() => {
        if (!busy) onClose();
      }}
      wide
    >
      <p className="muted">
        {(file.size / 1000000).toFixed(2)} MB. Processing creates a private
        library task. Review extracted text before sending it to chat.
      </p>
      {url && file.type.startsWith("image/") && (
        <img className="media-preview" src={url} alt={file.name} />
      )}{" "}
      {url &&
        (file.type === "application/pdf" ||
          file.name.toLowerCase().endsWith(".pdf")) && (
          <>
            <iframe
              className="pdf-preview"
              title="Local PDF preview"
              src={url}
              sandbox=""
            />
            <a href={url} download={file.name}>
              Download local PDF if the browser cannot preview it
            </a>
          </>
        )}
      {url && file.type.startsWith("audio/") && <audio controls src={url} />}{" "}
      {file.name.toLowerCase().endsWith(".docx") && (
        <p>DOCX preview is available as extracted text after processing.</p>
      )}
      {error && <p role="alert">{error}</p>}
      <form
        className="form-stack"
        onSubmit={async (e) => {
          e.preventDefault();
          const controller = new AbortController();
          action.current = controller;
          setBusy(true);
          setError("");
          try {
            if (file.size > 8000000) throw new Error("File exceeds 8 MB.");
            const input = await new Promise<string>((resolve, reject) => {
              const r = new FileReader();
              r.onload = () => resolve(String(r.result).split(",")[1]);
              r.onerror = () => reject(new Error("Unable to read file."));
              r.readAsDataURL(file);
            });
            if (controller.signal.aborted) return;
            const task = await api<{ id: number }>(
              "media",
              "POST",
              {
                kind,
                prompt: kind === "vision" ? prompt : "",
                input,
              },
              controller.signal,
            );
            if (controller.signal.aborted) return;
            onTask(task.id);
            onClose();
          } catch (e) {
            if (!controller.signal.aborted) setError((e as Error).message);
          } finally {
            if (!controller.signal.aborted) setBusy(false);
          }
        }}
      >
        <label>
          Process as
          <select value={kind} onChange={(e) => setKind(e.target.value)}>
            <option value="vision">Image understanding</option>
            <option value="document">Document text extraction</option>
            <option value="transcription">Audio transcription</option>
          </select>
        </label>
        {kind === "vision" && (
          <label>
            Question
            <textarea
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              maxLength={4000}
              required
            />
          </label>
        )}
        <button
          className="primary-button"
          disabled={busy || !kinds.includes(kind)}
        >
          {busy
            ? "Uploading…"
            : kinds.includes(kind)
              ? "Process privately"
              : "This engine is not configured"}
        </button>
      </form>
    </Dialog>
  );
}
