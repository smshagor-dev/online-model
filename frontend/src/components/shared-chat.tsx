"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { api, type Message } from "@/lib/api";
import ResponseMarkdown from "./response-markdown";
export default function SharedChat({ token }: { token: string }) {
  const [data, setData] = useState<{
    title: string;
    messages: Message[];
  } | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    const c = new AbortController();
    api<{ title: string; messages: Message[] }>(
      `shared/${token}`,
      "GET",
      undefined,
      c.signal,
    )
      .then(setData)
      .catch((e) => {
        if (e.name !== "AbortError")
          setError("This link expired, was revoked, or does not exist.");
      });
    return () => c.abort();
  }, [token]);
  return (
    <main className="shared-page">
      <header>
        <Link href="/">Openmindai</Link>
        <span className="muted">Shared snapshot</span>
      </header>
      {error ? (
        <p role="alert">{error}</p>
      ) : data ? (
        <>
          <h1>{data.title}</h1>
          {data.messages.map((m, i) => (
            <article key={i} className={`shared-message ${m.role}`}>
              <small>{m.role === "user" ? "User" : "Openmindai"}</small>
              <ResponseMarkdown content={m.content} />
            </article>
          ))}
        </>
      ) : (
        <p className="muted">Loading shared conversation…</p>
      )}
    </main>
  );
}
