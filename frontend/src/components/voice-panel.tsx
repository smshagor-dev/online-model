"use client";
import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
export default function VoicePanel({
  onText,
}: {
  onText: (text: string) => void;
}) {
  const [available, setAvailable] = useState(false);
  const [recording, setRecording] = useState(false);
  const [busy, setBusy] = useState(false);
  const [transcript, setTranscript] = useState("");
  const [error, setError] = useState("");
  const [level, setLevel] = useState(0);
  const stream = useRef<MediaStream | null>(null);
  const recorder = useRef<MediaRecorder | null>(null);
  const controller = useRef<AbortController | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const audio = useRef<AudioContext | null>(null);
  const frame = useRef<number>(0);
  const alive = useRef(true);
  function cleanup() {
    stream.current?.getTracks().forEach((t) => t.stop());
    stream.current = null;
    if (timer.current) clearTimeout(timer.current);
    cancelAnimationFrame(frame.current);
    if (audio.current && audio.current.state !== "closed")
      void audio.current.close().catch(() => {});
    audio.current = null;
  }
  useEffect(() => {
    alive.current = true;
    const c = new AbortController();
    api<{ media: string[] }>("capabilities", "GET", undefined, c.signal)
      .then((caps) => setAvailable(caps.media.includes("transcription")))
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => {
      alive.current = false;
      c.abort();
      controller.current?.abort();
      if (recorder.current?.state === "recording") recorder.current.stop();
      cleanup();
    };
  }, []);
  async function transcribe(blob: Blob) {
    const c = new AbortController();
    controller.current = c;
    setBusy(true);
    try {
      const input = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result).split(",")[1]);
        reader.onerror = () => reject(new Error("Unable to read recording."));
        reader.readAsDataURL(blob);
      });
      if (c.signal.aborted) return;
      const task = await api<{ id: number }>(
        "media",
        "POST",
        { kind: "transcription", prompt: "", input },
        c.signal,
      );
      let result;
      for (let i = 0; i < 180; i++) {
        await new Promise<void>((resolve, reject) => {
          const abort = () => {
            clearTimeout(wait);
            reject(new DOMException("Aborted", "AbortError"));
          };
          const wait = setTimeout(() => {
            c.signal.removeEventListener("abort", abort);
            resolve();
          }, 2000);
          c.signal.addEventListener("abort", abort, { once: true });
          if (c.signal.aborted) abort();
        });
        result = await api<{
          status: string;
          result_text: string;
          error: string;
        }>(`media/${task.id}`, "GET", undefined, c.signal);
        if (result.status === "complete") {
          setTranscript(result.result_text);
          return;
        }
        if (result.status === "failed") throw new Error(result.error);
      }
      throw new Error(
        "Transcription is still queued. Find it in Library or Media studio.",
      );
    } catch (e) {
      if (!c.signal.aborted && alive.current) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted && alive.current) setBusy(false);
    }
  }
  async function start() {
    setBusy(true);
    setError("");
    try {
      if (
        !navigator.mediaDevices?.getUserMedia ||
        typeof MediaRecorder === "undefined"
      )
        throw new Error(
          "Microphone recording needs HTTPS and a supported browser.",
        );
      const media = await navigator.mediaDevices.getUserMedia({ audio: true });
      if (!alive.current) {
        media.getTracks().forEach((t) => t.stop());
        return;
      }
      stream.current = media;
      const format = [
        "audio/webm;codecs=opus",
        "audio/webm",
        "audio/ogg;codecs=opus",
      ].find((x) => MediaRecorder.isTypeSupported(x));
      if (!format)
        throw new Error(
          "Use a browser supporting WebM or OGG audio recording.",
        );
      const r = new MediaRecorder(media, { mimeType: format });
      recorder.current = r;
      const parts: BlobPart[] = [];
      r.ondataavailable = (e) => {
        if (e.data.size) parts.push(e.data);
      };
      r.onstop = () => {
        cleanup();
        if (alive.current) {
          setRecording(false);
          void transcribe(new Blob(parts, { type: format }));
        }
      };
      audio.current = new AudioContext();
      const analyser = audio.current.createAnalyser();
      audio.current.createMediaStreamSource(media).connect(analyser);
      const samples = new Uint8Array(analyser.fftSize);
      function draw() {
        analyser.getByteTimeDomainData(samples);
        let sum = 0;
        for (const v of samples) sum += (v - 128) ** 2;
        if (alive.current)
          setLevel(Math.min(100, Math.sqrt(sum / samples.length) * 4));
        frame.current = requestAnimationFrame(draw);
      }
      draw();
      r.start();
      setRecording(true);
      setBusy(false);
      timer.current = setTimeout(() => r.stop(), 110000);
    } catch (e) {
      cleanup();
      setBusy(false);
      setError((e as Error).message);
    }
  }
  return (
    <section className="voice-panel">
      <h3>Voice input</h3>
      <p className="muted">
        Record up to 110 seconds, then transcribe with your private model.
        Review the transcript before sending. This is turn-based input;
        continuous realtime conversation, screen sharing and background
        listening are not enabled.
      </p>
      {!available && (
        <p role="status">
          An administrator must enable the self-hosted transcription engine.
        </p>
      )}
      {error && <p role="alert">{error}</p>}
      <div
        className="voice-meter"
        role="meter"
        aria-label="Microphone level"
        aria-valuenow={Math.round(level)}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <span style={{ width: `${recording ? level : 0}%` }} />
      </div>
      <div className="button-row">
        {recording ? (
          <button
            className="danger-button"
            onClick={() => recorder.current?.stop()}
          >
            Stop & transcribe
          </button>
        ) : (
          <button
            className="primary-button"
            disabled={!available || busy}
            onClick={() => void start()}
          >
            Start recording
          </button>
        )}
        {busy && (
          <>
            <span role="status">Transcribing…</span>
            <button
              onClick={() => {
                controller.current?.abort();
                setBusy(false);
              }}
            >
              Stop waiting
            </button>
          </>
        )}
      </div>
      <label>
        Transcript
        <textarea
          rows={5}
          value={transcript}
          onChange={(e) => setTranscript(e.target.value)}
          maxLength={16000}
        />
      </label>
      <button
        className="secondary-button"
        disabled={!transcript.trim()}
        onClick={() => onText(transcript)}
      >
        Use transcript in chat
      </button>
      <p className="muted">
        Recordings create a saved media task. Inputs are deleted after
        processing; transcript tasks remain until deleted from Library.
      </p>
    </section>
  );
}
