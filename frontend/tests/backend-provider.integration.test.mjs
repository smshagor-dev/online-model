import test from "node:test";
import assert from "node:assert/strict";
import { backendProviderFetcher } from "../src/lib/backend-provider.ts";

const backend = "https://cloud.openmindai.org/api/v1";
const token = "signed-user-token";
const secret = "s".repeat(32);
const chatUrl = "https://integrate.api.nvidia.com/v1/chat/completions";
const imageUrl = "https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.1-schnell";
const asrUrl = "https://1598d209-5e27-4d3c-8079-4751568b1081.invocation.api.nvcf.nvidia.com/v1/audio/transcriptions";
const ttsUrl = "https://877104f7-e885-42b9-8de8-f6e4c6303969.invocation.api.nvcf.nvidia.com/v1/audio/synthesize";
const videoUrl = "https://ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano";

function fixture() {
  const calls = [];
  const transport = async (url, init) => {
    calls.push({ url: String(url), init });
    return Response.json({ ok: true });
  };
  return { calls, fetcher: backendProviderFetcher(backend, token, secret, transport) };
}

test("all five inference services are routed only to the Laravel cloud backend", async () => {
  for (const [url, service, payload] of [
    [chatUrl, "chat", { model: "nvidia/nemotron-3.5-lightning-30b-a3b", messages: [{ role: "user", content: "Hi" }], stream: true }],
    [imageUrl, "image", { prompt: "Cloud" }],
    [videoUrl, "video", { prompt: "Moon" }],
  ]) {
    const f = fixture();
    const response = await f.fetcher(url, { method: "POST", headers: { Authorization: "Bearer SECRET-NVIDIA-KEY" }, body: JSON.stringify(payload) });
    assert.equal(response.status, 200);
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].url, backend + "/provider/invoke");
    const sent = JSON.parse(f.calls[0].init.body);
    assert.equal(sent.service, service);
    assert.deepEqual(sent.payload, payload);
    assert.equal(f.calls[0].init.headers.Authorization, "Bearer " + token);
    assert.equal(f.calls[0].init.headers["X-Chat-Bridge-Key"], secret);
    assert.doesNotMatch(JSON.stringify(f.calls[0]), /SECRET-NVIDIA-KEY/);
    if (service === "chat") assert.equal(f.calls[0].init.headers.Accept, "text/event-stream");
  }
});

test("transcription converts microphone upload to backend-only base64 transport", async () => {
  const f = fixture();
  const wav = new Uint8Array(44);
  const form = new FormData();
  form.set("file", new Blob([wav], { type: "audio/wav" }), "audio.wav");
  await f.fetcher(asrUrl, { method: "POST", body: form });
  assert.equal(f.calls.length, 1);
  const payload = JSON.parse(f.calls[0].init.body);
  assert.equal(payload.service, "transcription");
  assert.deepEqual(Buffer.from(payload.payload.data, "base64"), Buffer.from(wav));
});

test("generated speech language is forwarded but arbitrary browser voice is not", async () => {
  const f = fixture();
  const form = new FormData();
  form.set("text", "Hola");
  form.set("language", "es-US");
  form.set("voice", "unauthorized-provider-voice");
  await f.fetcher(ttsUrl, { method: "POST", body: form });
  assert.equal(f.calls.length, 1);
  assert.deepEqual(JSON.parse(f.calls[0].init.body), {
    service: "speech", payload: { text: "Hola", language: "es-US" },
  });
});

test("unknown NVIDIA URLs fail closed without making an outbound request", async () => {
  const f = fixture();
  await assert.rejects(f.fetcher("https://ai.api.nvidia.com/v1/attacker/unknown", { method: "POST", body: "{}" }), /does not support/);
  assert.equal(f.calls.length, 0);
});

test("non-provider Laravel calls still use the original authenticated fetcher", async () => {
  const f = fixture();
  await f.fetcher(backend + "/me", { headers: { Authorization: "Bearer " + token } });
  assert.equal(f.calls.length, 1);
  assert.equal(f.calls[0].url, backend + "/me");
});

test("missing bridge secret rejects provider calls before contacting backend", async () => {
  const calls = [];
  const fetcher = backendProviderFetcher(backend, token, "", async () => {
    calls.push("called");
    return Response.json({});
  });
  await assert.rejects(fetcher(imageUrl, { body: JSON.stringify({ prompt: "test" }) }), /not configured/);
  assert.equal(calls.length, 0);
});
