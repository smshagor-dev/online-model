import test from "node:test";
import assert from "node:assert/strict";
import { generateHostedImage } from "../src/lib/hosted-image.ts";

const config = { apiKey: "private-image-key", bridgeKey: "s".repeat(32) };
const png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=";
const jpeg = "/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/2wBDARESEhgVGC8aGi9jQjhCY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2P/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAT/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAABv/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/ALABcjf/2Q==";

function backendScenario({ providerStatus = 200, finishReason = "SUCCESS", finishStatus = 200, limited = false, imageData = png } = {}) {
  const calls = [];
  const fetcher = async (url, init) => {
    const body = JSON.parse(init.body);
    calls.push({ url, body, headers: init.headers });
    if (url.endsWith("/media/hosted/begin")) {
      return limited
        ? Response.json({ message: "No quota" }, { status: 429 })
        : Response.json({ id: 7, status: "running" }, { status: 202 });
    }
    if (url.endsWith("/media/7/hosted/finish"))
      return Response.json(
        { id: 7, status: "complete", output_mime: "image/png" },
        { status: finishStatus },
      );
    if (url.endsWith("/media/7/hosted/fail"))
      return new Response(null, { status: 204 });
    assert.equal(url, "https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.1-schnell");
    assert.equal(init.headers.Authorization, "Bearer private-image-key");
    assert.equal(body.mode, "base");
    assert.equal(body.steps, 4);
    assert.equal(body.cfg_scale, 0);
    assert.equal(body.samples, 1);
    assert.equal(body.prompt, "Sunrise over a forest");
    return Response.json(
      { artifacts: [{ base64: imageData, finishReason }] },
      { status: providerStatus },
    );
  };
  return { fetcher, calls };
}
async function generate(f, options = {}) {
  return generateHostedImage(
    JSON.stringify({ prompt: "Sunrise over a forest" }),
    "user-token",
    "https://private-backend/api/v1",
    new AbortController().signal,
    { fetcher: f.fetcher, config, ...options },
  );
}

test("authenticated image bridge generates and saves a private image", async () => {
  const f = backendScenario();
  const response = await generate(f);
  assert.equal(response.status, 201);
  const saved = await response.json();
  assert.equal(saved.id, 7);
  assert.equal(saved.status, "complete");
  assert.equal(f.calls.length, 3);
  assert.equal(f.calls[0].headers.Authorization, "Bearer user-token");
  assert.equal(f.calls[0].headers["X-Chat-Bridge-Key"], config.bridgeKey);
  assert.equal(f.calls[2].body.mime, "image/png");
  assert.equal(f.calls[2].body.data, png);
  assert.doesNotMatch(JSON.stringify(saved), /NVIDIA|flux|private-image-key/i);
});

test("accepts the JPEG format returned by hosted generation", async () => {
  const f = backendScenario({ imageData: jpeg });
  const response = await generate(f);
  assert.equal(response.status, 201);
  assert.equal(f.calls[2].body.mime, "image/jpeg");
  assert.equal(f.calls[2].body.data, jpeg);
});

test("rejects non-image content even when it is valid base64", async () => {
  const f = backendScenario({ imageData: Buffer.from("not an image").toString("base64") });
  const response = await generate(f);
  assert.equal(response.status, 502);
  assert.equal(f.calls.at(-1).url.endsWith("/fail"), true);
});

test("does not spend a provider call when image quota is exhausted", async () => {
  const f = backendScenario({ limited: true });
  const response = await generate(f);
  assert.equal(response.status, 429);
  assert.equal(f.calls.length, 1);
});

test("filtered provider output is rejected and task failure is persisted", async () => {
  const f = backendScenario({ finishReason: "CONTENT_FILTERED" });
  const response = await generate(f);
  assert.equal(response.status, 422);
  assert.deepEqual(f.calls.map((call) => call.url.split("/").slice(-1)[0]), [
    "begin",
    "flux.1-schnell",
    "fail",
  ]);
});

test("provider failure is not reported as an image success", async () => {
  const f = backendScenario({ providerStatus: 429 });
  const response = await generate(f);
  assert.equal(response.status, 429);
  assert.equal(f.calls.length, 3);
  assert.equal(f.calls[2].url.endsWith("/fail"), true);
});

test("image is not reported complete when backend persistence fails", async () => {
  const f = backendScenario({ finishStatus: 503 });
  const response = await generate(f);
  assert.equal(response.status, 503);
  assert.equal(f.calls.length, 4);
  assert.equal(f.calls[3].url.endsWith("/fail"), true);
});

test("missing credentials and invalid prompts do not call the backend", async () => {
  const f = backendScenario();
  const missing = await generate(f, { config: { apiKey: "", bridgeKey: "s".repeat(32) } });
  assert.equal(missing.status, 503);
  const invalid = await generateHostedImage(
    JSON.stringify({ prompt: "  " }),
    "user-token",
    "https://private-backend/api/v1",
    new AbortController().signal,
    { fetcher: f.fetcher, config },
  );
  assert.equal(invalid.status, 422);
  assert.equal(f.calls.length, 0);
});
