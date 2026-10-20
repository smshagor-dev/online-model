"use client";
import { useState } from "react";
import { ArrowRight, LoaderCircle, Mail, ShieldCheck } from "lucide-react";
import Dialog from "./dialog";
import { api, type User } from "@/lib/api";
type Mode = "login" | "register" | "forgot";
export default function AuthDialog({
  onClose,
  onLogin,
}: {
  onClose: () => void;
  onLogin: (user: User) => void;
}) {
  const [mode, setMode] = useState<Mode>("login");
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [showCode, setShowCode] = useState(false);
  function change(next: Mode) {
    setMode(next);
    setError("");
    setNotice("");
  }
  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setNotice("");
    setBusy(true);
    const data = Object.fromEntries(new FormData(event.currentTarget));
    try {
      if (mode === "login") {
        const result = await api<{ user: User }>("login", "POST", {
          ...data,
          device_name: "Openmindai Web",
        });
        onLogin(result.user);
      } else if (mode === "register") {
        await api("register", "POST", data);
        setMode("login");
        setNotice(
          "Account created. Check your email to verify it, then sign in.",
        );
      } else {
        await api("forgot-password", "POST", data);
        setNotice("If an account exists, a password reset link has been sent.");
      }
    } catch (e) {
      const message = e instanceof Error ? e.message : "Unable to continue.";
      setError(message);
      if (message.toLowerCase().includes("two-factor")) setShowCode(true);
    } finally {
      setBusy(false);
    }
  }
  async function resend() {
    if (!email) {
      setError("Enter your email first.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      await api("email/resend", "POST", { email });
      setNotice("If eligible, a verification email has been sent.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog
      title={
        mode === "register"
          ? "Create your account"
          : mode === "forgot"
            ? "Reset your password"
            : "Welcome back"
      }
      onClose={() => {
        if (!busy) onClose();
      }}
    >
      <p className="dialog-description">
        {mode === "register"
          ? "A space for your ideas. Your conversations, saved in one place."
          : mode === "forgot"
            ? "We'll send a reset link to your email address."
            : "Pick up where you left off."}
      </p>
      <form onSubmit={submit} className="form-stack">
        {mode === "register" && (
          <label>
            Full name
            <input
              name="name"
              autoComplete="name"
              required
              maxLength={120}
              autoFocus
            />
          </label>
        )}
        <label>
          Email address
          <input
            name="email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoFocus={mode !== "register"}
          />
        </label>
        {mode !== "forgot" && (
          <label>
            Password
            <input
              name="password"
              type="password"
              autoComplete={
                mode === "register" ? "new-password" : "current-password"
              }
              required
              minLength={mode === "register" ? 6 : undefined}
            />
          </label>
        )}
        {mode === "register" && (
          <>
            <small className="muted">
              At least 6 characters.
            </small>
            <label>
              Confirm password
              <input
                name="password_confirmation"
                type="password"
                autoComplete="new-password"
                required
                minLength={6}
              />
            </label>
          </>
        )}
        {mode === "login" &&
          (showCode ? (
            <label>
              Authenticator or recovery code
              <input
                name="code"
                autoComplete="one-time-code"
                placeholder="6-digit code or recovery code"
                maxLength={64}
              />
            </label>
          ) : (
            <button
              className="text-button align-start"
              type="button"
              onClick={() => setShowCode(true)}
            >
              <ShieldCheck size={15} /> Use a two-factor code
            </button>
          ))}
        {error && (
          <p className="feedback error" role="alert">
            {error}
          </p>
        )}
        {notice && (
          <p className="feedback success" role="status">
            <Mail size={17} />
            {notice}
          </p>
        )}
        <button className="primary-button" disabled={busy}>
          {busy ? (
            <LoaderCircle className="spin" size={18} />
          ) : (
            <>
              {mode === "register"
                ? "Create account"
                : mode === "forgot"
                  ? "Send reset link"
                  : "Continue"}
              <ArrowRight size={17} />
            </>
          )}
        </button>
      </form>
      <div className="auth-links">
        {mode === "login" ? (
          <>
            <button
              className="text-button"
              disabled={busy}
              onClick={() => change("forgot")}
            >
              Forgot password?
            </button>
            <button
              className="text-button"
              disabled={busy}
              onClick={() => change("register")}
            >
              Create an account
            </button>
            <button
              className="text-button muted"
              disabled={busy}
              onClick={resend}
            >
              Resend verification email
            </button>
          </>
        ) : (
          <button
            className="text-button"
            disabled={busy}
            onClick={() => change("login")}
          >
            Back to sign in
          </button>
        )}
      </div>
    </Dialog>
  );
}
