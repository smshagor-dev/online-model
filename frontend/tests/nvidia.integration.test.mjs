import test from "node:test";
import assert from "node:assert/strict";
import { nvidiaChat } from "../src/lib/nvidia-chat.ts";
const config = {
  apiKey: "secret-nvidia-key",
  bridgeKey: "k".repeat(32),
  model: "nvidia/nemotron-3-nano-30b-a3b",
};
function fixture({
  upstreamStatus = 200,
  truncated = false,
  saveFails = false,
  automaticMemory = false,
} = {}) {
  const calls = [];
  const fetcher = async (url, options) => {
    calls.push({ url, options, body: JSON.parse(options.body) });
    if (url.endsWith("/begin"))
      return Response.json({
        ticket: "a".repeat(64),
        messages: [
          { role: "system", content: "PRIVATE CONTEXT" },
          { role: "user", content: "Hi" },
        ],
        conversation_id: 1,
        user_message_id: 2,
        message_id: 3,
        sources: [],
        automatic_memory: automaticMemory,
      });
    if (url.endsWith("/finish"))
      return Response.json(
        { stored: !saveFails },
        { status: saveFails ? 503 : 200 },
      );
    if (url.endsWith("/checkpoint")) return Response.json({ stored: true });
    if (url.endsWith("/status")) return Response.json({ cancelled: false });
    assert.equal(url, "https://integrate.api.nvidia.com/v1/chat/completions");
    assert.equal(options.headers.Authorization, "Bearer secret-nvidia-key");
    if (JSON.parse(options.body).stream === false)
      return Response.json({
        choices: [
          {
            message: {
              content: JSON.stringify({
                action: "finish",
                reason: "Evidence sufficient for this fixture",
              }),
            },
          },
        ],
      });
    return new Response(
      'data: {"choices":[{"delta":{"reasoning_content":"hidden reasoning"}}]}\n\ndata: {"choices":[{"delta":{"content":"Hello"}}]}\n\n' +
        (truncated ? "" : "data: [DONE]\n\n"),
      {
        status: upstreamStatus,
        headers: { "Content-Type": "text/event-stream" },
      },
    );
  };
  return { calls, fetcher };
}
test("Next server connects directly to NVIDIA and persists only final answer to backend", async () => {
  const f = fixture();
  const r = await nvidiaChat(
    JSON.stringify({
      message: "Hi",
      model_id: -1,
      deep_research: true,
      search_time_range: "week",
    }),
    "user-token",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  const text = await r.text();
  assert.match(text, /event: delta/);
  assert.match(text, /Hello/);
  assert.match(text, /"saved":true/);
  for (const secret of [
    "secret-nvidia-key",
    "user-token",
    "PRIVATE CONTEXT",
    "hidden reasoning",
    "a".repeat(64),
  ])
    assert.ok(!text.includes(secret));
  const begin = f.calls.find((x) => x.url.endsWith("/begin"));
  assert.equal(begin.body.deep_research, true);
  assert.equal(begin.body.search_time_range, "week");
  const provider = f.calls.find(
    (x) => x.url.includes("nvidia.com") && x.body.stream,
  );
  assert.ok(provider.body.messages[0].content.includes("PRIVATE CONTEXT"));
  const done = f.calls.find((x) => x.url.endsWith("/finish"));
  assert.equal(done.body.content, "Hello");
  assert.equal(done.body.status, "complete");
  assert.equal(done.options.headers.Authorization, "Bearer user-token");
  assert.equal(done.options.headers["X-Chat-Bridge-Key"], config.bridgeKey);
  assert.equal(
    f.calls.find((x) => x.url.includes("nvidia.com") && x.body.stream).body
      .stream,
    true,
  );
});
test("truncated provider response saves partial answer with failed status", async () => {
  const f = fixture({ truncated: true });
  const r = await nvidiaChat(
    '{"message":"Hi"}',
    "u",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  const text = await r.text();
  assert.match(text, /event: error/);
  const done = f.calls.find((x) => x.url.endsWith("/finish"));
  assert.equal(done.body.content, "Hello");
  assert.equal(done.body.status, "failed");
});
test("provider throttling is recorded as failed without leaking provider body", async () => {
  const f = fixture({ upstreamStatus: 429 });
  const r = await nvidiaChat(
    '{"message":"Hi"}',
    "u",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  assert.match(await r.text(), /NVIDIA rate limit/);
  assert.equal(
    f.calls.find((x) => x.url.endsWith("/finish")).body.status,
    "failed",
  );
});
test("persistence errors retry and never report successful save", async () => {
  const f = fixture({ saveFails: true });
  const r = await nvidiaChat(
    '{"message":"Hi"}',
    "u",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  const text = await r.text();
  assert.match(text, /"saved":false/);
  assert.match(text, /could not be saved/);
  assert.equal(f.calls.filter((x) => x.url.endsWith("/finish")).length, 3);
});
test("missing key fails before any backend/provider request", async () => {
  let called = false;
  const r = await nvidiaChat(
    "{}",
    "u",
    "https://backend",
    new AbortController().signal,
    {
      config: { ...config, apiKey: "" },
      fetcher: async () => {
        called = true;
        throw Error();
      },
    },
  );
  assert.equal(r.status, 503);
  assert.equal(called, false);
});
test("cancelled browser request still finalizes backend lease", async () => {
  const f = fixture();
  const c = new AbortController();
  c.abort();
  const r = await nvidiaChat("{}", "u", "https://backend/api/v1", c.signal, {
    ...f,
    config,
  });
  await r.text();
  assert.equal(
    f.calls.find((x) => x.url.endsWith("/finish")).body.status,
    "cancelled",
  );
  assert.equal(f.calls.filter((x) => x.url.includes("nvidia.com")).length, 0);
});

test("opted-in NVIDIA memory is extracted from user text only after chat persistence", async () => {
  const f = fixture({ automaticMemory: true });
  let memoriesSaved = false;
  const fetcher = async (url, options) => {
    const body = JSON.parse(options.body);
    if (url.includes("nvidia.com") && body.stream === false) {
      assert.ok(f.calls.some((call) => call.url.endsWith("/finish")));
      assert.equal(body.messages[1].content, "I prefer Bangla");
      assert.ok(!JSON.stringify(body).includes("PRIVATE CONTEXT"));
      return Response.json({
        choices: [{ message: { content: '["Prefers Bangla answers"]' } }],
      });
    }
    if (url.endsWith("/memory")) {
      memoriesSaved = true;
      assert.deepEqual(body.memories, ["Prefers Bangla answers"]);
      return Response.json({ saved: 1 });
    }
    return f.fetcher(url, options);
  };
  const result = await nvidiaChat(
    '{"message":"I prefer Bangla"}',
    "user-token",
    "https://backend/api/v1",
    new AbortController().signal,
    { fetcher, config },
  );
  const text = await result.text();
  assert.equal(memoriesSaved, true);
  assert.match(text, /event: memory/);
  assert.match(text, /"saved":true/);
});

test("interrupted NVIDIA answer resumes durable context without creating another turn", async () => {
  const first = fixture({ truncated: true });
  let checkpoint;
  const fetchFirst = async (url, options) => {
    if (url.endsWith("/checkpoint")) {
      checkpoint = JSON.parse(options.body).state;
      return Response.json({ stored: true });
    }
    return first.fetcher(url, options);
  };
  await (
    await nvidiaChat(
      '{"message":"Hi"}',
      "token",
      "https://backend/api/v1",
      new AbortController().signal,
      { config, fetcher: fetchFirst },
    )
  ).text();
  assert.equal(checkpoint.content, "Hello");
  assert.equal(checkpoint.messages.at(-2).content, "Hello");
  let saved;
  const response = await nvidiaChat(
    '{"resume_message_id":3}',
    "token",
    "https://backend/api/v1",
    new AbortController().signal,
    {
      config,
      fetcher: async (url, options) => {
        const body = JSON.parse(options.body);
        if (url.endsWith("/resume")) {
          assert.equal(body.message_id, 3);
          return Response.json({
            ticket: "b".repeat(64),
            message_id: 3,
            user_message_id: 2,
            conversation_id: 1,
            sources: [],
            messages: [{ role: "system", content: "PRIVATE CONTEXT" }],
            checkpoint,
          });
        }
        if (url.endsWith("/checkpoint")) return Response.json({ stored: true });
        if (url.endsWith("/finish")) {
          saved = body;
          return Response.json({ stored: true });
        }
        assert.equal(
          url,
          "https://integrate.api.nvidia.com/v1/chat/completions",
        );
        assert.equal(body.messages.at(-2).content, "Hello");
        return new Response(
          'data: {"choices":[{"delta":{"content":" world"},"finish_reason":"stop"}]}\n\ndata: [DONE]\n\n',
          { headers: { "Content-Type": "text/event-stream" } },
        );
      },
    },
  );
  const output = await response.text();
  assert.equal(saved.content, "Hello world");
  assert.equal(saved.status, "complete");
  assert.match(output, /"content":"Hello"/);
  assert.ok(!output.includes("PRIVATE CONTEXT"));
});
