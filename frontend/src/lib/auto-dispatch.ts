import { generateHostedImage } from "./hosted-image.ts";
import { hostedMediaRequest } from "./hosted-media.ts";
import type { Route } from "./prompt-router.ts";

// Reuse existing media APIs. Laravel still verifies the account, quota and private storage.
export async function dispatchPromptMedia(
  route: Route,
  prompt: string,
  token: string,
  backend: string,
  signal: AbortSignal,
  options: { fetcher?: typeof fetch; apiKey: string; bridgeKey: string; chatModel: string },
): Promise<Response> {
  if (route.kind === "chat") throw new Error("Not a media request");
  const data = JSON.stringify({kind: route.kind, prompt, format: route.format || "docx"});
  const shared = {fetcher: options.fetcher, config: {apiKey: options.apiKey, bridgeKey: options.bridgeKey, chatModel: route.model || options.chatModel}};
  const result = route.kind === "image"
    ? await generateHostedImage(JSON.stringify({prompt}), token, backend, signal, {
        fetcher: options.fetcher, config: {apiKey: options.apiKey, bridgeKey: options.bridgeKey},
      })
    : await hostedMediaRequest(data, token, backend, signal, shared);
  // The client stream parser already handles non-2xx JSON errors.
  if (!result.ok) return result;
  const stored = await result.json();
  if (!Number.isSafeInteger(stored?.id) || stored.id < 1 || stored.status !== "complete")
    return Response.json({message: "Generated output was not saved."}, {status: 502});
  const encoder = new TextEncoder();
  const payload = "event: media\n" +
    "data: " + JSON.stringify({id: stored.id, kind: route.kind}) + "\n\n" +
    "event: done\n" +
    "data: " + JSON.stringify({status: "complete", saved: true}) + "\n\n";
  return new Response(new ReadableStream({
    start(controller) { controller.enqueue(encoder.encode(payload)); controller.close(); },
  }), {headers: {
    "Content-Type": "text/event-stream",
    "Cache-Control": "no-cache, no-transform",
    "X-Accel-Buffering": "no",
  }});
}
