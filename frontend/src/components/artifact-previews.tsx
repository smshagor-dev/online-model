"use client";
import { useState } from "react";
export type Artifact = {
  name: string;
  type: "image" | "table" | "text";
  content?: string;
  rows?: string[][];
};
export default function ArtifactPreviews({ items }: { items: Artifact[] }) {
  return (
    <section aria-label="Generated file previews" className="artifact-previews">
      {items.map((a, i) => (
        <details key={i} open={i === 0}>
          <summary>{a.name}</summary>
          {a.type === "image" && (
            <img
              className="media-preview"
              src={`data:image/png;base64,${a.content}`}
              alt={a.name}
            />
          )}{" "}
          {a.type === "text" && (
            <pre className="file-text-preview">{a.content}</pre>
          )}
          {a.type === "table" && <TablePreview rows={a.rows || []} />}
        </details>
      ))}
      <small className="muted">
        Previews are bounded samples. Download the ZIP for complete files.
      </small>
    </section>
  );
}
function TablePreview({ rows }: { rows: string[][] }) {
  const [column, setColumn] = useState(1);
  const [sort, setSort] = useState<number | null>(null);
  const header = rows[0] || [];
  const body = rows.slice(1);
  const data =
    sort === null
      ? body
      : [...body].sort((a, b) =>
          String(a[sort] || "").localeCompare(
            String(b[sort] || ""),
            undefined,
            { numeric: true },
          ),
        );
  const points = body
    .map((r, i) => ({ x: i, y: r[column]?.trim() ? Number(r[column]) : NaN }))
    .filter((p) => Number.isFinite(p.y));
  const low = Math.min(...points.map((p) => p.y));
  const high = Math.max(...points.map((p) => p.y));
  const magnitude = Math.max(1, ...points.map((p) => Math.abs(p.y)));
  const y = (value: number) =>
    190 -
    ((value / magnitude - low / magnitude) /
      (high / magnitude - low / magnitude || 1)) *
      155;
  return (
    <>
      <small className="muted">
        First row is the header. Preview shows up to 100 data rows.
      </small>
      <div className="artifact-table">
        <table>
          <thead>
            <tr>
              {header.map((h, i) => (
                <th key={i}>
                  <button
                    onClick={() => setSort(sort === i ? null : i)}
                    aria-label={`Sort by ${h}`}
                  >
                    {h}
                    {sort === i ? " ↑" : ""}
                  </button>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.map((r, i) => (
              <tr key={i}>
                {r.map((c, j) => (
                  <td key={j}>{c}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <label>
        Plot numeric column
        <select
          value={column}
          onChange={(e) => setColumn(Number(e.target.value))}
        >
          {header.map((h, i) => (
            <option key={i} value={i}>
              {h || `Column ${i + 1}`}
            </option>
          ))}
        </select>
      </label>
      {points.length > 1 ? (
        <svg
          viewBox="0 0 600 220"
          role="img"
          aria-label={`Line chart of ${header[column] || "selected column"} by row`}
          className="artifact-chart"
        >
          <line x1="35" y1="190" x2="580" y2="190" stroke="currentColor" />
          <polyline
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            points={points
              .map(
                (p) =>
                  `${35 + (p.x / Math.max(body.length - 1, 1)) * 545},${y(p.y)}`,
              )
              .join(" ")}
          />
          {points.map((p) => (
            <circle
              key={p.x}
              cx={35 + (p.x / Math.max(body.length - 1, 1)) * 545}
              cy={y(p.y)}
              r="4"
              fill="currentColor"
            >
              <title>
                Row {p.x + 1}: {p.y}
              </title>
            </circle>
          ))}
          <text x="35" y="215" fill="currentColor">
            Rows 1–{body.length} · range {low}–{high}
          </text>
        </svg>
      ) : (
        <p className="muted">
          Choose a column with at least two numeric values to plot.
        </p>
      )}
    </>
  );
}
