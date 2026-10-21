"use client";
import { isValidElement, useEffect } from "react";
import CodeBlock from "./code-block";
import Markdown from "react-markdown";
import remarkGfm from "remark-gfm";
export default function ResponseMarkdown({
  content,
  onRendered,
}: {
  content: string;
  onRendered?: () => void;
}) {
  useEffect(() => {
    onRendered?.();
  }, [content, onRendered]);
  return (
    <div className="markdown">
      <Markdown
        remarkPlugins={[remarkGfm]}
        components={{
          a: ({ children, ...props }) => (
            <a {...props} target="_blank" rel="noopener noreferrer">
              {children}
            </a>
          ),
          pre: ({ children }) => {
            if (isValidElement<{children?: string; className?: string}>(children)) {
              return <CodeBlock content={String(children.props.children ?? "").replace(/\n$/, "")} language={children.props.className?.replace("language-", "") || "code"} />;
            }
            return <pre tabIndex={0}>{children}</pre>;
          },
        }}
      >
        {content}
      </Markdown>
    </div>
  );
}
