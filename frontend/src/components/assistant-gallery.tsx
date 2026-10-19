"use client";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { api, type Page } from "@/lib/api";

type Template = {
  slug: string;
  name: string;
  description: string | null;
  author: string;
  instructions?: string;
};
export default function AssistantGallery({
  compact = false,
}: {
  compact?: boolean;
}) {
  const [items, setItems] = useState<Page<Template> | null>(null);
  const [selected, setSelected] = useState<Template | null>(null);
  const [query, setQuery] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const action = useRef<AbortController | null>(null);
  useEffect(() => {
    const c = new AbortController();
    setError("");
    api<Page<Template>>(
      `explore/assistants?page=${page}&q=${encodeURIComponent(search)}`,
      "GET",
      undefined,
      c.signal,
    )
      .then(setItems)
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [page, search]);
  useEffect(() => () => action.current?.abort(), []);
  async function inspect(item: Template) {
    action.current?.abort();
    const c = new AbortController();
    action.current = c;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const result = await api<Template>(
        `explore/assistants/${item.slug}`,
        "GET",
        undefined,
        c.signal,
      );
      if (!c.signal.aborted) setSelected(result);
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  async function copy() {
    if (!selected) return;
    const c = new AbortController();
    action.current?.abort();
    action.current = c;
    setBusy(true);
    setError("");
    try {
      await api(
        `explore/assistants/${selected.slug}/copy`,
        "POST",
        {},
        c.signal,
      );
      if (!c.signal.aborted)
        setNotice(
          "Added to your private assistants. Open the chat workspace to use it.",
        );
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  return (
    <section
      className={
        compact ? "assistant-gallery" : "assistant-gallery gallery-page"
      }
    >
      {!compact && (
        <nav>
          <Link href="/">Openmindai · Back to chat</Link>
        </nav>
      )}
      <h1>Explore assistants</h1>
      <p>
        Public templates created by users. Review the instructions before adding
        one to your private assistants. Templates use your selected model and do
        not grant tool permissions.
      </p>
      <form
        className="button-row"
        onSubmit={(e) => {
          e.preventDefault();
          setSearch(query);
          setPage(1);
        }}
      >
        <label>
          Search templates
          <input
            value={query}
            maxLength={100}
            onChange={(e) => setQuery(e.target.value)}
          />
        </label>
        <button>Search</button>
      </form>
      {error && <p role="alert">{error}</p>}
      {notice && <p role="status">{notice}</p>}
      {!items && !error && <p role="status">Loading templates…</p>}
      {items?.data.length === 0 && (
        <p>No public templates found. Publish one from Your assistants.</p>
      )}
      <div className="tool-cards">
        {items?.data.map((item) => (
          <article className="manage-card" key={item.slug}>
            <h2>{item.name}</h2>
            <p>{item.description}</p>
            <small>By {item.author}</small>
            <p>
              <button disabled={busy} onClick={() => void inspect(item)}>
                Review template
              </button>
            </p>
          </article>
        ))}
      </div>
      {selected && (
        <article className="manage-card">
          <h2>{selected.name}</h2>
          <p>Instructions by {selected.author}</p>
          <pre style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere" }}>
            {selected.instructions}
          </pre>
          <div className="button-row">
            <button disabled={busy} onClick={() => void copy()}>
              Add to my assistants
            </button>
            <button disabled={busy} onClick={() => setSelected(null)}>
              Close preview
            </button>
          </div>
          <p>
            Sign in to add a private copy. Attached files, credentials and the
            author's model configuration are not copied. Future edits to the
            public template do not change your copy.
          </p>
        </article>
      )}
      {items && items.last_page > 1 && (
        <div className="button-row">
          <button
            disabled={page <= 1 || busy}
            onClick={() => setPage((p) => p - 1)}
          >
            Previous
          </button>
          <span>
            {page} / {items.last_page}
          </span>
          <button
            disabled={page >= items.last_page || busy}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </button>
        </div>
      )}
    </section>
  );
}
