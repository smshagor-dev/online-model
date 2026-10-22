"use client";
import CreationProgress from "./creation-progress";
import DocumentPreview from "./document-preview";
import ResponseMarkdown from "./response-markdown";
import { useEffect, useState } from "react";
import ArtifactPreviews, { type Artifact } from "./artifact-previews";
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
    progress_percent?: number | null;
    progress_stage?: string | null;
    result_text: string | null;
    output_mime: string | null;
    error: string | null;
    artifacts?: Artifact[];
  } | null>(null);
  const [error, setError] = useState("");
  const [documentFile, setDocumentFile] = useState<File | null>(null);
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
            if (["application/pdf", "application/vnd.openxmlformats-officedocument.wordprocessingml.document", "text/markdown", "text/plain", "application/msword"].includes(blob.type)) {
              const ext = blob.type === "application/pdf" ? "pdf" : blob.type.includes("wordprocessingml") ? "docx" : blob.type === "application/msword" ? "doc" : "md";
              setDocumentFile(new File([blob], `document.${ext}`, {type: blob.type}));
            }
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
  if (!error && !task?.error && (!task || ["queued", "running"].includes(task.status) || (task.status === "complete" && task.output_mime && !url))) {
    return <CreationProgress kind={task?.kind || "media"} preparing={task?.status === "complete"}
      progress={task?.progress_percent} stage={task?.progress_stage}/>;
  }
  return (
    <article className="inline-media manage-card creation-result">
      <strong>
        {task?.kind || "Media"} · {task?.status || "Loading"}
      </strong>
      {(error || task?.error) && <p role="alert">{error || task?.error}</p>}
      {url && ["image/png", "image/jpeg"].includes(task?.output_mime || "") && (
        <a href={url} target="_blank" rel="noopener noreferrer">
          <img
            className="media-preview"
            src={url}
            alt="Generated image; open full size"
          />
        </a>
      )}
      {documentFile && <DocumentPreview file={documentFile} />}
      {url && task?.output_mime === "audio/wav" && <audio controls src={url} />}{" "}
      {url && task?.output_mime === "video/mp4" && (
        <video className="media-preview" controls src={url} />
      )}{" "}
      {!!task?.artifacts?.length && <ArtifactPreviews items={task.artifacts} />}
      {task?.result_text && (
        <>
          <ResponseMarkdown content={task.result_text} />
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
