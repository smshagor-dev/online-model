"use client";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
export default function InlineMedia({
  id,
  onText,
}: {
  id: number;
  onText: (text: string) => void;
}) {
  const [task, setTask] = useState<{
    id: number;
    kind: string;
    status: string;
    result_text: string | null;
    output_mime: string | null;
    error: string | null;
  } | null>(null);
  const [error, setError] = useState("");
  const [url, setUrl] = useState("");
  useEffect(() => {
    const c = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    async function load() {
      try {
        const t = await api<NonNullable<typeof task>>(
          `media/${id}`,
          "GET",
          undefined,
          c.signal,
        );
        setTask(t);
        if (["queued", "running"].includes(t.status))
          timer = setTimeout(load, 3000);
      } catch (e) {
        if (!c.signal.aborted) setError((e as Error).message);
      }
    }
    void load();
    return () => {
      c.abort();
      clearTimeout(timer);
    };
  }, [id]);
  useEffect(() => {
    const c = new AbortController();
    let u = "";
    if (task?.status === "complete" && task.output_mime)
      fetch(`/api/backend/media/${id}/output`, {
        signal: c.signal,
        cache: "no-store",
      })
        .then(async (r) => {
          if (!r.ok) throw new Error("Media unavailable.");
          const blob = await r.blob();
          if (!c.signal.aborted) {
            u = URL.createObjectURL(blob);
            setUrl(u);
          }
        })
        .catch((e) => {
          if (!c.signal.aborted) setError(e.message);
        });
    return () => {
      c.abort();
      if (u) URL.revokeObjectURL(u);
    };
  }, [id, task?.status, task?.output_mime]);
  return (
    <article className="inline-media manage-card">
      <strong>
        {task?.kind || "Media"} · {task?.status || "Loading"}
      </strong>
      {(error || task?.error) && <p role="alert">{error || task?.error}</p>}
      {url && task?.output_mime === "image/png" && (
        <a href={url} target="_blank" rel="noopener noreferrer">
          <img
            className="media-preview"
            src={url}
            alt="Generated image; open full size"
          />
        </a>
      )}
      {url && task?.output_mime === "audio/wav" && <audio controls src={url} />}{" "}
      {url && task?.output_mime === "video/mp4" && (
        <video className="media-preview" controls src={url} />
      )}{" "}
      {task?.result_text && (
        <>
          <pre className="file-text-preview">{task.result_text}</pre>
          <button onClick={() => onText(task.result_text!)}>
            Use text in message
          </button>
        </>
      )}
      {url && (
        <a href={`/api/backend/media/${id}/output`} download>
          Download result
        </a>
      )}
      <small className="muted">
        Saved in Library. This preview is local to the current workspace view.
      </small>
    </article>
  );
}
