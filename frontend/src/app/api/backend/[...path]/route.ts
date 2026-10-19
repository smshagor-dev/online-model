import {
  nvidiaChat,
  nvidiaEnabled,
  nvidiaModel,
  NVIDIA_MODEL_ID,
} from "@/lib/nvidia-chat";
import { cookies } from "next/headers";
import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
const cookieName = "openmindai_token";
const publicPaths = new Set([
  "login",
  "register",
  "forgot-password",
  "reset-password",
  "email/resend",
  "email/change/confirm",
]);
const routes: Record<string, RegExp> = {
  GET: /^(explore\/assistants(?:\/[a-f0-9-]{36})?|assistants|tasks(?:\/\d+\/runs)?|canvases\/\d+\/versions(?:\/\d+)?|conversations\/\d+\/branches|media(?:\/\d+(?:\/output)?)?|me|models|tokens|capabilities|preferences|projects|memories|attachments(?:\/\d+)?|canvases(?:\/\d+)?|shares|export|shared\/[a-f0-9]{64}|conversations(?:\/\d+)?)$/,
  POST: /^(explore\/assistants\/[a-f0-9-]{36}\/copy|assistants(?:\/\d+\/publish)?|tasks(?:\/\d+\/runs(?:\/\d+\/resume)?)?|me\/(avatar|email)|email\/change\/confirm|settings\/data|media|login|register|forgot-password|reset-password|email\/resend|logout|logout-all|web-search|chat(?:\/stream)?|projects|memories|attachments|canvases|conversations\/\d+\/(branch|share)|conversations\/\d+\/messages\/\d+\/stop|2fa\/(setup|confirm|recovery-codes))$/,
  PATCH: /^(me|preferences|conversations\/\d+)$/,
  PUT: /^(assistants\/\d+|tasks\/\d+|me\/password|projects\/\d+|canvases\/\d+|memories\/\d+|conversations\/\d+\/messages\/\d+\/feedback)$/,
  DELETE:
    /^(assistants\/\d+(?:\/publish)?|tasks\/\d+(?:\/runs\/\d+)?|me\/(avatar|email)|media\/\d+|me|2fa|tokens\/\d+|conversations\/\d+|projects\/\d+|canvases\/\d+|memories\/\d+|attachments\/\d+|shares\/\d+)$/,
};
async function proxy(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
) {
  const { path } = await context.params;
  const endpoint = path.join("/");
  if (!routes[request.method]?.test(endpoint))
    return NextResponse.json({ message: "Not found." }, { status: 404 });
  if (!["GET", "HEAD"].includes(request.method)) {
    const expectedOrigin = new URL(process.env.FRONTEND_URL || request.url)
      .origin;
    if (request.headers.get("origin") !== expectedOrigin)
      return NextResponse.json(
        { message: "Invalid request origin." },
        { status: 403 },
      );
    if (!request.headers.get("content-type")?.startsWith("application/json"))
      return NextResponse.json(
        { message: "JSON is required." },
        { status: 415 },
      );
  }
  const jar = await cookies();
  const token = jar.get(cookieName)?.value;
  const isPublic =
    publicPaths.has(endpoint) ||
    endpoint.startsWith("shared/") ||
    (request.method === "GET" &&
      /^explore\/assistants(?:\/[a-f0-9-]{36})?$/.test(endpoint));
  if (!token && !isPublic)
    return NextResponse.json({ message: "Please sign in." }, { status: 401 });
  const base = process.env.LARAVEL_API_URL;
  if (!base)
    return NextResponse.json(
      { message: "The backend connection has not been configured." },
      { status: 503 },
    );
  let body: string | undefined;
  if (request.method !== "GET") {
    const reader = request.body?.getReader();
    const chunks: Uint8Array[] = [];
    let size = 0;
    if (reader) {
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        size += value.byteLength;
        if (
          size >
          (endpoint === "media"
            ? 11_000_000
            : endpoint === "me/avatar"
              ? 130_000
              : 100_000)
        ) {
          await reader.cancel();
          return NextResponse.json(
            { message: "Request is too large." },
            { status: 413 },
          );
        }
        chunks.push(value);
      }
    }
    body = Buffer.concat(chunks).toString("utf8");
    try {
      JSON.parse(body);
    } catch {
      return NextResponse.json({ message: "Invalid JSON." }, { status: 400 });
    }
  }
  if (
    endpoint === "chat/stream" &&
    request.method === "POST" &&
    nvidiaEnabled() &&
    Number(JSON.parse(body!)?.model_id) === NVIDIA_MODEL_ID
  ) {
    const response = await nvidiaChat(body!, token!, base, request.signal);
    if (response.status === 401) jar.delete(cookieName);
    return response;
  }
  if (endpoint === "models" && request.method === "GET" && nvidiaEnabled()) {
    // Authenticate against Laravel before exposing the frontend-hosted model.
    try {
      const response = await fetch(`${base.replace(/\/$/, "")}/models`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
        },
        cache: "no-store",
        redirect: "error",
        signal: AbortSignal.timeout(10000),
      });
      if (response.status === 401) jar.delete(cookieName);
      if (!response.ok)
        return NextResponse.json(
          { message: "Cannot load models." },
          { status: response.status },
        );
      const local = await response.json();
      return NextResponse.json(
        [nvidiaModel(), ...(Array.isArray(local) ? local : [])],
        { headers: { "Cache-Control": "no-store" } },
      );
    } catch {
      return NextResponse.json(
        { message: "Cannot connect to backend." },
        { status: 503 },
      );
    }
  }
  try {
    const url = new URL(`${base.replace(/\/$/, "")}/${endpoint}`);
    // Only a numeric page parameter reaches Laravel; clients cannot choose an upstream URL.
    const page = request.nextUrl.searchParams.get("page");
    if (page && /^\d{1,6}$/.test(page)) url.searchParams.set("page", page);
    for (const key of ["q", "project_id", "archived", "kind"]) {
      const value = request.nextUrl.searchParams.get(key);
      if (value && value.length <= 100) url.searchParams.set(key, value);
    }
    const upstream = await fetch(url, {
      method: request.method,
      body,
      cache: "no-store",
      redirect: "error",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        ...(process.env.TRUST_PROXY_HEADERS === "true" &&
        request.headers.get("x-forwarded-for")
          ? { "X-Forwarded-For": request.headers.get("x-forwarded-for")! }
          : {}),
        ...(token && !isPublic ? { Authorization: `Bearer ${token}` } : {}),
      },
      signal: endpoint.startsWith("chat")
        ? request.signal
        : AbortSignal.timeout(30_000),
    });
    if (
      upstream.ok &&
      upstream.headers.get("content-type")?.includes("text/event-stream")
    ) {
      return new NextResponse(upstream.body, {
        status: 200,
        headers: {
          "Content-Type": "text/event-stream",
          "Cache-Control": "no-cache, no-transform",
          "X-Accel-Buffering": "no",
        },
      });
    }
    if (upstream.ok && /^media\/\d+\/output$/.test(endpoint)) {
      const mime = upstream.headers.get("content-type")?.split(";")[0];
      if (
        !mime ||
        !["image/png", "audio/wav", "video/mp4", "application/zip"].includes(
          mime,
        )
      )
        return NextResponse.json(
          { message: "Invalid media format." },
          { status: 502 },
        );
      return new NextResponse(upstream.body, {
        headers: {
          "Content-Type": mime,
          "Content-Disposition": `attachment; filename="openmindai-${endpoint.split("/")[1]}.${mime === "image/png" ? "png" : mime === "audio/wav" ? "wav" : mime === "application/zip" ? "zip" : "mp4"}"`,
          "Cache-Control": "private, no-store",
          "X-Content-Type-Options": "nosniff",
        },
      });
    }
    const text = await upstream.text();
    let data: Record<string, unknown> = {};
    if (text) {
      try {
        data = JSON.parse(text);
      } catch {
        return NextResponse.json(
          { message: "The backend returned an invalid response." },
          { status: 502 },
        );
      }
    }
    if (endpoint === "login" && upstream.ok && typeof data.token === "string") {
      jar.set(cookieName, data.token, {
        httpOnly: true,
        secure: process.env.NODE_ENV === "production",
        sameSite: "lax",
        path: "/",
        maxAge: 30 * 86400,
      });
      delete data.token;
    }
    if (
      upstream.status === 401 ||
      (upstream.ok &&
        [
          "logout",
          "logout-all",
          "me/password",
          "email/change/confirm",
        ].includes(endpoint)) ||
      (upstream.ok && endpoint === "me" && request.method === "DELETE")
    )
      jar.delete(cookieName);
    const response =
      upstream.status === 204
        ? new NextResponse(null, { status: 204 })
        : NextResponse.json(data, { status: upstream.status });
    response.headers.set("Cache-Control", "no-store");
    const retry = upstream.headers.get("retry-after");
    if (retry) response.headers.set("Retry-After", retry);
    return response;
  } catch {
    return NextResponse.json(
      {
        message:
          endpoint === "chat"
            ? "The model did not respond in time. Check your chat history before retrying."
            : "Cannot connect to the backend. Please try again.",
      },
      { status: 503 },
    );
  }
}
export {
  proxy as GET,
  proxy as POST,
  proxy as PATCH,
  proxy as PUT,
  proxy as DELETE,
};
