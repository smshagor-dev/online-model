import test from "node:test";
import assert from "node:assert/strict";
import { routePrompt } from "../src/lib/prompt-router.ts";
import { nvidiaChat } from "../src/lib/nvidia-chat.ts";

const cfg = {
  apiKey: "private-test-key",
  defaultModel: "nvidia/nemotron-3.5-lightning-30b-a3b",
  fastModel: "nvidia/nemotron-3.5-lightning-30b-a3b",
  reasoningModel: "nvidia/nemotron-3-ultra-550b-a55b",
  visionModel: "nvidia/nemotron-3.5-super-vl-120b-a12b",
  classifierModel: "nvidia/nemotron-3.5-lightning-30b-a3b",
};
const classify = (s, options = {}) => routePrompt(s, cfg, {enableClassifier: false, ...options});

test("clear prompts dispatch existing hosted generation capabilities", async () => {
  assert.equal((await classify("Create a logo for my site")).kind, "image");
  assert.equal((await classify("আমার জন্য একটি ছবি বানাও")).kind, "image");
  assert.equal((await classify("Make a short video of a mountain")).kind, "video");
  assert.equal((await classify("Generate an audio narration of a poem")).kind, "speech");
  assert.deepEqual(
    (({kind, format}) => ({kind, format}))(await classify("Create a PDF report about my project")),
    {kind: "document_create", format: "pdf"},
  );
  assert.equal((await classify("Create a DOCX document about my project")).format, "docx");
});
test("questions and content about media are not secretly converted into media tasks", async () => {
  for (const prompt of [
    "How do I generate an image using NVIDIA?",
    "Design an API for image generation",
    "Write a video script about technology",
    "Tell me how to create a PDF using Laravel",
    "Write a speech for a conference",
    "Create a video generation system",
  ]) assert.equal((await classify(prompt)).kind, "chat", prompt);
  assert.equal((await classify("Generate a photo", {hasAttachments: true})).kind, "chat");
  assert.equal((await classify("Create a logo", {deepResearch: true})).kind, "chat");
});
test("code, research and visual text prompts select appropriate hosted models", async () => {
  assert.equal((await classify("Debug my Laravel authentication controller")).model, cfg.reasoningModel);
  assert.equal((await classify("Research paper about secure UAV mesh networks")).model, cfg.reasoningModel);
  assert.equal((await classify("Analyze the image in this screenshot")).model, cfg.visionModel);
  assert.equal((await classify("Hello")).model, cfg.fastModel);
  assert.equal((await classify("Hello", {deepResearch:true})).model, cfg.reasoningModel);
});
test("semantic classifier is constrained to known model tiers and task types", async () => {
  const calls = [];
  const fetcher = async (url, options) => {
    calls.push({url, options});
    return Response.json({choices:[{message:{content: JSON.stringify({kind:"chat",tier:"reasoning",url:"http://internal"})}}]});
  };
  const result = await routePrompt("Please review my recent architecture decisions in detail", cfg, {fetcher});
  assert.equal(result.kind, "chat");
  assert.equal(result.model, cfg.reasoningModel);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, "https://integrate.api.nvidia.com/v1/chat/completions");
  assert.equal(calls[0].options.headers.Authorization, "Bearer private-test-key");
  assert.ok(!JSON.stringify(result).includes("http://internal"));
});
test("classifier errors fall back to chat without leaking provider failures", async () => {
  const result = await routePrompt("Please compose a detailed comparison of these approaches", cfg, {
    fetcher: async () => Response.json({error: "bad credentials"}, {status: 403}),
  });
  assert.equal(result.kind, "chat");
});
test("automatic DOCX generation reuses Laravel media begin/complete and emits preview event", async () => {
  const calls = [];
  const fetcher = async (url, init) => {
    calls.push({url, init});
    if (url.endsWith("/media/hosted/task/begin"))
      return Response.json({id: 44, status:"running"}, {status:202});
    if (url.endsWith("/media/44/hosted/task/complete"))
      return Response.json({id:44, status:"complete"});
    if (url.endsWith("/chat/completions"))
      return Response.json({choices:[{message:{content:"Example report\n\nProject summary."}}]});
    throw Error("Unexpected provider URL: " + url);
  };
  const r = await nvidiaChat(JSON.stringify({model_id:-1, message:"Create a DOCX document about our project"}),
    "user-token", "https://backend/api/v1", new AbortController().signal,
    {fetcher, config:{apiKey:cfg.apiKey, bridgeKey:"k".repeat(32), model:cfg.fastModel}});
  assert.equal(r.status, 200);
  assert.match(r.headers.get("content-type"), /text\/event-stream/);
  const stream = await r.text();
  assert.match(stream, /event: media/);
  assert.match(stream, /"id":44/);
  assert.match(stream, /"status":"complete"/);
  assert.ok(!stream.includes("private-test-key"));
  assert.equal(calls.some(c => c.url.includes("/chat/frontend/begin")), false);
  assert.equal(calls.filter(c => c.url.includes("/media/")).length, 2);
  assert.equal(JSON.parse(calls[1].init.body).model, cfg.fastModel);
});

test("provider rate limiting triggers one pre-stream fallback and saves the selected model", async () => {
  const requests = [];
  let saved;
  const fetcher = async (url, init) => {
    if (url.endsWith("/begin")) return Response.json({
      ticket: "d".repeat(64), messages: [
        {role: "system", content: "context"}, {role: "user", content: "Debug my Laravel controller"},
      ], conversation_id: 12, user_message_id: 14, message_id: 15, sources: [],
    });
    if (url.endsWith("/checkpoint")) {
      saved = JSON.parse(init.body).state;
      return Response.json({stored: true});
    }
    if (url.endsWith("/finish")) return Response.json({stored:true});
    if (url.endsWith("/status")) return Response.json({cancelled:false});
    const body = JSON.parse(init.body);
    requests.push(body.model);
    if (requests.length === 1) return Response.json({error:"busy"}, {status:429});
    return new Response(
      'data: {"choices":[{"delta":{"content":"Fixed"}}]}\n\n' +
      'data: [DONE]\n\n',
      {headers:{"Content-Type":"text/event-stream"}},
    );
  };
  const response = await nvidiaChat(
    JSON.stringify({message:"Debug my Laravel controller",model_id:-1}),
    "user", "https://backend/api/v1", new AbortController().signal,
    {fetcher, config:{apiKey:cfg.apiKey,bridgeKey:"x".repeat(32),model:cfg.fastModel}},
  );
  const text = await response.text();
  assert.match(text, /Fixed/);
  assert.match(text, /"saved":true/);
  assert.deepEqual(requests, [cfg.reasoningModel,cfg.fastModel]);
  assert.equal(saved.modelId, cfg.fastModel);
});
