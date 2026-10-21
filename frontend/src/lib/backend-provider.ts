// Server-only transport: every provider inference request goes to Laravel.
// Never forward the upstream Authorization header or accept a caller-supplied URL.
const ENDPOINTS: Record<string, string> = {
  "https://integrate.api.nvidia.com/v1/chat/completions": "chat",
  "https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.1-schnell": "image",
  "https://1598d209-5e27-4d3c-8079-4751568b1081.invocation.api.nvcf.nvidia.com/v1/audio/transcriptions": "transcription",
  "https://877104f7-e885-42b9-8de8-f6e4c6303969.invocation.api.nvcf.nvidia.com/v1/audio/synthesize": "speech",
  "https://ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano": "video",
};

async function providerPayload(service: string, body: BodyInit | null | undefined): Promise<Record<string, unknown>> {
  if (body instanceof FormData) {
    if (service === "transcription") {
      const file = body.get("file");
      if (!(file instanceof Blob) || file.size < 44 || file.size > 8_000_000)
        throw new Error("Invalid audio upload.");
      const bytes = Buffer.from(await file.arrayBuffer());
      return { data: bytes.toString("base64") };
    }
    if (service === "speech") {
      return { text: body.get("text"), language: body.get("language") };
    }
    throw new Error("Unsupported media encoding.");
  }
  if (typeof body !== "string")
    throw new Error("Invalid hosted model request.");
  const value: unknown = JSON.parse(body);
  if (!value || typeof value !== "object" || Array.isArray(value))
    throw new Error("Invalid hosted model payload.");
  return value as Record<string, unknown>;
}

export function backendProviderFetcher(
  backend: string, userToken: string, bridgeKey: string,
  transport: typeof fetch = fetch,
): typeof fetch {
  const target = backend.replace(/\/$/, "") + "/provider/invoke";
  return (async (resource: RequestInfo | URL, options?: RequestInit): Promise<Response> => {
    const address = typeof resource === "string" ? resource
      : resource instanceof URL ? resource.toString()
      : resource.url;
    const service = ENDPOINTS[address];
    if (!service) {
      // Do not accidentally fall back to a direct provider call when a new
      // provider endpoint is introduced without backend routing.
      if (/^https:\/\/(?:[a-z0-9.-]+\.)?nvidia\.com(?::443)?\//i.test(address))
        throw new Error("The backend does not support this provider endpoint.");
      return transport(resource, options);
    }
    if (bridgeKey.length < 32) throw new Error("The backend inference bridge is not configured.");
    const payload = await providerPayload(service, options?.body);
    return transport(target, {
      method: "POST",
      cache: "no-store",
      redirect: "error",
      headers: {
        Authorization: "Bearer " + userToken,
        "X-Chat-Bridge-Key": bridgeKey,
        "Content-Type": "application/json",
        Accept: service === "chat" && payload.stream === true
          ? "text/event-stream" : service === "speech" ? "audio/wav" : "application/json",
      },
      body: JSON.stringify({ service, payload }),
      signal: options?.signal,
    });
  }) as typeof fetch;
}
