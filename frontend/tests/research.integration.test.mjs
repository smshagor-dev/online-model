import test from "node:test";
import assert from "node:assert/strict";
import { researchLoop } from "../src/lib/research-loop.ts";
import { nvidiaChat } from "../src/lib/nvidia-chat.ts";

test("research continues beyond the initial query/source batch until coverage is finished", async () => {
  let decisions = 0;
  const result = await researchLoop({
    sources: [],
    signal: new AbortController().signal,
    progress() {},
    decide: async () =>
      JSON.stringify(
        ++decisions <= 12
          ? { action: "search", query: "Question " + decisions }
          : { action: "finish", reason: "Every requested part has evidence" },
      ),
    tool: async (action) => ({
      sources: [
        { url: `https://example.org/${action.query}`, title: "Source" },
      ],
    }),
  });
  assert.equal(decisions, 13);
  assert.equal(result.sources.length, 12);
  assert.equal(result.conclusion, "Every requested part has evidence");
});
test("repeated research actions fail explicitly instead of claiming completion", async () => {
  await assert.rejects(
    () =>
      researchLoop({
        sources: [],
        signal: new AbortController().signal,
        progress() {},
        decide: async () => '{"action":"search","query":"same"}',
        tool: async () => ({ sources: [] }),
      }),
    /request is incomplete/,
  );
});
test("research cancellation prevents the next tool request", async () => {
  const controller = new AbortController();
  let requests = 0;
  await assert.rejects(() =>
    researchLoop({
      sources: [],
      signal: controller.signal,
      progress() {},
      decide: async () => {
        controller.abort();
        return '{"action":"read","url":"https://example.org"}';
      },
      tool: async () => {
        requests++;
        return {};
      },
    }),
  );
  assert.equal(requests, 0);
});
test("provider length boundary continues and saves a single complete answer", async () => {
  let generated = 0;
  let saved;
  const response = await nvidiaChat(
    '{"message":"Explain fully"}',
    "token",
    "https://backend/api/v1",
    new AbortController().signal,
    {
      config: { apiKey: "key", bridgeKey: "b".repeat(32), model: "fixture" },
      fetcher: async (url, options) => {
        const body = JSON.parse(options.body);
        if (url.endsWith("/begin"))
          return Response.json({
            ticket: "a".repeat(64),
            messages: [{ role: "user", content: "Explain fully" }],
            sources: [],
            message_id: 1,
          });
        if (url.endsWith("/finish")) {
          saved = body;
          return Response.json({ stored: true });
        }
        if (url.endsWith("/checkpoint")) return Response.json({ stored: true });
        if (url.endsWith("/status")) return Response.json({ cancelled: false });
        assert.equal(body.max_tokens, undefined);
        generated++;
        if (generated === 2) assert.equal(body.messages.at(-1).role, "user");
        return new Response(
          `data: ${JSON.stringify({ choices: [{ delta: { content: generated === 1 ? "First part. " : "Final part." }, finish_reason: generated === 1 ? "length" : "stop" }] })}\n\ndata: [DONE]\n\n`,
          { headers: { "Content-Type": "text/event-stream" } },
        );
      },
    },
  );
  const text = await response.text();
  assert.equal(generated, 2);
  assert.equal(saved.content, "First part. Final part.");
  assert.equal(saved.status, "complete");
  assert.match(text, /"saved":true/);
});

test("NVIDIA research searches and reads before saving the sourced report", async () => {
  let plans = 0;
  let saved;
  const url = "https://example.org/paper";
  const result = await nvidiaChat(
    '{"message":"Full report","deep_research":true}',
    "user-token",
    "https://backend/api/v1",
    new AbortController().signal,
    {
      config: {
        apiKey: "private-key",
        bridgeKey: "b".repeat(32),
        model: "fixture",
      },
      fetcher: async (endpoint, options) => {
        const body = JSON.parse(options.body);
        if (endpoint.endsWith("/begin"))
          return Response.json({
            ticket: "a".repeat(64),
            sources: [],
            messages: [
              { role: "system", content: "Context" },
              { role: "user", content: "Full report" },
            ],
            message_id: 3,
          });
        if (endpoint.endsWith("/checkpoint"))
          return Response.json({ stored: true });
        if (endpoint.endsWith("/status"))
          return Response.json({ cancelled: false });
        if (endpoint.endsWith("/tool"))
          return Response.json(
            body.action === "search"
              ? { sources: [{ url, title: "Paper", snippet: "Summary" }] }
              : {
                  url,
                  page_text: "PRIVATE PAGE EVIDENCE",
                  read_status: "read",
                },
          );
        if (endpoint.endsWith("/finish")) {
          saved = body;
          return Response.json({ stored: true });
        }
        if (!body.stream) {
          const action = [
            { action: "search", query: "Full report evidence" },
            { action: "read", url },
            { action: "finish", reason: "Request covered" },
          ][plans++];
          return Response.json({
            choices: [{ message: { content: JSON.stringify(action) } }],
          });
        }
        assert.ok(body.messages[0].content.includes("PRIVATE PAGE EVIDENCE"));
        return new Response(
          'data: {"choices":[{"delta":{"content":"Report [1](https://example.org/paper)"},"finish_reason":"stop"}]}\n\ndata: [DONE]\n\n',
          { headers: { "Content-Type": "text/event-stream" } },
        );
      },
    },
  );
  const output = await result.text();
  assert.equal(plans, 3);
  assert.equal(saved.sources.length, 1);
  assert.equal(saved.sources[0].read_status, "read");
  assert.equal(saved.sources[0].page_text, undefined);
  assert.equal(saved.status, "complete");
  assert.match(output, /event: research/);
  assert.ok(!output.includes("PRIVATE PAGE EVIDENCE"));
  assert.ok(!output.includes("private-key"));
  assert.ok(!output.includes("a".repeat(64)));
});

test("research checkpoint replays only pending tool and preserves completed evidence", async () => {
  let checkpoint;
  let plans = 0;
  await assert.rejects(
    () =>
      researchLoop({
        sources: [],
        signal: new AbortController().signal,
        progress() {},
        decide: async () =>
          JSON.stringify({
            action: "read",
            url: "https://example.org/pending",
          }),
        checkpoint: async (state) => {
          checkpoint = structuredClone(state);
        },
        tool: async () => {
          throw new Error("Process interrupted");
        },
      }),
    /Process interrupted/,
  );
  assert.equal(checkpoint.pending.action, "read");
  const result = await researchLoop({
    sources: [],
    state: checkpoint,
    signal: new AbortController().signal,
    progress() {},
    checkpoint: async (state) => {
      checkpoint = structuredClone(state);
    },
    decide: async (context) => {
      plans++;
      assert.ok(context.includes("PRIVATE EVIDENCE"));
      return JSON.stringify({ action: "finish", reason: "Coverage complete" });
    },
    tool: async (action) => {
      assert.equal(action.url, "https://example.org/pending");
      return {
        url: action.url,
        page_text: "PRIVATE EVIDENCE",
        read_status: "read",
      };
    },
  });
  assert.equal(plans, 1);
  assert.equal(result.sources[0].read_status, "read");
  assert.equal(checkpoint.pending, null);
  assert.equal(checkpoint.actions.length, 1);
});
