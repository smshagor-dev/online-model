"use client";
import { useEffect } from "react";
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
          pre: ({ children }) => <pre tabIndex={0}>{children}</pre>,
        }}
      >
        {content}
      </Markdown>
    </div>
  );
}
