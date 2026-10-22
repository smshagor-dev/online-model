import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = file => readFileSync(new URL("../src/" + file, import.meta.url), "utf8");

test("image creation shows server-supplied percent with accessible progressbar", () => {
  const progress = read("components/creation-progress.tsx");
  assert.match(progress, /progress\?: number \| null/);
  assert.match(progress, /stage\?: string \| null/);
  assert.match(progress, /kind === "image"/);
  assert.match(progress, /aria-valuenow=\{percent\}/);
  assert.match(progress, /aria-label="Image generation workflow progress"/);
  assert.match(progress, /imageStages\[stage\]/);
  assert.doesNotMatch(progress, /setInterval|Date\.now\(|Math\.random\(/);
});

test("chat preview polls the server's persisted generation progress", () => {
  const inline = read("components/inline-media.tsx");
  assert.match(inline, /progress_percent\?: number \| null/);
  assert.match(inline, /progress_stage\?: string \| null/);
  assert.match(inline, /progress=\{task\?\.progress_percent\}/);
  assert.match(inline, /stage=\{task\?\.progress_stage\}/);
  assert.match(inline, /setTimeout\(load, 3000\)/);
});

test("media studio renders progress for active images", () => {
  const studio = read("components/media-dialog.tsx");
  assert.match(studio, /import CreationProgress from "\.\/creation-progress"/);
  assert.match(studio, /\["queued", "running"\]\.includes\(task.status\)/);
  assert.match(studio, /progress=\{task.progress_percent\}/);
  assert.match(studio, /stage=\{task.progress_stage\}/);
});

test("image progress bar respects reduced-motion preference", () => {
  const css = read("app/motion.css");
  assert.match(css, /\.creation-meter/);
  assert.match(css, /\.creation-canvas-percent/);
  assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
});
