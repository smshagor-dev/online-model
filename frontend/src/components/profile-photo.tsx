"use client";
import { useEffect, useRef, useState } from "react";
import { api, type User } from "@/lib/api";
export default function ProfilePhoto({
  user,
  onUpdate,
}: {
  user: User;
  onUpdate: (u: User) => void;
}) {
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  async function change(file: File | null) {
    const c = new AbortController();
    controller.current = c;
    setBusy(true);
    setError("");
    try {
      let image: string | undefined;
      if (file) {
        if (
          !["image/png", "image/jpeg", "image/webp"].includes(file.type) ||
          file.size > 5_000_000
        )
          throw new Error("Choose a PNG, JPEG or WebP up to 5 MB.");
        const bitmap = await createImageBitmap(file, {
          resizeWidth: 256,
          resizeHeight: 256,
          resizeQuality: "high",
        });
        try {
          const canvas = document.createElement("canvas");
          canvas.width = 256;
          canvas.height = 256;
          const ctx = canvas.getContext("2d");
          if (!ctx) throw new Error("Image processing is unavailable.");
          ctx.drawImage(bitmap, 0, 0, 256, 256);
          image = canvas.toDataURL("image/jpeg", 0.85).split(",")[1];
        } finally {
          bitmap.close();
        }
      }
      if (c.signal.aborted) return;
      const updated = await api<User>(
        "me/avatar",
        file ? "POST" : "DELETE",
        file ? { image } : undefined,
        c.signal,
      );
      if (!c.signal.aborted) onUpdate(updated);
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  return (
    <div className="profile-photo">
      <span className="avatar">
        {user.avatar ? (
          <img src={user.avatar} alt="Your profile" />
        ) : (
          user.name.charAt(0).toUpperCase()
        )}
      </span>
      <label>
        Profile photo
        <input
          type="file"
          disabled={busy}
          accept="image/png,image/jpeg,image/webp"
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) void change(file);
            e.target.value = "";
          }}
        />
      </label>
      {user.avatar && (
        <button disabled={busy} onClick={() => void change(null)}>
          Remove photo
        </button>
      )}
      {busy && <p role="status">Updating photo…</p>}
      {error && <p role="alert">{error}</p>}
    </div>
  );
}
