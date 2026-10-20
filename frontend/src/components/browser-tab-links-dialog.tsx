"use client";
import { useState } from "react";
import Dialog from "./dialog";

export default function BrowserTabLinksDialog({
  onClose,
  onAdd,
}: {
  onClose: () => void;
  onAdd: (links: string[]) => void;
}) {
  const [value, setValue] = useState("");
  const [error, setError] = useState("");

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const parts = value.trim().split(/\s+/).filter(Boolean);
    if (!parts.length || parts.length > 10) {
      setError("Paste between 1 and 10 links, one per line.");
      return;
    }
    const urls: string[] = [];
    for (const part of parts) {
      try {
        const parsed = new URL(part);
        if (!["https:", "http:"].includes(parsed.protocol) ||
            !parsed.hostname || parsed.username || parsed.password || part.length > 2048) {
          throw new Error("Invalid browser link");
        }
        if (!urls.includes(parsed.href)) urls.push(parsed.href);
      } catch {
        setError("Enter complete http:// or https:// URLs without embedded credentials.");
        return;
      }
    }
    onAdd(urls);
  }

  return (
    <Dialog title="Add browser tab links" onClose={onClose}>
      <p className="dialog-description">
        Paste links from up to ten browser tabs. The website cannot read your
        open tabs automatically or access private pages. Public pages may be
        researched if a web search provider is available.
      </p>
      <form className="form-stack" onSubmit={submit}>
        <label>
          Browser tab links (one per line)
          <textarea rows={6} autoFocus required maxLength={20500}
            aria-label="Browser tab links"
            placeholder={"https://example.com/article\nhttps://example.org/guide"}
            value={value}
            onChange={(event) => { setValue(event.target.value); setError(""); }}
          />
        </label>
        {error && <p className="feedback error" role="alert">{error}</p>}
        <div className="button-row">
          <button type="button" className="secondary-button" onClick={onClose}>Cancel</button>
          <button type="submit" className="primary-button">Add links to message</button>
        </div>
      </form>
    </Dialog>
  );
}
