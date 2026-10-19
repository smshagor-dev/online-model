"use client";
// A small, safe lexical highlighter for previews; no HTML injection or execution.
export default function CodePreview({ content }: { content: string }) {
  const parts = content.split(
    /("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\/\/[^\n]*|#[^\n]*|\b(?:const|let|var|function|return|if|else|for|while|class|import|from|export|async|await|def|try|except|catch|public|private|use|namespace|true|false|null|None|True|False)\b|\b\d+(?:\.\d+)?\b)/g,
  );
  return (
    <pre className="code-preview" tabIndex={0}>
      <code>
        {parts.map((p, i) => (
          <span
            key={i}
            className={
              /^['"]/.test(p)
                ? "syntax-string"
                : /^(\/\/|#)/.test(p)
                  ? "syntax-comment"
                  : /^\d/.test(p)
                    ? "syntax-number"
                    : /^[a-zA-Z]+$/.test(p)
                      ? "syntax-keyword"
                      : undefined
            }
          >
            {p}
          </span>
        ))}
      </code>
    </pre>
  );
}
