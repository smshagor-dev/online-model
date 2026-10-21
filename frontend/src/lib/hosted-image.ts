// Server-only cloud image bridge. The NVIDIA key is owned exclusively by Laravel.
// Generation runs on the Laravel media queue, not the Vercel request.
type ImageConfig = { apiKey?: string; bridgeKey?: string };
type Fetcher = typeof fetch;
type ImageOptions = { fetcher?: Fetcher; config?: ImageConfig; waitForCompletion?: boolean };

export async function generateHostedImage(
  body: string, token: string, backend: string, signal: AbortSignal, options: ImageOptions = {},
): Promise<Response> {
  let data: Record<string, unknown>;
  try {
    data = JSON.parse(body);
    if (!data || typeof data !== "object" || Array.isArray(data)) throw new Error("Invalid");
  } catch {
    return Response.json({message: "Invalid image request."}, {status: 400});
  }
  if (typeof data.prompt !== "string" || !data.prompt.trim() || data.prompt.length > 4000)
    return Response.json({message: "Enter an image description up to 4000 characters."}, {status: 422});

  const fetcher = options.fetcher || fetch;
  const base = backend.replace(/\/$/, "");
  const headers = {Authorization: "Bearer " + token, Accept: "application/json", "Content-Type": "application/json"};
  const request = async (endpoint: string, method: "GET" | "POST", json?: unknown) =>
    fetcher(base + "/" + endpoint, {
      method, headers, ...(json ? {body: JSON.stringify(json)} : {}),
      redirect: "error", cache: "no-store", signal: AbortSignal.any([signal, AbortSignal.timeout(18000)]),
    });
  try {
    const started = await request("media/cloud-image", "POST", data);
    if (!started.ok) {
      const result = await started.json().catch(() => ({}));
      return Response.json({message: typeof result.message === "string" ? result.message : "Image request rejected."},
        {status: started.status, headers: {"Cache-Control":"no-store"}});
    }
    const created = await started.json();
    if (!Number.isSafeInteger(created?.id) || created.id < 1 || !Array.isArray(created.ids))
      return Response.json({message:"Invalid cloud image task response."}, {status:502});
    if (!options.waitForCompletion) return Response.json(created, {status:202, headers:{"Cache-Control":"no-store"}});
    // Native chat tools wait for a saved artifact; the Media Studio does not block on it.
    // Worker may outlive the request; any interruption leaves a resumable task in Library.
    if (created.ids.length !== 1) return Response.json({message:"Chat image tool supports one generated image per call."}, {status:422});
    for (let attempts = 0; attempts < 48; attempts++) {
      if (signal.aborted) throw new Error("Cancelled");
      await new Promise<void>((resolve, reject) => {
        const timer = setTimeout(resolve, 3000);
        signal.addEventListener("abort", () => {clearTimeout(timer); reject(new Error("Cancelled"));}, {once:true});
      });
      const polled = await request("media/" + created.id, "GET");
      if (!polled.ok) break;
      const task = await polled.json();
      if (task?.status === "complete" && task?.output_mime?.startsWith("image/"))
        return Response.json({id:created.id,status:"complete",kind:"image"},{status:201});
      if (task?.status === "failed" || task?.status === "cancelled")
        return Response.json({message:"Image generation failed. Check the saved task in Library.",task_id:created.id}, {status:502});
    }
    return Response.json({message:"Image generation is still processing. Check Library for the result.",task_id:created.id},
      {status:202, headers:{"Cache-Control":"no-store"}});
  } catch {
    return Response.json({message:"Cannot reach the cloud image service. Check Library before retrying."}, {status:503});
  }
}
