"use client";
export default function ErrorPage({ reset }: { reset: () => void }) {
  return (
    <main className="reset-page">
      <div className="reset-card">
        <h1>Let’s try that again</h1>
        <p className="dialog-description">
          Something interrupted your workspace. Your saved conversations are
          still in your account.
        </p>
        <button className="primary-button" onClick={reset}>
          Reload workspace
        </button>
      </div>
    </main>
  );
}
