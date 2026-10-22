import TypingIndicator from "./typing-indicator";

const imageStages: Record<string, string> = {
  queued: "Waiting for an image worker",
  preparing: "Preparing your image",
  generating: "Generating your image",
  finalizing: "Finalizing your image",
  complete: "Image ready",
};

export default function CreationProgress({
  kind,
  preparing = false,
  progress = null,
  stage = null,
}: {
  kind: string;
  preparing?: boolean;
  progress?: number | null;
  stage?: string | null;
}) {
  const video = kind === "video";
  const image = kind === "image";
  const name = kind === "document_create"
    ? "document"
    : kind === "speech"
      ? "audio"
      : kind;
  // This is server-reported workflow progress, not a guessed model-rendering
  // percentage. Providers with a single synchronous response cannot report steps.
  const percent = image && typeof progress === "number" && Number.isFinite(progress)
    ? Math.max(0, Math.min(100, Math.round(progress)))
    : null;
  const label = image && stage && imageStages[stage]
    ? imageStages[stage]
    : preparing ? `Preparing your ${name}` : `Creating your ${name}`;

  return (
    <section className={`creation-progress ${video ? "creation-video" : ""}`}
      aria-label={`Creating ${name}`}>
      <div className="creation-canvas" aria-hidden="true">
        <div className="creation-glow" />
        <div className="creation-grid" />
        <span className="creation-glyph">
          {video ? "▷" : image ? "✧" : "✳"}
        </span>
        {percent !== null && (
          <span className="creation-canvas-percent">{percent}%</span>
        )}
        {video && (
          <div className="creation-film">
            <i /><i /><i /><i /><i /><i />
          </div>
        )}
      </div>
      <div className="creation-caption">
        <div className="creation-status-line">
          <TypingIndicator label={label} />
          {percent !== null && <strong className="creation-percent">{percent}%</strong>}
        </div>
        {percent !== null && (
          <div className="creation-meter" role="progressbar" aria-label="Image generation workflow progress"
            aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}
            aria-valuetext={`${label}: ${percent}%`}>
            <span style={{ width: `${percent}%` }} />
          </div>
        )}
        <span>
          {percent !== null
            ? "Progress reflects completed generation stages, not internal model steps."
            : "You can stay here while it takes shape."}
        </span>
      </div>
    </section>
  );
}
