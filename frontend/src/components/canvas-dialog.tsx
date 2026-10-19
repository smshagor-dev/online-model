"use client";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import Dialog from "./dialog";
import ResponseMarkdown from "./response-markdown";
type Canvas = {
  id: number;
  title: string;
  format: "markdown" | "code";
  content: string;
  version: number;
};
export default function CanvasDialog({
  initial = "",
  onClose,
  onUse,
}: {
  initial?: string;
  onClose: () => void;
  onUse: (attachment: { id: number; name: string }) => void;
}) {
  const [items, setItems] = useState<Canvas[]>([]);
  const [selected, setSelected] = useState<Canvas | null>(null);
  const [title, setTitle] = useState("Untitled draft");
  const [content, setContent] = useState(initial);
  const [format, setFormat] = useState<"markdown" | "code">("markdown");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [preview, setPreview] = useState(false);
  const [notice, setNotice] = useState("");
  const refresh = () => api<Canvas[]>("canvases").then(setItems);
  useEffect(() => {
    refresh().catch((e) => setError(e.message));
  }, []);
  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await action();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  function set(c: Canvas | null) {
    setSelected(c);
    setTitle(c?.title || "Untitled draft");
    setContent(c?.content || "");
    setFormat(c?.format || "markdown");
    setPreview(false);
    setNotice("");
  }
  return (
    <Dialog
      title="Canvas"
      onClose={() => {
        if (!busy) onClose();
      }}
      wide
    >
      <div className="canvas-header">
        <select
          aria-label="Open saved canvas"
          value={selected?.id || ""}
          onChange={(e) => {
            if (!e.target.value) set(null);
            else
              void run(async () =>
                set(await api<Canvas>(`canvases/${e.target.value}`)),
              );
          }}
        >
          <option value="">New draft</option>
          {items.map((c) => (
            <option key={c.id} value={c.id}>
              {c.title}
            </option>
          ))}
        </select>
        <button className="text-button" onClick={() => setPreview(!preview)}>
          {preview ? "Edit" : "Preview"}
        </button>
      </div>
      <form
        className="form-stack"
        onSubmit={(e) => {
          e.preventDefault();
          void run(async () => {
            const saved = await api<Canvas>(
              selected ? `canvases/${selected.id}` : "canvases",
              selected ? "PUT" : "POST",
              {
                title,
                format,
                content,
                ...(selected ? { version: selected.version } : {}),
              },
            );
            setSelected(saved);
            setNotice("Canvas saved.");
            await refresh();
          });
        }}
      >
        <label>
          Title
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
            maxLength={120}
          />
        </label>
        <label>
          Format
          <select
            value={format}
            onChange={(e) => setFormat(e.target.value as "markdown" | "code")}
          >
            <option value="markdown">Markdown document</option>
            <option value="code">Code / plain text</option>
          </select>
        </label>
        {preview ? (
          <div className="canvas-preview">
            {format === "markdown" ? (
              <ResponseMarkdown content={content} />
            ) : (
              <pre>{content}</pre>
            )}
          </div>
        ) : (
          <label>
            Content
            <textarea
              className="canvas-editor"
              rows={15}
              value={content}
              onChange={(e) => setContent(e.target.value)}
              maxLength={50000}
            />
          </label>
        )}
        {error && (
          <p className="feedback error" role="alert">
            {error}
          </p>
        )}
        {notice && (
          <p className="feedback success" role="status">
            {notice}
          </p>
        )}
        <div className="button-row">
          <button
            type="button"
            className="secondary-button"
            disabled={busy || !content}
            onClick={() =>
              void run(async () => {
                const file = await api<{ id: number; name: string }>(
                  "attachments",
                  "POST",
                  {
                    name: `${title.slice(0, 100)}.${format === "markdown" ? "md" : "txt"}`,
                    content,
                  },
                );
                onUse(file);
                onClose();
              })
            }
          >
            Use in chat
          </button>
          {selected && (
            <button
              type="button"
              className="danger-button"
              disabled={busy}
              onClick={() => {
                if (confirm("Delete this canvas?"))
                  void run(async () => {
                    await api(`canvases/${selected.id}`, "DELETE");
                    set(null);
                    await refresh();
                  });
              }}
            >
              Delete
            </button>
          )}
          <button className="primary-button" disabled={busy}>
            Save canvas
          </button>
        </div>
      </form>
    </Dialog>
  );
}
