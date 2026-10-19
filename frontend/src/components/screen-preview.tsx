"use client";
import { memo, useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import InlineMedia from "./inline-media";
function ScreenPreview({ onText }: { onText: (text: string) => void }) {
  const video = useRef<HTMLVideoElement | null>(null);
  const stream = useRef<MediaStream | null>(null);
  const alive = useRef(true);
  const action = useRef<AbortController | null>(null);
  const [sharing, setSharing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [enabled, setEnabled] = useState(false);
  const [error, setError] = useState("");
  const [prompt, setPrompt] = useState(
    "Explain what is visible in this screenshot.",
  );
  const [task, setTask] = useState<number | null>(null);
  function stop() {
    const current = stream.current;
    stream.current = null;
    current?.getTracks().forEach((t) => t.stop());
    if (video.current) video.current.srcObject = null;
    if (alive.current) setSharing(false);
  }
  useEffect(() => {
    alive.current = true;
    const c = new AbortController();
    api<{ media: string[] }>("capabilities", "GET", undefined, c.signal)
      .then((r) => setEnabled(r.media.includes("vision")))
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => {
      alive.current = false;
      c.abort();
      action.current?.abort();
      stop();
    };
  }, []);
  async function start() {
    setBusy(true);
    setError("");
    try {
      if (!navigator.mediaDevices?.getDisplayMedia)
        throw new Error(
          "Screen preview needs HTTPS and a supported desktop browser.",
        );
      const media = await navigator.mediaDevices.getDisplayMedia({
        video: true,
        audio: false,
      });
      if (!alive.current) {
        media.getTracks().forEach((t) => t.stop());
        return;
      }
      stream.current = media;
      media.getVideoTracks()[0].addEventListener("ended", stop, { once: true });
      if (video.current) {
        video.current.srcObject = media;
        await video.current.play();
      }
      setSharing(true);
    } catch (e) {
      stop();
      if (alive.current) setError((e as Error).message);
    } finally {
      if (alive.current) setBusy(false);
    }
  }
  async function capture() {
    const c = new AbortController();
    action.current = c;
    setBusy(true);
    setError("");
    try {
      const v = video.current;
      if (!v?.videoWidth || !stream.current)
        throw new Error("Start a screen preview first.");
      const scale = Math.min(1, 1280 / v.videoWidth, 1280 / v.videoHeight);
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(v.videoWidth * scale));
      canvas.height = Math.max(1, Math.round(v.videoHeight * scale));
      const ctx = canvas.getContext("2d");
      if (!ctx) throw new Error("Capture is unavailable.");
      ctx.drawImage(v, 0, 0, canvas.width, canvas.height);
      const input = canvas.toDataURL("image/jpeg", 0.8).split(",")[1];
      const result = await api<{ id: number }>(
        "media",
        "POST",
        { kind: "vision", prompt, input },
        c.signal,
      );
      if (!c.signal.aborted) setTask(result.id);
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  return (
    <section className="voice-panel">
      <h3>Screen preview</h3>
      <p className="muted">
        Choose a tab, window or display. The live preview stays on this device.
        Only selecting “Send one screenshot” uploads a single frame to your
        private vision runtime; it creates a saved media task. Hide sensitive
        content first.
      </p>
      {error && <p role="alert">{error}</p>}
      <video
        ref={video}
        muted
        autoPlay
        playsInline
        className="media-preview"
        aria-label="Local screen preview"
      />
      <div className="button-row">
        {sharing ? (
          <button onClick={stop}>Stop screen preview</button>
        ) : (
          <button
            className="secondary-button"
            disabled={busy}
            onClick={() => void start()}
          >
            Choose screen
          </button>
        )}
      </div>
      <label>
        Question about the screenshot
        <textarea
          value={prompt}
          onChange={(e) => setPrompt(e.target.value)}
          maxLength={4000}
          rows={2}
        />
      </label>
      <button
        className="primary-button"
        disabled={!sharing || !enabled || busy || !prompt.trim()}
        onClick={() => void capture()}
      >
        Send one screenshot
      </button>
      {!enabled && (
        <p className="muted">
          Screenshot analysis requires an enabled private vision model.
        </p>
      )}
      {task && <InlineMedia id={task} onText={onText} />}
    </section>
  );
}

export default memo(ScreenPreview);
