"use client";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import Dialog from "./dialog";
import CodePreview from "./code-preview";
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
  const [versions, setVersions] = useState<
    { version: number; title: string }[]
  >([]);
  const [compare, setCompare] = useState<string | null>(null);
  const [split, setSplit] = useState(true);
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
  useEffect(() => {
    if (!selected) {
      setVersions([]);
      return;
    }
    const c = new AbortController();
    api<typeof versions>(
      `canvases/${selected.id}/versions`,
      "GET",
      undefined,
      c.signal,
    )
      .then(setVersions)
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [selected?.id, selected?.version]);
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
      <div className="canvas-toolbar">
        <button onClick={() => setSplit((v) => !v)}>
          {split ? "Single pane" : "Split editor / preview"}
        </button>
        {selected && (
          <select
            aria-label="Canvas version history"
            value=""
            onChange={(e) => {
              const v = Number(e.target.value);
              if (v)
                void run(async () => {
                  const old = await api<Canvas>(
                    `canvases/${selected.id}/versions/${v}`,
                  );
                  setCompare(old.content);
                });
            }}
          >
            <option value="">Compare saved version…</option>
            {versions.map((v) => (
              <option key={v.version} value={v.version}>
                Version {v.version} · {v.title}
              </option>
            ))}
          </select>
        )}
      </div>
      {compare !== null && (
        <section className="canvas-diff">
          <h3>Version comparison</h3>
          <div className="canvas-split">
            <div>
              <h4>Saved version</h4>
              <pre>{compare}</pre>
            </div>
            <div>
              <h4>Current draft</h4>
              <pre>{content}</pre>
            </div>
          </div>
          <button
            onClick={() => {
              setContent(compare);
              setCompare(null);
              setNotice(
                "Saved version loaded into draft. Save to create a new version.",
              );
            }}
          >
            Restore into draft
          </button>
          <button onClick={() => setCompare(null)}>Close comparison</button>
        </section>
      )}
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
        <div className={split ? "canvas-split" : ""}>
          {(!preview || split) && (
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
          {(preview || split) && (
            <div className="canvas-preview">
              {format === "markdown" ? (
                <ResponseMarkdown content={content} />
              ) : (
                <CodePreview content={content} />
              )}
            </div>
          )}
        </div>
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
