"use client";
import { isValidElement, useEffect } from "react";
import { useAnimatedText } from "@/hooks/use-animated-text";
import TypingIndicator from "./typing-indicator";
import CodeBlock from "./code-block";
import Markdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";
// Stable renderers preserve code blocks while streamed text grows.
const markdownComponents: Components = {
          a: ({ children, ...props }) => (
            <a {...props} target="_blank" rel="noopener noreferrer">
              {children}
            </a>
          ),
          pre: ({ children }) => {
            if (
              isValidElement<{ children?: string; className?: string }>(
                children,
              )
            ) {
              return (
                <CodeBlock
                  content={String(children.props.children ?? "").replace(
                    /\n$/,
                    "",
                  )}
                  language={
                    children.props.className?.replace("language-", "") || "code"
                  }
                />
              );
            }
            return <pre tabIndex={0}>{children}</pre>;
          },
        };

export default function ResponseMarkdown({
  content,
  onRendered,
  animate = false,
  streaming = false,
  reducedMotion = false,
}: {
  content: string;
  onRendered?: () => void;
  animate?: boolean;
  streaming?: boolean;
  reducedMotion?: boolean;
}) {
  const { text, pending } = useAnimatedText(content, animate, reducedMotion);
  const typing = streaming || pending;
  useEffect(() => {
    onRendered?.();
  }, [text, onRendered]);
  return (
    <div
      className={`markdown${typing ? " response-streaming" : ""}`}
      aria-busy={typing}
    >
      <Markdown
        remarkPlugins={[remarkGfm]}
        components={markdownComponents}
      >
        {text}
      </Markdown>
      {typing && <TypingIndicator writing={Boolean(text)} />}
    </div>
  );
}
