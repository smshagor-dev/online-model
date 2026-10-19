"use client";
import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import { applyAppearance, type Preferences } from "@/lib/settings";
export default function SettingsPreferences({
  section,
  onChanged,
}: {
  section: "general" | "personalization";
  onChanged: () => void;
}) {
  const [prefs, setPrefs] = useState<Preferences | null>(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const action = useRef<AbortController | null>(null);
  useEffect(() => {
    const c = new AbortController();
    api<Preferences>("preferences", "GET", undefined, c.signal)
      .then(setPrefs)
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => {
      c.abort();
      action.current?.abort();
    };
  }, []);
  function field<K extends keyof Preferences>(key: K, value: Preferences[K]) {
    setPrefs((p) => (p ? { ...p, [key]: value } : p));
  }
  async function save() {
    if (!prefs) return;
    const c = new AbortController();
    action.current = c;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const saved = await api<Preferences>(
        "preferences",
        "PATCH",
        section === "general"
          ? {
              theme: prefs.theme,
              accent_color: prefs.accent_color,
              text_size: prefs.text_size,
              reduced_motion: prefs.reduced_motion,
              send_on_enter: prefs.send_on_enter,
              completion_notifications: prefs.completion_notifications,
            }
          : {
              nickname: prefs.nickname,
              occupation: prefs.occupation,
              about_you: prefs.about_you,
              custom_instructions: prefs.custom_instructions,
              personalization_enabled: prefs.personalization_enabled,
              memory_enabled: prefs.memory_enabled,
              automatic_memory: prefs.automatic_memory,
              response_style: prefs.response_style,
              response_length: prefs.response_length,
              response_language: prefs.response_language,
            },
        c.signal,
      );
      if (c.signal.aborted) return;
      setPrefs(saved);
      applyAppearance(saved);
      onChanged();
      setNotice(
        "Settings saved across your devices. Response preferences apply to future messages.",
      );
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  if (!prefs) return <p role="status">{error || "Loading settings…"}</p>;
  return (
    <>
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
      <form
        className="form-stack"
        onSubmit={(e) => {
          e.preventDefault();
          void save();
        }}
      >
        <fieldset disabled={busy} className="settings-fieldset">
          {section === "general" ? (
            <>
              <h3>Appearance & interaction</h3>
              <label>
                Theme
                <select
                  value={prefs.theme}
                  onChange={(e) =>
                    field("theme", e.target.value as Preferences["theme"])
                  }
                >
                  {["system", "light", "dark"].map((v) => (
                    <option key={v}>{v}</option>
                  ))}
                </select>
              </label>
              <label>
                Accent color
                <select
                  value={prefs.accent_color}
                  onChange={(e) => field("accent_color", e.target.value)}
                >
                  {["default", "blue", "purple", "green"].map((v) => (
                    <option key={v}>{v}</option>
                  ))}
                </select>
              </label>
              <label>
                Text size
                <select
                  value={prefs.text_size}
                  onChange={(e) => field("text_size", e.target.value)}
                >
                  {["small", "default", "large"].map((v) => (
                    <option key={v}>{v}</option>
                  ))}
                </select>
              </label>
              <label className="checkbox-label">
                <input
                  type="checkbox"
                  checked={prefs.reduced_motion}
                  onChange={(e) => field("reduced_motion", e.target.checked)}
                />
                Reduce animations and smooth scrolling
              </label>
              <label className="checkbox-label">
                <input
                  type="checkbox"
                  checked={prefs.send_on_enter}
                  onChange={(e) => field("send_on_enter", e.target.checked)}
                />
                Press Enter to send (Shift+Enter adds a line)
              </label>
              <h3>Notifications</h3>
              <label className="checkbox-label">
                <input
                  type="checkbox"
                  checked={prefs.completion_notifications}
                  onChange={async (e) => {
                    if (!e.target.checked)
                      return field("completion_notifications", false);
                    if (!("Notification" in window))
                      return setError(
                        "This browser does not support desktop notifications.",
                      );
                    const permission = await Notification.requestPermission();
                    field("completion_notifications", permission === "granted");
                    if (permission !== "granted")
                      setError(
                        "Allow notifications in your browser settings to enable this option.",
                      );
                  }}
                />
                Notify when a response finishes while this tab is hidden
              </label>
              <small className="muted">
                Requires permission on each browser. Notification text does not
                include your chat content.
              </small>
            </>
          ) : (
            <>
              <h3>Personalization</h3>
              <label className="checkbox-label">
                <input
                  type="checkbox"
                  checked={prefs.personalization_enabled}
                  onChange={(e) =>
                    field("personalization_enabled", e.target.checked)
                  }
                />
                Use my profile and custom response preferences
              </label>
              <label>
                What should Openmindai call you?
                <input
                  value={prefs.nickname || ""}
                  maxLength={80}
                  onChange={(e) => field("nickname", e.target.value)}
                />
              </label>
              <label>
                Occupation
                <input
                  value={prefs.occupation || ""}
                  maxLength={160}
                  onChange={(e) => field("occupation", e.target.value)}
                />
              </label>
              <label>
                More about you
                <textarea
                  rows={3}
                  maxLength={4000}
                  value={prefs.about_you || ""}
                  onChange={(e) => field("about_you", e.target.value)}
                />
              </label>
              <label>
                Response style
                <select
                  value={prefs.response_style}
                  onChange={(e) => field("response_style", e.target.value)}
                >
                  {[
                    "default",
                    "professional",
                    "friendly",
                    "candid",
                    "concise",
                  ].map((v) => (
                    <option key={v}>{v}</option>
                  ))}
                </select>
              </label>
              <label>
                Response detail
                <select
                  value={prefs.response_length}
                  onChange={(e) => field("response_length", e.target.value)}
                >
                  {["default", "short", "detailed"].map((v) => (
                    <option key={v}>{v}</option>
                  ))}
                </select>
              </label>
              <label>
                Response language
                <select
                  value={prefs.response_language}
                  onChange={(e) => field("response_language", e.target.value)}
                >
                  {[
                    "auto",
                    "English",
                    "Bangla",
                    "Russian",
                    "Arabic",
                    "Hindi",
                    "Spanish",
                    "French",
                    "German",
                  ].map((v) => (
                    <option key={v} value={v}>
                      {v === "auto" ? "Match my message" : v}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                Custom instructions
                <textarea
                  rows={5}
                  maxLength={8000}
                  value={prefs.custom_instructions || ""}
                  onChange={(e) => field("custom_instructions", e.target.value)}
                />
              </label>
              <label className="checkbox-label">
                <input
                  type="checkbox"
                  checked={prefs.memory_enabled}
                  onChange={(e) => field("memory_enabled", e.target.checked)}
                />
                Use my saved memories
              </label>
              <label className="check-row">
                <input
                  type="checkbox"
                  checked={prefs.automatic_memory}
                  disabled={!prefs.memory_enabled}
                  onChange={(e) => field("automatic_memory", e.target.checked)}
                />
                Automatically remember preferences from completed chats
              </label>
              <p className="muted">
                Off by default. When enabled, the model may save facts from your
                messages for future chats. Review or delete saved memories in
                Memory settings. Temporary chats are excluded.
              </p>

              <small className="muted">
                Memory is controlled separately. Temporary chats ignore both
                profile customization and memories. Language quality depends on
                the downloaded model. These settings change responses, not the
                interface language.
              </small>
            </>
          )}
          <button className="primary-button">
            {busy ? "Saving…" : "Save settings"}
          </button>
        </fieldset>
      </form>
      {section === "personalization" && <MemoryManager onChanged={onChanged} />}
    </>
  );
}
function MemoryManager({ onChanged }: { onChanged: () => void }) {
  const [items, setItems] = useState<{ id: number; content: string }[]>([]);
  const [content, setContent] = useState("");
  const [id, setId] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const c = useRef<AbortController | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    c.current = controller;
    api<typeof items>("memories", "GET", undefined, controller.signal)
      .then(setItems)
      .catch((e) => {
        if (!controller.signal.aborted) setError(e.message);
      });
    return () => c.current?.abort();
  }, []);
  async function change(remove?: number) {
    c.current?.abort();
    const controller = new AbortController();
    c.current = controller;
    setBusy(true);
    setError("");
    try {
      await api(
        remove ? `memories/${remove}` : id ? `memories/${id}` : "memories",
        remove ? "DELETE" : id ? "PUT" : "POST",
        { content },
        controller.signal,
      );
      const result = await api<typeof items>(
        "memories",
        "GET",
        undefined,
        controller.signal,
      );
      if (!controller.signal.aborted) {
        setItems(result);
        setContent("");
        setId(null);
        onChanged();
      }
    } catch (e) {
      if (!controller.signal.aborted) setError((e as Error).message);
    } finally {
      if (!controller.signal.aborted) setBusy(false);
    }
  }
  return (
    <section>
      <hr />
      <h3>Manage saved memories</h3>
      <p className="muted">
        Only notes you explicitly save are remembered. Deleting a memory does
        not delete chat messages that mention it.
      </p>
      {error && <p role="alert">{error}</p>}
      <form
        className="form-stack"
        onSubmit={(e) => {
          e.preventDefault();
          void change();
        }}
      >
        <label>
          {id ? "Edit memory" : "Add memory"}
          <textarea
            required
            rows={2}
            maxLength={2000}
            value={content}
            onChange={(e) => setContent(e.target.value)}
          />
        </label>
        <div className="button-row">
          <button className="secondary-button" disabled={busy}>
            Save memory
          </button>
          {id && (
            <button
              type="button"
              onClick={() => {
                setId(null);
                setContent("");
              }}
            >
              Cancel
            </button>
          )}
        </div>
      </form>
      {items.map((m) => (
        <div className="manage-card" key={m.id}>
          <p>{m.content}</p>
          <div className="button-row">
            <button
              disabled={busy}
              onClick={() => {
                setId(m.id);
                setContent(m.content);
              }}
            >
              Edit
            </button>
            <button
              disabled={busy}
              className="danger-text"
              onClick={() => {
                if (confirm("Delete this saved memory?")) void change(m.id);
              }}
            >
              Delete
            </button>
          </div>
        </div>
      ))}
    </section>
  );
}
