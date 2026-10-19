"use client";
import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import InlineMedia from "./inline-media";
export default function CodeRunner({
  initial = "",
  onText,
}: {
  initial?: string;
  onText: (text: string) => void;
}) {
  const action = useRef<AbortController | null>(null);
  const [code, setCode] = useState(
    initial ||
      "print('Hello from Openmindai')\n# Save downloadable files under /work/output",
  );
  const [enabled, setEnabled] = useState(false);
  const [approved, setApproved] = useState(false);
  const [task, setTask] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    const c = new AbortController();
    api<{ code_execution: boolean }>("capabilities", "GET", undefined, c.signal)
      .then((x) => setEnabled(x.code_execution))
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => {
      c.abort();
      action.current?.abort();
    };
  }, []);
  return (
    <>
      <h3>Python / data analysis</h3>
      <p className="muted">
        Review code before running it. Execution uses a separate temporary
        container with no network, no host mounts and bounded resources. Save
        charts, CSV or other artifacts under /work/output to download them as a
        ZIP.
      </p>
      {!enabled && (
        <p role="status">
          The isolated code runner is not configured on this installation.
        </p>
      )}
      {error && <p role="alert">{error}</p>}
      <form
        className="form-stack"
        onSubmit={async (e) => {
          e.preventDefault();
          if (!approved || !enabled || busy) return;
          const controller = new AbortController();
          action.current = controller;
          setBusy(true);
          setError("");
          try {
            const result = await api<{ id: number }>(
              "media",
              "POST",
              {
                kind: "code",
                prompt: code,
                approved: true,
              },
              controller.signal,
            );
            if (controller.signal.aborted) return;
            setTask(result.id);
            setApproved(false);
          } catch (e) {
            if (!controller.signal.aborted) setError((e as Error).message);
          } finally {
            if (!controller.signal.aborted) setBusy(false);
          }
        }}
      >
        <label>
          Python code
          <textarea
            className="canvas-editor"
            value={code}
            onChange={(e) => {
              setCode(e.target.value);
              setApproved(false);
            }}
            maxLength={4000}
            rows={12}
            required
          />
        </label>
        <label className="checkbox-label">
          <input
            type="checkbox"
            checked={approved}
            onChange={(e) => setApproved(e.target.checked)}
          />
          I reviewed this code and want to run it
        </label>
        <button
          className="primary-button"
          disabled={!enabled || !approved || busy}
        >
          {busy ? "Queueing…" : "Run in isolated container"}
        </button>
      </form>
      {task && <InlineMedia id={task} onText={onText} />}
    </>
  );
}
