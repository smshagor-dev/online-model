export default function TypingIndicator({
  writing = false,
}: {
  writing?: boolean;
}) {
  return (
    <span
      className={writing ? "response-cursor" : "typing-indicator"}
      role="status"
      aria-label={writing ? "Writing response" : "Thinking"}
    >
      {!writing && (
        <span className="typing-dots" aria-hidden="true">
          <i />
          <i />
          <i />
        </span>
      )}
      <span className="sr-only">
        {writing ? "Writing response" : "Thinking"}
      </span>
    </span>
  );
}
