"use client";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
export default function ConfirmEmail({
  account,
  token,
}: {
  account: string;
  token: string;
}) {
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const c = useRef<AbortController | null>(null);
  useEffect(() => {
    history.replaceState(null, "", "/confirm-email");
    return () => c.current?.abort();
  }, []);
  return (
    <main className="email-confirm-page">
      <h1>Confirm email change</h1>
      <p>
        Confirming replaces the account email with the requested new address and
        signs out all devices.
      </p>
      <p>Only confirm if you requested this change.</p>
      {message && <p role="status">{message}</p>}
      {!done && (
        <button
          className="primary-button"
          disabled={busy || !/^\d+$/.test(account) || token.length !== 64}
          onClick={async () => {
            const controller = new AbortController();
            c.current = controller;
            setBusy(true);
            try {
              const result = await api<{ message: string }>(
                "email/change/confirm",
                "POST",
                { account: Number(account), token },
                controller.signal,
              );
              if (!controller.signal.aborted) {
                setMessage(result.message);
                setDone(true);
              }
            } catch (e) {
              if (!controller.signal.aborted) setMessage((e as Error).message);
            } finally {
              if (!controller.signal.aborted) setBusy(false);
            }
          }}
        >
          {busy ? "Confirming…" : "Confirm my email change"}
        </button>
      )}
      <Link href="/">Return to sign in</Link>
    </main>
  );
}
