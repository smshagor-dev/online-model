import TypingIndicator from "./typing-indicator";

export default function CreationProgress({
  kind,
  preparing = false,
}: {
  kind: string;
  preparing?: boolean;
}) {
  const video = kind === "video";
  const name =
    kind === "document_create"
      ? "document"
      : kind === "speech"
        ? "audio"
        : kind;
  return (
    <section
      className={`creation-progress ${video ? "creation-video" : ""}`}
      aria-label={`Creating ${name}`}
    >
      <div className="creation-canvas" aria-hidden="true">
        <div className="creation-glow" />
        <div className="creation-grid" />
        <span className="creation-glyph">
          {video ? "▷" : kind === "image" ? "✧" : "✳"}
        </span>
        {video && (
          <div className="creation-film">
            <i />
            <i />
            <i />
            <i />
            <i />
            <i />
          </div>
        )}
      </div>
      <div className="creation-caption">
        <TypingIndicator
          label={preparing ? `Preparing your ${name}` : `Creating your ${name}`}
        />
        <span>You can stay here while it takes shape.</span>
      </div>
    </section>
  );
}
