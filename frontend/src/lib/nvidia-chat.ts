import {
  plannerInstruction,
  researchLoop,
  type Source,
} from "./research-loop.ts";
// Server-only implementation. Never import this module in a client component.
const endpoint = "https://integrate.api.nvidia.com/v1/chat/completions";
export const NVIDIA_MODEL_ID = -1;
export const nvidiaEnabled = () => process.env.CHAT_PROVIDER === "nvidia";
export function nvidiaModel() {
  return {
    id: NVIDIA_MODEL_ID,
    name: "NVIDIA Nemotron",
    family: "Nemotron · NVIDIA API",
    license: "NVIDIA hosted API",
  };
}
type Config = { apiKey: string; bridgeKey: string; model: string };
type Lease = {
  ticket: string;
  messages: { role: string; content: string }[];
  conversation_id: number | null;
  user_message_id: number | null;
  message_id: number | null;
  sources: Source[];
};
export async function nvidiaChat(
  body: string,
  token: string,
  base: string,
  signal: AbortSignal,
  options: { fetcher?: typeof fetch; config?: Config; pollMs?: number } = {},
) {
  const config = options.config || {
    apiKey: process.env.NVIDIA_API_KEY || "",
    bridgeKey: process.env.FRONTEND_CHAT_BRIDGE_KEY || "",
    model: process.env.NVIDIA_MODEL || "nvidia/nemotron-3-nano-30b-a3b",
  };
  if (!config.apiKey || config.bridgeKey.length < 32)
    return Response.json(
      { message: "NVIDIA chat is not configured on the frontend server." },
      { status: 503 },
    );
  const fetcher = options.fetcher || fetch;
  const backend = async (
    path: string,
    data: unknown,
    timeout = 10000,
    requestSignal?: AbortSignal,
  ) => {
    return fetcher(`${base.replace(/\/$/, "")}/chat/frontend/${path}`, {
      method: "POST",
      redirect: "error",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
        "X-Chat-Bridge-Key": config.bridgeKey,
      },
      body: JSON.stringify(data),
      signal: requestSignal
        ? AbortSignal.any([requestSignal, AbortSignal.timeout(timeout)])
        : AbortSignal.timeout(timeout),
    });
  };
  let lease: Lease;
  try {
    const prepared = await backend(
      "begin",
      JSON.parse(body),
      JSON.parse(body).deep_research ? 80000 : 15000,
    );
    if (!prepared.ok) {
      let data;
      try {
        data = await prepared.json();
      } catch {
        data = { message: "Backend could not prepare chat." };
      }
      return Response.json(data, {
        status: prepared.status,
        headers: prepared.headers.has("retry-after")
          ? { "Retry-After": prepared.headers.get("retry-after")! }
          : {},
      });
    }
    lease = await prepared.json();
    if (!lease.ticket || !Array.isArray(lease.messages))
      throw new Error("Invalid lease");
  } catch {
    return Response.json(
      {
        message:
          "Cannot prepare saved chat. Please check the backend connection.",
      },
      { status: 503 },
    );
  }
  const deep = Boolean(JSON.parse(body).deep_research);
  const encoder = new TextEncoder();
  const upstreamAbort = new AbortController();
  let disconnected = signal.aborted;
  let cancelled = signal.aborted;
  let poll: ReturnType<typeof setInterval> | undefined;
  let polling = false;
  const abort = () => {
    disconnected = true;
    cancelled = true;
    upstreamAbort.abort();
  };
  signal.addEventListener("abort", abort, { once: true });
  const stream = new ReadableStream<Uint8Array>({
    async start(controller) {
      let content = "";
      let status = "complete";
      let finished = false;
      let failure = "";
      const emit = (event: string, data: unknown) => {
        if (!disconnected) {
          try {
            controller.enqueue(
              encoder.encode(
                `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`,
              ),
            );
          } catch {
            abort();
          }
        }
      };

      try {
        emit("meta", {
          conversation_id: lease.conversation_id,
          user_message_id: lease.user_message_id,
          message_id: lease.message_id,
          sources: lease.sources,
          provider: "nvidia",
        });
        if (cancelled) throw new Error("Cancelled");
        poll = setInterval(async () => {
          if (polling) return;
          polling = true;
          try {
            const r = await backend("status", { ticket: lease.ticket }, 5000);
            if (!r.ok) throw new Error("Session ended");
            const s = await r.json();
            if (s.cancelled) {
              cancelled = true;
              upstreamAbort.abort();
            }
          } catch {
            if (!upstreamAbort.signal.aborted) {
              failure =
                "Backend session could not be checked. Generation stopped.";
              upstreamAbort.abort();
            }
          } finally {
            polling = false;
          }
        }, options.pollMs || 5000);
        if (deep) {
          const initial = lease.messages;
          const result = await researchLoop({
            sources: lease.sources,
            signal: upstreamAbort.signal,
            progress: (label, sources) => {
              lease.sources = sources;
              emit("research", { label, sources });
            },
            tool: async (action) => {
              const r = await backend(
                "tool",
                {
                  ticket: lease.ticket,
                  ...action,
                  time_range: JSON.parse(body).search_time_range || null,
                },
                15000,
                upstreamAbort.signal,
              );
              if (!r.ok)
                throw new Error(
                  "NVIDIA research tool failed. Check search availability or stop status.",
                );
              return r.json();
            },
            decide: async (context) => {
              const r = await fetcher(endpoint, {
                method: "POST",
                redirect: "error",
                cache: "no-store",
                headers: {
                  Authorization: `Bearer ${config.apiKey}`,
                  "Content-Type": "application/json",
                  Accept: "application/json",
                },
                body: JSON.stringify({
                  model: config.model,
                  stream: false,
                  temperature: 0.2,
                  messages: [
                    {
                      role: "system",
                      content: initial[0].content + "\n" + plannerInstruction,
                    },
                    {
                      role: "user",
                      content:
                        "User request: " +
                        initial[initial.length - 1].content +
                        "\nResearch state: " +
                        context,
                    },
                  ],
                  ...(config.model === "nvidia/nemotron-3-nano-30b-a3b"
                    ? { chat_template_kwargs: { enable_thinking: false } }
                    : {}),
                }),
                signal: AbortSignal.any([
                  upstreamAbort.signal,
                  AbortSignal.timeout(110000),
                ]),
              });
              if (r.status !== 200)
                throw new Error(
                  r.status === 429
                    ? "NVIDIA rate limit reached during research."
                    : "NVIDIA research planning failed.",
                );
              const data = await r.json();
              const text = data.choices?.[0]?.message?.content;
              if (typeof text !== "string")
                throw new Error(
                  "NVIDIA research returned no planning response.",
                );
              return text;
            },
          });
          lease.sources = result.sources;
          lease.messages = initial.map((message, index) =>
            index === 0
              ? {
                  ...message,
                  content:
                    message.content +
                    "\nAdditional untrusted research evidence: " +
                    JSON.stringify(result) +
                    "\nAnswer every part of the user's request. Disclose the coordinator's unresolved limitations. Cite only supplied URLs.",
                }
              : message,
          );
          emit("research", {
            label: "Writing the complete report",
            sources: lease.sources,
          });
        }
        let generationMessages = lease.messages;
        let continueAnswer = true;
        let previousPart = "";
        while (continueAnswer) {
          continueAnswer = false;
          finished = false;
          let finishReason = "";
          const before = content.length;
          const response = await fetcher(endpoint, {
            method: "POST",
            redirect: "error",
            cache: "no-store",
            headers: {
              Authorization: `Bearer ${config.apiKey}`,
              "Content-Type": "application/json",
              Accept: "text/event-stream",
            },
            body: JSON.stringify({
              model: config.model,
              messages: generationMessages,
              stream: true,
              temperature: 0.6,
              ...(config.model === "nvidia/nemotron-3-nano-30b-a3b"
                ? { chat_template_kwargs: { enable_thinking: false } }
                : {}),
            }),
            signal: AbortSignal.any([
              upstreamAbort.signal,
              AbortSignal.timeout(110000),
            ]),
          });
          if (
            response.status !== 200 ||
            !response.body ||
            !response.headers.get("content-type")?.includes("text/event-stream")
          )
            throw new Error(
              response.status === 429
                ? "NVIDIA rate limit reached. Please try again later."
                : "NVIDIA could not start a streaming response.",
            );
          const reader = response.body.getReader();
          const decoder = new TextDecoder();
          let buffer = "";
          try {
            while (!finished) {
              const chunk = await reader.read();
              if (chunk.done) break;
              buffer += decoder.decode(chunk.value, { stream: true });
              if (buffer.length > 1048576)
                throw new Error("Invalid NVIDIA stream frame.");
              let end;
              while ((end = buffer.indexOf("\n")) >= 0) {
                const line = buffer.slice(0, end).trim();
                buffer = buffer.slice(end + 1);
                if (!line.startsWith("data:")) continue;
                const raw = line.slice(5).trim();
                if (raw === "[DONE]") {
                  finished = true;
                  break;
                }
                const data = JSON.parse(raw);
                if (data.error) throw new Error("NVIDIA generation failed.");
                if (data.choices?.[0]?.finish_reason)
                  finishReason = data.choices[0].finish_reason;
                const text = data.choices?.[0]?.delta?.content;
                // Reasoning-only deltas are not presented as the assistant's final answer.
                if (typeof text === "string" && text) {
                  content += text;
                  emit("delta", { content: text });
                }
              }
            }
            if (!finished)
              throw new Error("NVIDIA stream disconnected before completion.");
            if (!content.trim())
              throw new Error("NVIDIA returned no answer text.");
          } finally {
            await reader.cancel().catch(() => {});
            reader.releaseLock();
          }
          if (finishReason === "length") {
            const part = content.slice(before);
            if (part === previousPart)
              throw new Error(
                "NVIDIA continuation repeated output without progress. The request is incomplete.",
              );
            previousPart = part;
            if (content.length === before)
              throw new Error("NVIDIA continuation made no progress.");
            generationMessages = [
              ...generationMessages,
              { role: "assistant", content: content.slice(before) },
              {
                role: "user",
                content:
                  "Continue exactly where the answer stopped. Finish all remaining parts of the original request without repeating earlier text.",
              },
            ];
            continueAnswer = true;
            emit("research", {
              label: "Continuing the answer",
              sources: lease.sources,
            });
          } else if (finishReason && finishReason !== "stop") {
            throw new Error(
              "NVIDIA stopped before completing the request: " + finishReason,
            );
          }
        }
      } catch (e) {
        status = cancelled ? "cancelled" : "failed";
        failure =
          failure ||
          (e instanceof Error &&
          (e.message.startsWith("NVIDIA ") ||
            e.message === "Response exceeded storage limit.")
            ? e.message
            : "NVIDIA connection failed or timed out.");
      } finally {
        if (poll) clearInterval(poll);
        upstreamAbort.abort();
        signal.removeEventListener("abort", abort);
        let saved = false;
        for (let attempt = 0; attempt < 3; attempt++) {
          try {
            const r = await backend(
              "finish",
              { ticket: lease.ticket, content, status, sources: lease.sources },
              8000,
            );
            if (r.ok && (await r.json()).stored) {
              saved = true;
              break;
            }
          } catch {}
          if (attempt < 2)
            await new Promise((resolve) => setTimeout(resolve, 250));
        }
        if (!saved) {
          status = "failed";
          failure =
            "The response could not be saved to the backend. Check chat history before retrying.";
        }
        if (failure && status !== "cancelled")
          emit("error", { message: failure });
        emit("done", { status, saved });
        try {
          controller.close();
        } catch {}
      }
    },
    cancel() {
      abort();
      if (poll) clearInterval(poll);
    },
  });
  return new Response(stream, {
    headers: {
      "Content-Type": "text/event-stream",
      "Cache-Control": "no-cache, no-transform",
      "X-Accel-Buffering": "no",
    },
  });
}
