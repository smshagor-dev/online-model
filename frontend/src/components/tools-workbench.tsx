"use client";
import { useEffect, useRef, useState } from "react";
import { api, type Model, type Page } from "@/lib/api";
import Dialog from "./dialog";
import ResponseMarkdown from "./response-markdown";
import LibraryPanel from "./unified-library";
import CodeRunner from "./code-runner";
import VoicePanel from "./voice-panel";
type Assistant = {
  id: number;
  name: string;
  description: string | null;
  instructions: string;
  model_id: number | null;
  attachment_ids: number[] | null;
};
type Task = {
  id: number;
  title: string;
  prompt: string;
  model_id: number;
  kind: string;
  frequency: string;
  next_run_at: string | null;
  enabled: boolean;
};
type Run = {
  id: number;
  status: string;
  result: string | null;
  error: string | null;
  steps: { label: string; status: string }[] | null;
  sources:
    | {
        title: string;
        url: string;
        read_status?: string;
        page_text?: string;
        snippet?: string;
      }[]
    | null;
};
export default function ToolsWorkbench({
  initial = "assistants",
  onClose,
  onAssistant,
  onText,
  onAttachment,
}: {
  initial?: string;
  onClose: () => void;
  onAssistant: (a: {
    id: number;
    name: string;
    model_id: number | null;
  }) => void;
  onText: (text: string) => void;
  onAttachment: (file: { id: number; name: string }) => void;
}) {
  const [tab, setTab] = useState(initial);
  return (
    <Dialog title="Openmindai workspace" onClose={onClose} wide>
      <div className="workspace-tabs">
        {["assistants", "tasks", "research", "library", "voice", "code"].map(
          (t) => (
            <button
              key={t}
              aria-pressed={tab === t}
              className={tab === t ? "selected" : ""}
              onClick={() => setTab(t)}
            >
              {t[0].toUpperCase() + t.slice(1)}
            </button>
          ),
        )}
      </div>
      {tab === "assistants" && <AssistantBuilder onUse={onAssistant} />}
      {(tab === "tasks" || tab === "research") && (
        <TaskPlanner key={tab} research={tab === "research"} onText={onText} />
      )}
      {tab === "library" && (
        <LibraryPanel onText={onText} onAttachment={onAttachment} />
      )}
      {tab === "code" && <CodeRunner onText={onText} />}
      {tab === "voice" && <VoicePanel onText={onText} />}
    </Dialog>
  );
}
function AssistantBuilder({ onUse }: { onUse: (a: Assistant) => void }) {
  const [items, setItems] = useState<Assistant[]>([]);
  const [models, setModels] = useState<Model[]>([]);
  const [files, setFiles] = useState<{ id: number; name: string }[]>([]);
  const [editing, setEditing] = useState<Assistant | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const c = useRef<AbortController | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    c.current = controller;
    Promise.all([
      api<Assistant[]>("assistants", "GET", undefined, controller.signal),
      api<Model[]>("models", "GET", undefined, controller.signal),
      api<Page<{ id: number; name: string }>>(
        "attachments",
        "GET",
        undefined,
        controller.signal,
      ),
    ])
      .then(([a, m, f]) => {
        setItems(a);
        setModels(m.filter((model) => model.id > 0));
        setFiles(f.data);
      })
      .catch((e) => {
        if (!controller.signal.aborted) setError(e.message);
      });
    return () => c.current?.abort();
  }, []);
  async function run(fn: (signal: AbortSignal) => Promise<void>) {
    c.current?.abort();
    const controller = new AbortController();
    c.current = controller;
    setBusy(true);
    setError("");
    try {
      await fn(controller.signal);
    } catch (e) {
      if (!controller.signal.aborted) setError((e as Error).message);
    } finally {
      if (!controller.signal.aborted) setBusy(false);
    }
  }
  return (
    <>
      <h3>Your assistants</h3>
      <p className="muted">
        Private assistants use your own model runtime. Knowledge comes from your
        text-file library. Tools are not granted automatically by instructions.
      </p>
      {error && (
        <p role="alert" className="feedback error">
          {error}
        </p>
      )}
      <div className="tool-cards">
        {items.map((a) => (
          <article className="manage-card" key={a.id}>
            <strong>{a.name}</strong>
            <p>{a.description}</p>
            <div className="button-row">
              <button disabled={busy} onClick={() => onUse(a)}>
                Start chat
              </button>
              <button disabled={busy} onClick={() => setEditing(a)}>
                Edit
              </button>
              <button
                className="danger-text"
                disabled={busy}
                onClick={() => {
                  if (confirm("Delete this assistant? Existing chats remain."))
                    void run(async (signal) => {
                      await api(
                        `assistants/${a.id}`,
                        "DELETE",
                        undefined,
                        signal,
                      );
                      setItems((old) => old.filter((x) => x.id !== a.id));
                      if (editing?.id === a.id) setEditing(null);
                    });
                }}
              >
                Delete
              </button>
            </div>
          </article>
        ))}
      </div>
      <h3>{editing ? "Edit assistant" : "Create assistant"}</h3>
      <form
        key={editing?.id || "new"}
        className="form-stack"
        onSubmit={(e) => {
          e.preventDefault();
          const form = new FormData(e.currentTarget);
          void run(async (signal) => {
            const saved = await api<Assistant>(
              editing ? `assistants/${editing.id}` : "assistants",
              editing ? "PUT" : "POST",
              {
                name: form.get("name"),
                description: form.get("description"),
                instructions: form.get("instructions"),
                model_id: Number(form.get("model_id")) || null,
                attachment_ids: form.getAll("knowledge").map(Number),
              },
              signal,
            );
            setItems((old) => [saved, ...old.filter((a) => a.id !== saved.id)]);
            setEditing(null);
          });
        }}
      >
        <fieldset className="settings-fieldset" disabled={busy}>
          <label>
            Name
            <input
              name="name"
              defaultValue={editing?.name || ""}
              required
              maxLength={120}
            />
          </label>
          <label>
            Description
            <input
              name="description"
              defaultValue={editing?.description || ""}
              maxLength={500}
            />
          </label>
          <label>
            Instructions
            <textarea
              name="instructions"
              defaultValue={editing?.instructions || ""}
              required
              rows={5}
              maxLength={8000}
            />
          </label>
          <label>
            Preferred model
            <select name="model_id" defaultValue={editing?.model_id || ""}>
              <option value="">Keep current model</option>
              {models.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Knowledge files (select up to 5)
            <select
              name="knowledge"
              multiple
              defaultValue={(editing?.attachment_ids || []).map(String)}
            >
              {files.map((f) => (
                <option key={f.id} value={f.id}>
                  {f.name}
                </option>
              ))}
            </select>
          </label>
          <small className="muted">
            The first page of library files is shown. Add text files from the
            Library tab before configuring knowledge.
          </small>
          <div className="button-row">
            <button className="primary-button">Save assistant</button>
            {editing && (
              <button type="button" onClick={() => setEditing(null)}>
                Cancel edit
              </button>
            )}
          </div>
        </fieldset>
      </form>
    </>
  );
}
function TaskPlanner({
  research,
  onText,
}: {
  research: boolean;
  onText: (text: string) => void;
}) {
  const [tasks, setTasks] = useState<Task[]>([]);
  const [models, setModels] = useState<Model[]>([]);
  const [selected, setSelected] = useState<number | null>(null);
  const [editing, setEditing] = useState<Task | null>(null);
  const [runs, setRuns] = useState<Page<Run> | null>(null);
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [search, setSearch] = useState(false);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const action = useRef<AbortController | null>(null);
  useEffect(() => {
    const c = new AbortController();
    Promise.all([
      api<Task[]>("tasks", "GET", undefined, c.signal),
      api<Model[]>("models", "GET", undefined, c.signal),
      api<{ web_search: boolean }>("capabilities", "GET", undefined, c.signal),
    ])
      .then(([t, m, caps]) => {
        setTasks(t);
        setModels(m.filter((model) => model.id > 0));
        setSearch(caps.web_search);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => {
      c.abort();
      action.current?.abort();
    };
  }, []);
  useEffect(() => {
    if (!selected) {
      setRuns(null);
      return;
    }
    const c = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    async function load() {
      try {
        const r = await api<Page<Run>>(
          `tasks/${selected}/runs?page=${page}`,
          "GET",
          undefined,
          c.signal,
        );
        setRuns(r);
        if (r.data.some((x) => ["queued", "running"].includes(x.status)))
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
  }, [selected, page, revision]);
  async function run(fn: (signal: AbortSignal) => Promise<void>) {
    action.current?.abort();
    const c = new AbortController();
    action.current = c;
    setBusy(true);
    setError("");
    try {
      await fn(c.signal);
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  return (
    <>
      <h3>{research ? "Research workspace" : "Scheduled tasks"}</h3>
      <p className="muted">
        {research
          ? "Search up to five sources, read public HTML pages and generate a sourced report. Unreadable pages are marked; snippets are not full-page verification."
          : "Run prompts now or schedule one-time, daily or weekly runs. Results stay in task history. Times below use your browser’s timezone."}{" "}
        The server scheduler and task worker must be running. These tools
        require a configured local model; NVIDIA hosted chat is available in the
        chat workspace.
      </p>
      {research && !search && (
        <p role="status">
          Research needs the administrator’s self-hosted search service.
        </p>
      )}
      {error && (
        <p className="feedback error" role="alert">
          {error}
        </p>
      )}
      <div className="tool-cards">
        {tasks
          .filter((t) => t.kind === (research ? "research" : "chat"))
          .map((t) => (
            <article key={t.id} className="manage-card">
              <strong>{t.title}</strong>
              <p>
                {t.enabled ? "Enabled" : "Paused"} ·{" "}
                {t.next_run_at
                  ? new Date(t.next_run_at).toLocaleString()
                  : "Manual only"}{" "}
                · {t.frequency}
              </p>
              <div className="button-row">
                <button
                  disabled={busy || (research && !search)}
                  onClick={() =>
                    void run(async (signal) => {
                      await api(`tasks/${t.id}/runs`, "POST", {}, signal);
                      setSelected(t.id);
                      setPage(1);
                      setRevision((v) => v + 1);
                    })
                  }
                >
                  Run now
                </button>
                <button
                  disabled={busy}
                  onClick={() => {
                    setSelected(t.id);
                    setPage(1);
                  }}
                >
                  History
                </button>
                <button disabled={busy} onClick={() => setEditing(t)}>
                  Edit / pause
                </button>
                <button
                  disabled={busy}
                  onClick={() => {
                    if (confirm("Delete this task and all its run history?"))
                      void run(async (signal) => {
                        await api(`tasks/${t.id}`, "DELETE", undefined, signal);
                        setTasks((old) => old.filter((x) => x.id !== t.id));
                        if (selected === t.id) setSelected(null);
                      });
                  }}
                >
                  Delete
                </button>
              </div>
            </article>
          ))}
      </div>
      <form
        key={editing?.id || "new"}
        className="form-stack"
        onSubmit={(e) => {
          e.preventDefault();
          const f = new FormData(e.currentTarget);
          void run(async (signal) => {
            const date = String(f.get("date") || "");
            const saved = await api<Task>(
              editing ? `tasks/${editing.id}` : "tasks",
              editing ? "PUT" : "POST",
              {
                title: f.get("title"),
                prompt: f.get("prompt"),
                model_id: Number(f.get("model_id")),
                kind: research ? "research" : "chat",
                frequency: f.get("frequency"),
                enabled: f.get("enabled") === "on",
                next_run_at: date ? new Date(date).toISOString() : null,
              },
              signal,
            );
            setTasks((old) => [saved, ...old.filter((x) => x.id !== saved.id)]);
            setEditing(null);
            setSelected(saved.id);
          });
        }}
      >
        <fieldset
          disabled={busy || models.length === 0}
          className="settings-fieldset"
        >
          <h3>{editing ? "Edit task" : "Create task"}</h3>
          <label>
            Title
            <input
              name="title"
              defaultValue={editing?.title || ""}
              required
              maxLength={120}
            />
          </label>
          <label>
            {research ? "Research question" : "Prompt"}
            <textarea
              name="prompt"
              defaultValue={editing?.prompt || ""}
              required
              rows={4}
              maxLength={16000}
            />
          </label>
          <label>
            Model
            <select
              name="model_id"
              defaultValue={editing?.model_id || models[0]?.id || ""}
              required
            >
              {models.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Repeat
            <select
              name="frequency"
              defaultValue={editing?.frequency || "once"}
            >
              {["once", "daily", "weekly"].map((x) => (
                <option key={x}>{x}</option>
              ))}
            </select>
          </label>
          <label>
            Next run (leave empty for manual only)
            <input
              name="date"
              type="datetime-local"
              defaultValue={
                editing?.next_run_at ? localDate(editing.next_run_at) : ""
              }
            />
          </label>
          <label className="checkbox-label">
            <input
              name="enabled"
              type="checkbox"
              defaultChecked={editing?.enabled ?? true}
            />
            Enabled
          </label>
          <button className="primary-button">Save task</button>
          {editing && (
            <button type="button" onClick={() => setEditing(null)}>
              Cancel edit
            </button>
          )}
        </fieldset>
      </form>
      {selected && (
        <section className="task-activity">
          <h3>Run history · task #{selected}</h3>
          {runs?.data.length === 0 && (
            <p>No runs yet. Choose Run now after saving.</p>
          )}
          {runs?.data.map((r) => (
            <article className="manage-card" key={r.id}>
              <strong>
                Run #{r.id} · {r.status}
              </strong>
              {["queued", "running"].includes(r.status) && (
                <button
                  disabled={busy}
                  onClick={() =>
                    void run(async (signal) => {
                      await api(
                        `tasks/${selected}/runs/${r.id}`,
                        "DELETE",
                        undefined,
                        signal,
                      );
                      setRevision((v) => v + 1);
                    })
                  }
                >
                  Cancel run
                </button>
              )}
              <ol>
                {r.steps?.map((s, i) => (
                  <li key={i}>
                    {s.label} · {s.status}
                  </li>
                ))}
              </ol>
              {r.error && <p role="alert">{r.error}</p>}
              {r.result && (
                <>
                  <ResponseMarkdown content={r.result} />
                  <button onClick={() => onText(r.result!)}>
                    Use result in chat
                  </button>
                  <button
                    onClick={() => {
                      const u = URL.createObjectURL(
                        new Blob([r.result!], { type: "text/markdown" }),
                      );
                      const a = document.createElement("a");
                      a.href = u;
                      a.download = `research-${r.id}.md`;
                      a.click();
                      setTimeout(() => URL.revokeObjectURL(u), 1000);
                    }}
                  >
                    Download report
                  </button>
                </>
              )}
              <aside className="source-panel">
                {r.sources?.map((s, i) => (
                  <details key={i}>
                    <summary>
                      {s.title} · {s.read_status || "snippet"}
                    </summary>
                    <a href={s.url} target="_blank" rel="noopener noreferrer">
                      Open source
                    </a>
                    <p>{s.page_text || s.snippet}</p>
                  </details>
                ))}
              </aside>
            </article>
          ))}
          {runs && runs.last_page > 1 && (
            <div className="button-row">
              <button
                disabled={page <= 1}
                onClick={() => setPage((v) => v - 1)}
              >
                Previous
              </button>
              <span>
                {page}/{runs.last_page}
              </span>
              <button
                disabled={page >= runs.last_page}
                onClick={() => setPage((v) => v + 1)}
              >
                Next
              </button>
            </div>
          )}
        </section>
      )}
    </>
  );
}
function localDate(value: string) {
  const d = new Date(value);
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
}
