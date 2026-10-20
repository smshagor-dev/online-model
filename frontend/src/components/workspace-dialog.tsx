"use client";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import Dialog from "./dialog";
type Project = { id: number; name: string; instructions: string | null };
type Memory = { id: number; content: string };
type Share = { id: number; conversation_id: number; expires_at: string };
export default function WorkspaceDialog({
  onClose,
  onProject,
  onChanged,
  onAttachment,
  initialTab = "instructions",
}: {
  onClose: () => void;
  initialTab?: string;
  onProject: (id: number | null) => void;
  onChanged: () => void;
  onAttachment: (file: { id: number; name: string }) => void;
}) {
  const [files, setFiles] = useState<
    { id: number; name: string; bytes: number }[]
  >([]);
  const [filePage, setFilePage] = useState(1);
  const [fileLast, setFileLast] = useState(1);
  const [tab, setTab] = useState(initialTab);
  const [projects, setProjects] = useState<Project[]>([]);
  const [memories, setMemories] = useState<Memory[]>([]);
  const [shares, setShares] = useState<Share[]>([]);
  const [instructions, setInstructions] = useState("");
  const [enabled, setEnabled] = useState(false);
  const [theme, setTheme] = useState("dark");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [editingProject, setEditingProject] = useState<Project | null>(null);
  const [editingMemory, setEditingMemory] = useState<Memory | null>(null);
  useEffect(() => {
    if (tab === "files")
      api<{
        data: { id: number; name: string; bytes: number }[];
        last_page: number;
      }>("attachments")
        .then((r) => {
          setFiles(r.data);
          setFileLast(r.last_page);
          setFilePage(1);
        })
        .catch((e) => setError(e.message));
  }, [tab]);
  async function load() {
    const [p, m, s, prefs] = await Promise.all([
      api<Project[]>("projects"),
      api<Memory[]>("memories"),
      api<Share[]>("shares"),
      api<{
        custom_instructions: string | null;
        memory_enabled: boolean;
        theme: string;
      }>("preferences"),
    ]);
    setProjects(p);
    setMemories(m);
    setShares(s);
    setInstructions(prefs.custom_instructions || "");
    setEnabled(prefs.memory_enabled);
    setTheme(prefs.theme);
  }
  useEffect(() => {
    void load().catch((e) => setError(e.message));
  }, []);
  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await action();
      onChanged();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  function form(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    return Object.fromEntries(new FormData(e.currentTarget));
  }
  return (
    <Dialog
      title="Your workspace"
      onClose={() => {
        if (!busy) onClose();
      }}
      wide
    >
      <div className="workspace-tabs">
        {["instructions", "memory", "projects", "files", "data"].map((t) => (
          <button
            key={t}
            className={tab === t ? "selected" : ""}
            onClick={() => setTab(t)}
          >
            {t[0].toUpperCase() + t.slice(1)}
          </button>
        ))}
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
      {tab === "instructions" && (
        <form
          className="form-stack"
          onSubmit={(e) => {
            e.preventDefault();
            void run(async () => {
              await api("preferences", "PATCH", {
                custom_instructions: instructions,
                memory_enabled: enabled,
                theme,
              });
              document.documentElement.dataset.theme =
                theme === "system"
                  ? matchMedia("(prefers-color-scheme: light)").matches
                    ? "light"
                    : "dark"
                  : theme;
              setNotice("Preferences saved. They apply to future messages.");
            });
          }}
        >
          <label>
            How should Openmindai respond?
            <textarea
              value={instructions}
              onChange={(e) => setInstructions(e.target.value)}
              maxLength={8000}
              rows={7}
              placeholder="Your background, preferences, preferred response style…"
            />
          </label>
          <label>
            Appearance
            <select value={theme} onChange={(e) => setTheme(e.target.value)}>
              <option value="dark">Dark</option>
              <option value="light">Light</option>
              <option value="system">System</option>
            </select>
          </label>
          <label className="checkbox-label">
            <input
              type="checkbox"
              checked={enabled}
              onChange={(e) => setEnabled(e.target.checked)}
            />
            Use my saved memories in future chats
          </label>
          <small className="muted">
            Temporary chats ignore your custom instructions and memories.
            Project instructions apply inside that project.
          </small>
          <button className="primary-button" disabled={busy}>
            Save preferences
          </button>
        </form>
      )}
      {tab === "memory" && (
        <>
          <p className="muted">
            These are explicit notes you control. Openmindai does not silently
            invent or add memories.
          </p>
          <form
            className="form-stack"
            key={editingMemory?.id || "new-memory"}
            onSubmit={(e) => {
              const data = form(e);
              void run(async () => {
                await api(
                  editingMemory ? `memories/${editingMemory.id}` : "memories",
                  editingMemory ? "PUT" : "POST",
                  data,
                );
                setEditingMemory(null);
                await load();
              });
            }}
          >
            <label>
              {editingMemory ? "Edit memory" : "Add a memory"}
              <textarea
                name="content"
                defaultValue={editingMemory?.content || ""}
                maxLength={2000}
                required
                rows={3}
              />
            </label>
            <div className="button-row">
              {editingMemory && (
                <button
                  type="button"
                  className="secondary-button"
                  onClick={() => setEditingMemory(null)}
                >
                  Cancel
                </button>
              )}
              <button className="primary-button" disabled={busy}>
                Save memory
              </button>
            </div>
          </form>
          {memories.map((m) => (
            <div className="manage-card" key={m.id}>
              <p>{m.content}</p>
              <div className="button-row">
                <button
                  className="text-button"
                  onClick={() => setEditingMemory(m)}
                >
                  Edit
                </button>
                <button
                  className="text-button danger-text"
                  disabled={busy}
                  onClick={() =>
                    void run(async () => {
                      await api(`memories/${m.id}`, "DELETE");
                      await load();
                    })
                  }
                >
                  Delete
                </button>
              </div>
            </div>
          ))}
        </>
      )}
      {tab === "projects" && (
        <>
          <form
            className="form-stack"
            key={editingProject?.id || "new-project"}
            onSubmit={(e) => {
              const data = form(e);
              void run(async () => {
                await api(
                  editingProject ? `projects/${editingProject.id}` : "projects",
                  editingProject ? "PUT" : "POST",
                  data,
                );
                setEditingProject(null);
                await load();
              });
            }}
          >
            <label>
              Project name
              <input
                name="name"
                defaultValue={editingProject?.name || ""}
                required
                maxLength={120}
              />
            </label>
            <label>
              Project instructions
              <textarea
                name="instructions"
                defaultValue={editingProject?.instructions || ""}
                rows={4}
                maxLength={8000}
              />
            </label>
            <div className="button-row">
              {editingProject && (
                <button
                  type="button"
                  className="secondary-button"
                  onClick={() => setEditingProject(null)}
                >
                  Cancel
                </button>
              )}
              <button className="primary-button" disabled={busy}>
                {editingProject ? "Update project" : "Create project"}
              </button>
            </div>
          </form>
          {projects.map((p) => (
            <div className="manage-card" key={p.id}>
              <strong>{p.name}</strong>
              <p className="muted">{p.instructions}</p>
              <div className="button-row">
                <button
                  className="text-button"
                  onClick={() => {
                    onProject(p.id);
                    onClose();
                  }}
                >
                  Open
                </button>
                <button
                  className="text-button"
                  onClick={() => setEditingProject(p)}
                >
                  Edit
                </button>
                <button
                  className="text-button danger-text"
                  disabled={busy}
                  onClick={() => {
                    if (
                      confirm(
                        "Delete this project? Its chats will remain outside the project.",
                      )
                    )
                      void run(async () => {
                        await api(`projects/${p.id}`, "DELETE");
                        onProject(null);
                        await load();
                      });
                  }}
                >
                  Delete
                </button>
              </div>
            </div>
          ))}
        </>
      )}
      {tab === "files" && (
        <>
          <p className="muted">
            Your uploaded text/code files. Removing a file also removes it from
            future model context.
          </p>
          {files.map((f) => (
            <div className="manage-card" key={f.id}>
              <strong>{f.name}</strong>
              <small className="muted"> {f.bytes.toLocaleString()} bytes</small>
              <div className="button-row">
                <button
                  className="text-button"
                  onClick={() => {
                    onAttachment(f);
                    onClose();
                  }}
                >
                  Attach to chat
                </button>
                <button
                  className="text-button"
                  onClick={() =>
                    void run(async () => {
                      const data = await api<{ content: string }>(
                        `attachments/${f.id}`,
                      );
                      const url = URL.createObjectURL(
                        new Blob([data.content], { type: "text/plain" }),
                      );
                      const a = document.createElement("a");
                      a.href = url;
                      a.download = f.name;
                      a.click();
                      setTimeout(() => URL.revokeObjectURL(url), 1000);
                    })
                  }
                >
                  Download
                </button>
                <button
                  className="text-button danger-text"
                  onClick={() =>
                    void run(async () => {
                      await api(`attachments/${f.id}`, "DELETE");
                      setFiles((previous) =>
                        previous.filter((item) => item.id !== f.id),
                      );
                    })
                  }
                >
                  Delete
                </button>
              </div>
            </div>
          ))}
          {filePage < fileLast && (
            <button
              className="secondary-button"
              onClick={() =>
                void run(async () => {
                  const r = await api<{ data: typeof files }>(
                    `attachments?page=${filePage + 1}`,
                  );
                  setFiles((previous) => [...previous, ...r.data]);
                  setFilePage(filePage + 1);
                })
              }
            >
              Load more files
            </button>
          )}
        </>
      )}
      {tab === "data" && (
        <>
          <h3>Your data</h3>
          <p className="muted">
            Download your profile, projects, memories, attachments and saved
            conversations as JSON.
          </p>
          <button
            className="secondary-button"
            disabled={busy}
            onClick={() =>
              void run(async () => {
                const data = await api("export");
                const url = URL.createObjectURL(
                  new Blob([JSON.stringify(data, null, 2)], {
                    type: "application/json",
                  }),
                );
                const a = document.createElement("a");
                a.href = url;
                a.download = "openmindai-export.json";
                a.click();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
              })
            }
          >
            Export my data
          </button>
          <h3>Shared snapshots</h3>
          <p className="muted">
            Links expire after 30 days. Revoke access at any time.
          </p>
          {shares.map((s) => (
            <div className="device-row" key={s.id}>
              <span>
                Chat #{s.conversation_id}
                <small>
                  Expires {new Date(s.expires_at).toLocaleDateString()}
                </small>
              </span>
              <button
                className="text-button danger-text"
                disabled={busy}
                onClick={() =>
                  void run(async () => {
                    await api(`shares/${s.id}`, "DELETE");
                    await load();
                  })
                }
              >
                Revoke
              </button>
            </div>
          ))}
        </>
      )}
    </Dialog>
  );
}
