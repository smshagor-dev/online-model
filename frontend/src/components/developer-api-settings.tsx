"use client";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type KeyItem = { id: number; name: string; created_at: string; last_used_at: string | null; expires_at: string | null };
type KeyList = { base_url: string; keys: KeyItem[] };
type Created = { api_key: string; base_url: string };

export default function DeveloperApiSettings() {
  const [data, setData] = useState<KeyList | null>(null);
  const [label, setLabel] = useState("");
  const [password, setPassword] = useState("");
  const [secret, setSecret] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function refresh() {
    setData(await api<KeyList>("developer/keys"));
  }
  useEffect(() => {
    const controller = new AbortController();
    api<KeyList>("developer/keys", "GET", undefined, controller.signal)
      .then(setData)
      .catch((e) => { if (e?.name !== "AbortError") setError(e.message); });
    return () => controller.abort();
  }, []);
  async function create(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError("");
    setNotice("");
    setSecret("");
    try {
      const created = await api<Created>("developer/keys", "POST", { name: label.trim(), password });
      setSecret(created.api_key);
      setLabel("");
      setPassword("");
      await refresh();
      setNotice("Key created. Copy it now; you cannot view it again.");
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  async function revoke(id: number) {
    if (!window.confirm("Revoke this API key? Connected apps will lose access.")) return;
    setBusy(true);
    setError("");
    try {
      await api<null>(`developer/keys/${id}`, "DELETE");
      setSecret("");
      await refresh();
      setNotice("API key revoked.");
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  return (
    <section className="form-stack">
      <h3>Developer API</h3>
      <p className="muted">Connect your own applications to OpenMindAI Super, Ultra and Lite. API keys are private bearer secrets, not browser publishable keys.</p>
      <label className="form-stack">Base URL
        <input type="text" readOnly value={data?.base_url ?? "Loading…"} aria-label="API base URL" />
      </label>
      <p className="muted">GET /models and POST /chat/completions · Authorization: Bearer YOUR_KEY</p>
      <form onSubmit={create} className="form-stack">
        <label>Key name<input required maxLength={64} minLength={3} value={label} onChange={(e) => setLabel(e.target.value)} placeholder="My code editor" /></label>
        <label>Confirm password<input required type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} /></label>
        <button className="secondary-button" type="submit" disabled={busy}>Generate API key</button>
      </form>
      {secret && <div className="form-stack" role="status">
        <strong>New API key · visible only once</strong>
        <textarea aria-label="New API key" readOnly value={secret} rows={2} spellCheck={false} />
        <button className="secondary-button" type="button" onClick={() => navigator.clipboard.writeText(secret)}>Copy API key</button>
        <button className="secondary-button" type="button" onClick={() => setSecret("")}>Clear displayed key</button>
      </div>}
      <h3>Active developer keys</h3>
      {data?.keys.length ? data.keys.map((key) => (
        <div key={key.id} className="settings-row">
          <div><strong>{key.name.replace(/^developer:/, "")}</strong><p className="muted">Expires {key.expires_at ? new Date(key.expires_at).toLocaleDateString() : "Never"} · Last used {key.last_used_at ? new Date(key.last_used_at).toLocaleDateString() : "Never"}</p></div>
          <button className="secondary-button" type="button" disabled={busy} onClick={() => revoke(key.id)}>Revoke</button>
        </div>
      )) : <p className="muted">No developer keys yet.</p>}
      {error && <p className="feedback error" role="alert">{error}</p>}
      {notice && <p className="feedback success" role="status">{notice}</p>}
    </section>
  );
}
