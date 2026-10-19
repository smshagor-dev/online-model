"use client";
import { useEffect, useState } from "react";
import {
  Check,
  Copy,
  Download,
  KeyRound,
  LoaderCircle,
  Monitor,
  ShieldCheck,
  UserRound,
} from "lucide-react";
import Dialog from "./dialog";
import { api, type User } from "@/lib/api";
type Device = { id: number; name: string; last_used_at: string | null };
export default function AccountDialog({
  user,
  onClose,
  onUpdate,
  onSignedOut,
}: {
  user: User;
  onClose: () => void;
  onUpdate: (user: User) => void;
  onSignedOut: () => void;
}) {
  const [tab, setTab] = useState("profile");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [devices, setDevices] = useState<Device[]>([]);
  const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(
    null,
  );
  const [codes, setCodes] = useState<string[]>([]);
  const [copied, setCopied] = useState(false);
  useEffect(() => {
    if (tab === "devices") {
      const controller = new AbortController();
      api<Device[]>("tokens", "GET", undefined, controller.signal)
        .then(setDevices)
        .catch((e) => {
          if (e.name !== "AbortError") setError(e.message);
        });
      return () => controller.abort();
    }
  }, [tab]);
  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await action();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  function values(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    return Object.fromEntries(new FormData(event.currentTarget));
  }
  async function refresh() {
    onUpdate(await api<User>("me"));
  }
  return (
    <Dialog
      title="Settings"
      onClose={() => {
        if (!busy) onClose();
      }}
      wide
    >
      <div className="settings-layout">
        <nav className="settings-nav" aria-label="Account settings">
          {[
            { id: "profile", title: "General", icon: UserRound },
            { id: "security", title: "Security", icon: ShieldCheck },
            { id: "devices", title: "Devices", icon: Monitor },
          ].map((item) => (
            <button
              key={item.id}
              className={tab === item.id ? "selected" : ""}
              disabled={busy}
              onClick={() => {
                setTab(item.id);
                setError("");
                setNotice("");
              }}
            >
              <item.icon size={17} />
              {item.title}
            </button>
          ))}
        </nav>
        <div className="settings-content">
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
          {tab === "profile" && (
            <>
              <h3>Your profile</h3>
              <p className="muted">{user.email}</p>
              <form
                className="form-stack"
                onSubmit={(e) => {
                  const data = values(e);
                  void run(async () => {
                    onUpdate(await api<User>("me", "PATCH", data));
                    setNotice("Profile updated.");
                  });
                }}
              >
                <label>
                  Name
                  <input
                    name="name"
                    defaultValue={user.name}
                    required
                    maxLength={120}
                  />
                </label>
                <button className="primary-button" disabled={busy}>
                  Save changes
                </button>
              </form>
              <hr />
              <h3>Change password</h3>
              <form
                className="form-stack"
                onSubmit={(e) => {
                  const data = values(e);
                  void run(async () => {
                    await api("me/password", "PUT", data);
                    onSignedOut();
                  });
                }}
              >
                <label>
                  Current password
                  <input
                    name="current_password"
                    type="password"
                    autoComplete="current-password"
                    required
                  />
                </label>
                <label>
                  New password
                  <input
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    required
                    minLength={12}
                  />
                </label>
                <label>
                  Confirm new password
                  <input
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    minLength={12}
                  />
                </label>
                <small className="muted">
                  12+ characters with uppercase, lowercase and a number. You'll
                  be signed out on all devices.
                </small>
                <button className="secondary-button" disabled={busy}>
                  Update password
                </button>
              </form>
            </>
          )}
          {tab === "security" && (
            <>
              <h3>
                <ShieldCheck size={19} /> Two-factor authentication
              </h3>
              <p className="muted">
                {user.two_factor_confirmed_at
                  ? "Your account has an extra layer of protection."
                  : "Protect your account with an authenticator app."}
              </p>
              {!user.two_factor_confirmed_at && !setup && (
                <form
                  className="form-stack"
                  onSubmit={(e) => {
                    const data = values(e);
                    void run(async () => {
                      const result = await api<{
                        secret: string;
                        otpauth_url: string;
                      }>("2fa/setup", "POST", data);
                      setSetup({
                        secret: result.secret,
                        qr: await (
                          await import("qrcode")
                        ).default.toDataURL(result.otpauth_url, {
                          width: 200,
                          margin: 2,
                        }),
                      });
                    });
                  }}
                >
                  <label>
                    Confirm your password
                    <input
                      name="password"
                      type="password"
                      required
                      autoComplete="current-password"
                    />
                  </label>
                  <button className="primary-button" disabled={busy}>
                    <KeyRound size={16} /> Set up authenticator
                  </button>
                </form>
              )}
              {setup && !user.two_factor_confirmed_at && (
                <div className="setup-box">
                  <p>Scan this QR code in your authenticator app.</p>
                  {/* Local QR generation keeps the secret on this device. */}
                  <img
                    src={setup.qr}
                    alt="Authenticator setup QR code"
                    width={180}
                    height={180}
                  />
                  <label>
                    Or enter this setup key
                    <code className="secret">{setup.secret}</code>
                  </label>
                  <form
                    className="form-stack"
                    onSubmit={(e) => {
                      const data = values(e);
                      void run(async () => {
                        const result = await api<{ recovery_codes: string[] }>(
                          "2fa/confirm",
                          "POST",
                          data,
                        );
                        setCodes(result.recovery_codes);
                        setSetup(null);
                        await refresh();
                      });
                    }}
                  >
                    <label>
                      Authenticator code
                      <input
                        name="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        required
                        pattern="[0-9]{6}"
                      />
                    </label>
                    <button className="primary-button" disabled={busy}>
                      Enable two-factor authentication
                    </button>
                  </form>
                </div>
              )}
              {codes.length > 0 && (
                <div className="recovery-box">
                  <h4>Save your recovery codes</h4>
                  <p className="muted">
                    Shown once. Keep them somewhere safe. Each code works once.
                  </p>
                  <div className="recovery-grid">
                    {codes.map((code) => (
                      <code key={code}>{code}</code>
                    ))}
                  </div>
                  <div className="button-row">
                    <button
                      className="secondary-button"
                      onClick={async () => {
                        try {
                          await navigator.clipboard.writeText(codes.join("\n"));
                          setCopied(true);
                        } catch {
                          setError(
                            "Clipboard unavailable. Download your codes instead.",
                          );
                        }
                      }}
                    >
                      {copied ? <Check size={16} /> : <Copy size={16} />}Copy
                    </button>
                    <button
                      className="secondary-button"
                      onClick={() => {
                        const url = URL.createObjectURL(
                          new Blob([codes.join("\n")], { type: "text/plain" }),
                        );
                        const link = document.createElement("a");
                        link.href = url;
                        link.download = "online-model-recovery-codes.txt";
                        link.click();
                        setTimeout(() => URL.revokeObjectURL(url), 1000);
                      }}
                    >
                      <Download size={16} />
                      Download
                    </button>
                  </div>
                </div>
              )}
              {user.two_factor_confirmed_at && (
                <>
                  <form
                    className="form-stack"
                    onSubmit={(e) => {
                      const data = values(e);
                      void run(async () => {
                        const result = await api<{ recovery_codes: string[] }>(
                          "2fa/recovery-codes",
                          "POST",
                          data,
                        );
                        setCodes(result.recovery_codes);
                      });
                    }}
                  >
                    <label>
                      Password
                      <input
                        name="password"
                        type="password"
                        required
                        autoComplete="current-password"
                      />
                    </label>
                    <label>
                      Authenticator or recovery code
                      <input
                        name="code"
                        autoComplete="one-time-code"
                        required
                        maxLength={64}
                      />
                    </label>
                    <button className="secondary-button" disabled={busy}>
                      Replace recovery codes
                    </button>
                  </form>
                  <hr />
                  <form
                    className="form-stack"
                    onSubmit={(e) => {
                      const data = values(e);
                      void run(async () => {
                        await api("2fa", "DELETE", data);
                        setCodes([]);
                        await refresh();
                        setNotice("Two-factor authentication disabled.");
                      });
                    }}
                  >
                    <label>
                      Password
                      <input
                        name="password"
                        type="password"
                        required
                        autoComplete="current-password"
                      />
                    </label>
                    <label>
                      Authenticator or recovery code
                      <input
                        name="code"
                        autoComplete="one-time-code"
                        required
                      />
                    </label>
                    <button className="danger-button" disabled={busy}>
                      Disable two-factor authentication
                    </button>
                  </form>
                </>
              )}
            </>
          )}
          {tab === "devices" && (
            <>
              <h3>Signed-in devices</h3>
              <p className="muted">
                Revoke a device to sign it out. Revoking this browser will
                require signing in again.
              </p>
              {devices.length === 0 && (
                <p className="muted">No devices to display.</p>
              )}
              {devices.map((device) => (
                <div className="device-row" key={device.id}>
                  <Monitor size={19} />
                  <div>
                    <strong>{device.name}</strong>
                    <small>
                      {device.last_used_at
                        ? new Date(device.last_used_at).toLocaleDateString()
                        : "Not used yet"}
                    </small>
                  </div>
                  <button
                    className="text-button danger-text"
                    disabled={busy}
                    onClick={() =>
                      void run(async () => {
                        await api(`tokens/${device.id}`, "DELETE");
                        setDevices(
                          devices.filter((item) => item.id !== device.id),
                        );
                        try {
                          await api("me");
                        } catch {
                          onSignedOut();
                        }
                      })
                    }
                  >
                    Revoke
                  </button>
                </div>
              ))}
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() =>
                  void run(async () => {
                    await api("logout-all", "POST");
                    onSignedOut();
                  })
                }
              >
                Sign out all devices
              </button>
            </>
          )}
          {busy && (
            <p className="muted loading-inline" role="status">
              <LoaderCircle size={16} className="spin" />
              Saving…
            </p>
          )}
        </div>
      </div>
    </Dialog>
  );
}
