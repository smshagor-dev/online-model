// Server-only hosted image integration. No provider credentials enter browser code.
const IMAGE_ENDPOINT =
  "https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.1-schnell";
const IMAGE_MODEL = "black-forest-labs/flux.1-schnell";

type ImageConfig = { apiKey: string; bridgeKey: string };
type Fetcher = typeof fetch;

export const hostedImageEnabled = () =>
  Boolean(process.env.NVIDIA_API_KEY) &&
  (process.env.FRONTEND_CHAT_BRIDGE_KEY?.length ?? 0) >= 32;

class ImageFailure extends Error {
  status: number;
  constructor(message: string, status: number) {
    super(message);
    this.status = status;
  }
}

function imageFormat(image: Buffer): "image/png" | "image/jpeg" {
  if (
    image.length > 8 &&
    image.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))
  ) return "image/png";
  if (image.length > 4 && image[0] === 0xff && image[1] === 0xd8 && image[2] === 0xff)
    return "image/jpeg";
  throw new ImageFailure("The image service returned an unsupported file.", 502);
}

export async function generateHostedImage(
  body: string,
  token: string,
  backend: string,
  signal: AbortSignal,
  options: { fetcher?: Fetcher; config?: ImageConfig } = {},
): Promise<Response> {
  const config = options.config ?? {
    apiKey: process.env.NVIDIA_API_KEY || "",
    bridgeKey: process.env.FRONTEND_CHAT_BRIDGE_KEY || "",
  };
  if (!config.apiKey || config.bridgeKey.length < 32)
    return Response.json({ message: "Image generation is not configured." }, { status: 503 });

  let prompt: unknown;
  try {
    prompt = JSON.parse(body)?.prompt;
  } catch {
    return Response.json({ message: "Invalid image request." }, { status: 400 });
  }
  if (typeof prompt !== "string" || !prompt.trim() || prompt.length > 4000)
    return Response.json({ message: "Enter an image description up to 4000 characters." }, { status: 422 });

  const fetcher = options.fetcher || fetch;
  const base = backend.replace(/\/$/, "");
  const bridgeHeaders = {
    Authorization: "Bearer " + token,
    "X-Chat-Bridge-Key": config.bridgeKey,
    Accept: "application/json",
    "Content-Type": "application/json",
  };
  const requestBackend = (path: string, data: unknown, timeout = 30000) =>
    fetcher(base + "/" + path, {
      method: "POST",
      headers: bridgeHeaders,
      body: JSON.stringify(data),
      redirect: "error",
      cache: "no-store",
      signal: AbortSignal.timeout(timeout),
    });

  let taskId: number | undefined;
  try {
    const start = await requestBackend("media/hosted/begin", { prompt: prompt.trim(), provider_model: IMAGE_MODEL });
    if (!start.ok) {
      const status = [401, 403, 422, 429, 503].includes(start.status) ? start.status : 502;
      throw new ImageFailure(
        start.status === 429
          ? "Image request limit reached. Please try again later."
          : start.status === 401
            ? "Please sign in again."
            : start.status === 422
              ? "The image description was rejected."
              : "Image generation is currently unavailable.",
        status,
      );
    }
    const created = await start.json();
    if (!Number.isSafeInteger(created?.id) || created.id < 1)
      throw new ImageFailure("Unable to create an image task.", 502);
    taskId = created.id;

    // Official fixed endpoint and constrained parameters: no arbitrary browser URL.
    const upstream = await fetcher(IMAGE_ENDPOINT, {
      method: "POST",
      headers: {
        Authorization: "Bearer " + config.apiKey,
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        prompt: prompt.trim(),
        mode: "base",
        cfg_scale: 0,
        height: 1024,
        width: 1024,
        samples: 1,
        seed: 0,
        steps: 4,
      }),
      redirect: "error",
      cache: "no-store",
      signal: AbortSignal.any([signal, AbortSignal.timeout(110000)]),
    });
    if (!upstream.ok) {
      throw new ImageFailure(
        upstream.status === 429
          ? "Image generation is busy. Please try again later."
          : upstream.status === 401 || upstream.status === 403
            ? "Image generation is unavailable for this account."
            : "Image generation could not be completed. Please try again.",
        upstream.status === 429 ? 429 : 503,
      );
    }
    if (
      Number(upstream.headers.get("content-length") || 0) > 28000000 ||
      !upstream.headers.get("content-type")?.includes("application/json")
    )
      throw new ImageFailure("Unexpected image service response.", 502);
    const responseText = await upstream.text();
    if (responseText.length > 28000000)
      throw new ImageFailure("The generated image exceeds the size limit.", 502);
    let result: { artifacts?: { base64?: string; finishReason?: string }[] };
    try {
      result = JSON.parse(responseText);
    } catch {
      throw new ImageFailure("Invalid image service response.", 502);
    }
    const artifact = result?.artifacts?.[0];
    if (artifact?.finishReason && artifact.finishReason !== "SUCCESS")
      throw new ImageFailure("This image request could not be completed.", 422);
    const encoded = artifact?.base64;
    if (
      typeof encoded !== "string" ||
      encoded.length < 8 ||
      encoded.length > 27000000 ||
      !/^[A-Za-z0-9+/]+={0,2}$/.test(encoded)
    ) throw new ImageFailure("No valid image was returned.", 502);

    const image = Buffer.from(encoded, "base64");
    if (!image.length || image.length > 20000000)
      throw new ImageFailure("The generated image exceeds the size limit.", 502);
    const mime = imageFormat(image);
    const finish = await requestBackend(
      "media/" + taskId + "/hosted/finish",
      { data: encoded, mime, provider_model: IMAGE_MODEL },
      45000,
    );
    if (!finish.ok)
      throw new ImageFailure("The image was generated but could not be saved.", 503);
    const saved = await finish.json();
    if (saved?.status !== "complete" || saved?.id !== taskId)
      throw new ImageFailure("The image was not saved successfully.", 502);

    return Response.json(saved, {
      status: 201,
      headers: { "Cache-Control": "no-store" },
    });
  } catch (error) {
    if (taskId !== undefined) {
      try {
        await requestBackend("media/" + taskId + "/hosted/fail", {}, 12000);
      } catch {
        // Existing stale-task cleanup handles interrupted runs.
      }
    }
    const failure = error instanceof ImageFailure ? error : new ImageFailure(
      "Image generation was interrupted. Please try again later.",
      503,
    );
    return Response.json(
      { message: failure.message, ...(taskId ? { task_id: taskId } : {}) },
      { status: failure.status, headers: { "Cache-Control": "no-store" } },
    );
  }
}
