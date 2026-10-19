"use client";
import { useState, useEffect } from "react";
import { api, type Conversation } from "@/lib/api";
import Dialog from "./dialog";
export default function ConversationDialog({
  chat,
  onClose,
  onChanged,
}: {
  chat: Conversation;
  onClose: () => void;
  onChanged: () => void;
}) {
  const [ready, setReady] = useState(false);
  const [title, setTitle] = useState(chat.title);
  const [pinned, setPinned] = useState(!!chat.pinned);
  const [archived, setArchived] = useState(!!chat.archived_at);
  const [project, setProject] = useState(chat.project_id?.toString() || "");
  const [projects, setProjects] = useState<{ id: number; name: string }[]>([]);
  const [error, setError] = useState("");
  const [url, setUrl] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    Promise.all([
      api<{ id: number; name: string }[]>(
        "projects",
        "GET",
        undefined,
        controller.signal,
      ),
      api<{ conversation: Conversation }>(
        `conversations/${chat.id}`,
        "GET",
        undefined,
        controller.signal,
      ),
    ])
      .then(([p, r]) => {
        setProjects(p);
        setTitle(r.conversation.title);
        setPinned(!!r.conversation.pinned);
        setArchived(!!r.conversation.archived_at);
        setProject(r.conversation.project_id?.toString() || "");
        setReady(true);
      })
      .catch((e) => {
        if (e.name !== "AbortError") setError(e.message);
      });
    return () => controller.abort();
  }, [chat.id]);
  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError("");
    try {
      await action();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog
      title="Conversation"
      onClose={() => {
        if (!busy) onClose();
      }}
    >
      <form
        className="form-stack"
        onSubmit={(e) => {
          e.preventDefault();
          void run(async () => {
            await api(`conversations/${chat.id}`, "PATCH", {
              title,
              pinned,
              archived,
              project_id: project ? Number(project) : null,
            });
            onChanged();
            onClose();
          });
        }}
      >
        <label>
          Title
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            maxLength={120}
            required
          />
        </label>
        <label>
          Project
          <select value={project} onChange={(e) => setProject(e.target.value)}>
            <option value="">No project</option>
            {projects.map((p) => (
              <option value={p.id} key={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </label>
        <label className="checkbox-label">
          <input
            type="checkbox"
            checked={pinned}
            onChange={(e) => setPinned(e.target.checked)}
          />
          Pin chat
        </label>
        <label className="checkbox-label">
          <input
            type="checkbox"
            checked={archived}
            onChange={(e) => setArchived(e.target.checked)}
          />
          Archive chat
        </label>
        <button className="primary-button" disabled={busy || !ready}>
          Save changes
        </button>
      </form>
      <hr />
      <h3>Share a snapshot</h3>
      <p className="muted">
        Anyone with the link can read this conversation snapshot for 30 days.
        Later messages aren't included. Review private information before
        sharing.
      </p>
      <button
        className="secondary-button"
        disabled={busy || !ready}
        onClick={() =>
          void run(async () => {
            const share = await api<{ token: string }>(
              `conversations/${chat.id}/share`,
              "POST",
            );
            setUrl(`${location.origin}/share/${share.token}`);
          })
        }
      >
        Create share link
      </button>
      {url && (
        <label className="share-url">
          Your link
          <input value={url} readOnly onFocus={(e) => e.target.select()} />
        </label>
      )}
      {error && (
        <p className="feedback error" role="alert">
          {error}
        </p>
      )}
    </Dialog>
  );
}
