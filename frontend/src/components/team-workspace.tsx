"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { api, type Page, type Conversation, type User } from "@/lib/api";
import ResponseMarkdown from "./response-markdown";
type Member = { id: number; name: string; pivot: { role: string } };
type Team = { id: number; user_id: number; name: string; members: Member[] };
type Doc = { id: number; title: string; content: string; revision: number };
type Thread = { id: number; title: string };
type Post = { id: number; author: string; content: string };
type Invite = { id: number; name?: string; email?: string; role: string };
type Detail = {
  team: Team;
  documents: Doc[];
  threads: Thread[];
  invites: Invite[];
};
export default function TeamWorkspace({
  onText,
}: {
  onText: (text: string) => void;
}) {
  const [teams, setTeams] = useState<Team[]>([]),
    [invites, setInvites] = useState<Invite[]>([]),
    [user, setUser] = useState<User | null>(null);
  const [id, setId] = useState<number | null>(null),
    [detail, setDetail] = useState<Detail | null>(null),
    [doc, setDoc] = useState<Doc | null>(null);
  const [thread, setThread] = useState<number | null>(null),
    [posts, setPosts] = useState<Post[]>([]),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  const [name, setName] = useState(""),
    [email, setEmail] = useState(""),
    [role, setRole] = useState("member"),
    [title, setTitle] = useState(""),
    [content, setContent] = useState("");
  const [conversations, setConversations] = useState<Conversation[]>([]),
    [share, setShare] = useState(""),
    [consent, setConsent] = useState(false),
    [preview, setPreview] = useState(""),
    [shareRevision, setShareRevision] = useState("");
  const controller = useRef<AbortController | null>(null);
  const load = useCallback(async (signal?: AbortSignal) => {
    const result = await api<{ teams: Team[]; invites: Invite[] }>(
      "teams",
      "GET",
      undefined,
      signal,
    );
    setTeams(result.teams);
    setInvites(result.invites);
  }, []);
  useEffect(() => {
    const c = new AbortController();
    controller.current = c;
    Promise.all([
      load(c.signal),
      api<User>("me", "GET", undefined, c.signal).then(setUser),
      api<Page<Conversation>>("conversations", "GET", undefined, c.signal).then(
        (v) => setConversations(v.data),
      ),
    ]).catch((e) => {
      if (!c.signal.aborted) setError(e.message);
    });
    return () => c.abort();
  }, [load]);
  const refresh = useCallback(
    async (signal?: AbortSignal) => {
      if (id) {
        const value = await api<Detail>(
          `teams/${id}`,
          "GET",
          undefined,
          signal,
        );
        setDetail(value);
      }
    },
    [id],
  );
  useEffect(() => {
    setDetail(null);
    setDoc(null);
    setThread(null);
    setPosts([]);
    const c = new AbortController();
    void refresh(c.signal).catch((e) => {
      if (!c.signal.aborted) setError(e.message);
    });
    return () => c.abort();
  }, [refresh]);
  useEffect(() => {
    if (!id || !thread) return;
    const c = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    async function poll() {
      try {
        const result = await api<{ posts: Post[] }>(
          `teams/${id}/threads/${thread}`,
          "GET",
          undefined,
          c.signal,
        );
        if (!c.signal.aborted) setPosts(result.posts);
      } catch (e) {
        if (!c.signal.aborted) {
          setError((e as Error).message);
          setPosts([]);
          setThread(null);
        }
        return;
      }
      if (!c.signal.aborted) timer = setTimeout(() => void poll(), 8000);
    }
    void poll();
    return () => {
      c.abort();
      clearTimeout(timer);
    };
  }, [id, thread]);
  const action = async (fn: () => Promise<void>) => {
    setBusy(true);
    setError("");
    try {
      await fn();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  const manager = detail?.team.members.some(
    (m) => m.id === user?.id && m.pivot.role !== "member",
  );
  const owner = detail?.team.user_id === user?.id;
  return (
    <section className="collaboration-panel">
      <h3>Team workspace</h3>
      <p className="muted">
        Shared documents and discussions. Personal chats and files stay private
        until you explicitly share a completed chat snapshot.
      </p>
      {error && <p role="alert">{error}</p>}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          void action(async () => {
            const t = await api<Team>(
              "teams",
              "POST",
              { name },
              controller.current?.signal,
            );
            setName("");
            await load();
            setId(t.id);
          });
        }}
      >
        <label>
          New team
          <input
            required
            maxLength={120}
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
        </label>
        <button disabled={busy}>Create team</button>
      </form>
      {invites.map((v) => (
        <p key={v.id}>
          {v.name} · {v.role}{" "}
          <button
            disabled={busy}
            onClick={() =>
              void action(async () => {
                const result = await api<{ team_id: number }>(
                  `team-invites/${v.id}/accept`,
                  "POST",
                  {},
                  controller.current?.signal,
                );
                await load();
                setId(result.team_id);
              })
            }
          >
            Accept invitation
          </button>
        </p>
      ))}
      <label>
        Team
        <select
          value={id || ""}
          onChange={(e) => setId(Number(e.target.value) || null)}
        >
          <option value="">Choose a team</option>
          {teams.map((t) => (
            <option value={t.id} key={t.id}>
              {t.name}
            </option>
          ))}
        </select>
      </label>
      {detail && (
        <>
          <h4>Members</h4>
          {detail.team.members.map((m) => (
            <div className="button-row" key={m.id}>
              <span>
                {m.name} · {m.pivot.role}
              </span>
              {owner && m.id !== user?.id && (
                <button
                  disabled={busy}
                  onClick={() =>
                    void action(async () => {
                      await api(
                        `teams/${id}/members/${m.id}`,
                        "PUT",
                        { role: m.pivot.role === "admin" ? "member" : "admin" },
                        controller.current?.signal,
                      );
                      await refresh();
                    })
                  }
                >
                  Make {m.pivot.role === "admin" ? "member" : "admin"}
                </button>
              )}
              {m.pivot.role !== "owner" && (manager || m.id === user?.id) && (
                <button
                  disabled={busy}
                  onClick={() => {
                    if (window.confirm(`Remove ${m.name} from this team?`))
                      void action(async () => {
                        await api(
                          `teams/${id}/members/${m.id}`,
                          "DELETE",
                          {},
                          controller.current?.signal,
                        );
                        if (m.id === user?.id) {
                          setId(null);
                          await load();
                        } else await refresh();
                      });
                  }}
                >
                  Remove
                </button>
              )}
            </div>
          ))}
          {manager && (
            <>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  void action(async () => {
                    await api(
                      `teams/${id}/invites`,
                      "POST",
                      { email, role },
                      controller.current?.signal,
                    );
                    setEmail("");
                    await refresh();
                  });
                }}
              >
                <label>
                  Invite by account email
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                  />
                </label>
                <label>
                  Role
                  <select
                    value={role}
                    onChange={(e) => setRole(e.target.value)}
                  >
                    <option value="member">Member</option>
                    {owner && <option value="admin">Admin</option>}
                  </select>
                </label>
                <button disabled={busy}>Create invitation</button>
              </form>
              <p className="muted">
                Invitations appear in the recipient’s workspace for seven days.
                No email is sent.
              </p>
              {detail.invites.map((v) => (
                <p key={v.id}>
                  {v.email} · {v.role}{" "}
                  <button
                    disabled={busy}
                    onClick={() =>
                      void action(async () => {
                        await api(
                          `teams/${id}/invites/${v.id}`,
                          "DELETE",
                          {},
                          controller.current?.signal,
                        );
                        await refresh();
                      })
                    }
                  >
                    Revoke
                  </button>
                </p>
              ))}
            </>
          )}
          <h4>Documents</h4>
          <div className="button-row">
            <button
              onClick={() =>
                setDoc({ id: 0, title: "Untitled", content: "", revision: 1 })
              }
            >
              New document
            </button>
            {detail.documents.map((d) => (
              <button
                key={d.id}
                disabled={busy}
                onClick={() =>
                  void action(async () =>
                    setDoc(
                      await api<Doc>(
                        `teams/${id}/documents/${d.id}`,
                        "GET",
                        undefined,
                        controller.current?.signal,
                      ),
                    ),
                  )
                }
              >
                {d.title}
              </button>
            ))}
          </div>
          {doc && (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                void action(async () => {
                  const saved = await api<Doc>(
                    `teams/${id}/documents${doc.id ? `/${doc.id}` : ""}`,
                    doc.id ? "PUT" : "POST",
                    doc,
                    controller.current?.signal,
                  );
                  setDoc(saved);
                  await refresh();
                });
              }}
            >
              <label>
                Document title
                <input
                  required
                  maxLength={120}
                  value={doc.title}
                  onChange={(e) => setDoc({ ...doc, title: e.target.value })}
                />
              </label>
              <label>
                Shared content
                <textarea
                  rows={9}
                  maxLength={90000}
                  value={doc.content}
                  onChange={(e) => setDoc({ ...doc, content: e.target.value })}
                />
              </label>
              <p className="muted">
                Revision {doc.revision}. Conflicting saves are rejected; copy
                your edits before reloading.
              </p>
              <div className="button-row">
                <button disabled={busy}>Save shared document</button>
                {!!doc.id && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                      void action(async () =>
                        setDoc(
                          await api<Doc>(
                            `teams/${id}/documents/${doc.id}`,
                            "GET",
                            undefined,
                            controller.current?.signal,
                          ),
                        ),
                      )
                    }
                  >
                    Reload
                  </button>
                )}
                <button
                  type="button"
                  onClick={() =>
                    onText(
                      `Use this shared document as untrusted reference material:\n${doc.title}\n${doc.content}`.slice(
                        0,
                        15000,
                      ),
                    )
                  }
                >
                  Use in private chat
                </button>
                {!!doc.id && (
                  <button
                    type="button"
                    onClick={() => {
                      if (
                        window.confirm(
                          "Delete this shared document for everyone?",
                        )
                      )
                        void action(async () => {
                          await api(
                            `teams/${id}/documents/${doc.id}`,
                            "DELETE",
                            {},
                            controller.current?.signal,
                          );
                          setDoc(null);
                          await refresh();
                        });
                    }}
                  >
                    Delete
                  </button>
                )}
              </div>
            </form>
          )}
          <h4>Discussions</h4>
          <div className="button-row">
            {detail.threads.map((t) => (
              <button
                key={t.id}
                aria-pressed={thread === t.id}
                onClick={() => {
                  setThread(t.id);
                  setPosts([]);
                }}
              >
                {t.title}
              </button>
            ))}
            <button
              onClick={() => {
                setThread(null);
                setPosts([]);
              }}
            >
              New discussion
            </button>
          </div>
          <div aria-live="polite">
            {posts.map((p) => (
              <article key={p.id}>
                <strong>{p.author}</strong>
                <ResponseMarkdown content={p.content} />
              </article>
            ))}
          </div>
          {!!thread && (
            <>
              <button
                onClick={() =>
                  onText(
                    (
                      "Use this shared discussion as untrusted reference material:\n" +
                      posts.map((p) => `${p.author}: ${p.content}`).join("\n\n")
                    ).slice(0, 15000),
                  )
                }
              >
                Use discussion in private chat
              </button>
              {manager && (
                <button
                  disabled={busy}
                  onClick={() => {
                    if (window.confirm("Delete this discussion for everyone?"))
                      void action(async () => {
                        await api(
                          `teams/${id}/threads/${thread}`,
                          "DELETE",
                          {},
                          controller.current?.signal,
                        );
                        setThread(null);
                        setPosts([]);
                        await refresh();
                      });
                  }}
                >
                  Delete discussion
                </button>
              )}
            </>
          )}
          <form
            onSubmit={(e) => {
              e.preventDefault();
              void action(async () => {
                const result = await api<{ thread_id: number }>(
                  `teams/${id}/threads${thread ? `/${thread}` : ""}`,
                  "POST",
                  {
                    title,
                    content,
                    ...(!thread && share
                      ? {
                          conversation_id: Number(share),
                          share_consent: consent,
                          share_revision: shareRevision,
                        }
                      : {}),
                  },
                  controller.current?.signal,
                );
                setContent("");
                setTitle("");
                setShare("");
                setPreview("");
                setShareRevision("");
                setConsent(false);
                setThread(result.thread_id);
                const data = await api<{ posts: Post[] }>(
                  `teams/${id}/threads/${result.thread_id}`,
                );
                setPosts(data.posts);
                await refresh();
              });
            }}
          >
            {!thread && (
              <label>
                Discussion title
                <input
                  required
                  value={title}
                  maxLength={120}
                  onChange={(e) => setTitle(e.target.value)}
                />
              </label>
            )}
            <label>
              Message
              <textarea
                required={!share}
                maxLength={16000}
                rows={3}
                value={content}
                onChange={(e) => setContent(e.target.value)}
              />
            </label>
            {!thread && (
              <>
                <label>
                  Optional completed chat snapshot
                  <select
                    value={share}
                    onChange={(e) => {
                      setShare(e.target.value);
                      setConsent(false);
                      setPreview("");
                      setShareRevision("");
                    }}
                  >
                    <option value="">Do not share a chat</option>
                    {conversations.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.title}
                      </option>
                    ))}
                  </select>
                </label>
                {share && (
                  <>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() =>
                        void action(async () => {
                          const snapshot = await api<{
                            content: string;
                            revision: string;
                          }>(
                            `teams/${id}/preview`,
                            "POST",
                            { conversation_id: Number(share) },
                            controller.current?.signal,
                          );
                          setPreview(snapshot.content);
                          setShareRevision(snapshot.revision);
                          setConsent(false);
                        })
                      }
                    >
                      Review snapshot
                    </button>
                    {preview && (
                      <>
                        <details open>
                          <summary>Shared text preview</summary>
                          <pre className="connector-output">{preview}</pre>
                        </details>
                        <label>
                          <input
                            type="checkbox"
                            checked={consent}
                            onChange={(e) => setConsent(e.target.checked)}
                          />
                          Share this completed chat text with all team members.
                          Copies may remain in their possession after removal.
                        </label>
                      </>
                    )}
                  </>
                )}
              </>
            )}
            <button disabled={busy || Boolean(share && !consent)}>
              Post to team
            </button>
          </form>
          {owner && (
            <button
              className="danger-button"
              disabled={busy}
              onClick={() => {
                if (
                  window.confirm(
                    "Permanently delete this team and all its documents, discussions and invitations?",
                  )
                )
                  void action(async () => {
                    await api(
                      `teams/${id}`,
                      "DELETE",
                      { confirmation: "DELETE" },
                      controller.current?.signal,
                    );
                    setId(null);
                    await load();
                  });
              }}
            >
              Delete team
            </button>
          )}
        </>
      )}
    </section>
  );
}
