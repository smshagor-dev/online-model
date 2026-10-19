import { cookies } from "next/headers";
import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
const cookieName = "online_model_token";
const publicPaths = new Set([
  "login",
  "register",
  "forgot-password",
  "reset-password",
  "email/resend",
]);
const routes: Record<string, RegExp> = {
  GET: /^(me|models|tokens|conversations(?:\/\d+)?)$/,
  POST: /^(login|register|forgot-password|reset-password|email\/resend|logout|logout-all|chat|2fa\/(setup|confirm|recovery-codes))$/,
  PATCH: /^me$/,
  PUT: /^me\/password$/,
  DELETE: /^(me|2fa|tokens\/\d+|conversations\/\d+)$/,
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
  if (!token && !publicPaths.has(endpoint))
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
        if (size > 100_000) {
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
  try {
    const url = new URL(`${base.replace(/\/$/, "")}/${endpoint}`);
    // Only a numeric page parameter reaches Laravel; clients cannot choose an upstream URL.
    const page = request.nextUrl.searchParams.get("page");
    if (page && /^\d{1,6}$/.test(page)) url.searchParams.set("page", page);
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
        ...(token && !publicPaths.has(endpoint)
          ? { Authorization: `Bearer ${token}` }
          : {}),
      },
      signal: AbortSignal.timeout(endpoint === "chat" ? 135_000 : 30_000),
    });
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
        ["logout", "logout-all", "me/password"].includes(endpoint)) ||
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
