import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import http from "node:http";
import { spawn } from "node:child_process";
import { once } from "node:events";

let backend, next, origin;
let lastRequest;
let logs = "";
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
before(async () => {
  backend = http
    .createServer(async (req, res) => {
      let body = "";
      for await (const chunk of req) body += chunk;
      lastRequest = {
        url: req.url,
        method: req.method,
        authorization: req.headers.authorization,
        body,
      };
      res.setHeader("Content-Type", "application/json");
      if (req.url === "/api/v1/login")
        return res.end(
          JSON.stringify({
            token: "fixture-secret-token",
            user: { id: 1, name: "Test User", email: "test@example.com" },
          }),
        );
      if (req.url === "/api/v1/logout") {
        res.statusCode = 204;
        return res.end();
      }
      if (req.url.startsWith("/api/v1/conversations"))
        return res.end(
          JSON.stringify({ data: [], current_page: 2, last_page: 2 }),
        );
      if (req.url === "/api/v1/chat")
        return res.end(
          JSON.stringify({
            conversation_id: 7,
            message: { role: "assistant", content: "Fixture response" },
          }),
        );
      if (req.url === "/api/v1/models") {
        res.statusCode = 429;
        res.setHeader("Retry-After", "25");
        return res.end(JSON.stringify({ message: "Too many requests." }));
      }
      if (req.url === "/api/v1/tokens") {
        res.setHeader("Content-Type", "text/html");
        return res.end("<html>upstream error</html>");
      }
      if (req.url === "/api/v1/me")
        return res.end(JSON.stringify({ id: 1, name: "Test User" }));
      res.statusCode = 404;
      res.end("{}");
    })
    .listen(0, "127.0.0.1");
  await once(backend, "listening");
  const probe = http.createServer().listen(0, "127.0.0.1");
  await once(probe, "listening");
  const port = probe.address().port;
  await new Promise((resolve) => probe.close(resolve));
  origin = `http://127.0.0.1:${port}`;
  next = spawn(
    process.execPath,
    [
      "node_modules/next/dist/bin/next",
      "start",
      "-H",
      "127.0.0.1",
      "-p",
      String(port),
    ],
    {
      cwd: process.cwd(),
      env: {
        ...process.env,
        NEXT_TELEMETRY_DISABLED: "1",
        FRONTEND_URL: origin,
        LARAVEL_API_URL: `http://127.0.0.1:${backend.address().port}/api/v1`,
      },
      stdio: ["ignore", "pipe", "pipe"],
    },
  );
  next.stdout.on("data", (data) => {
    logs += data;
  });
  next.stderr.on("data", (data) => {
    logs += data;
  });
  for (let i = 0; i < 80; i++) {
    try {
      const r = await fetch(origin);
      if (r.ok) return;
    } catch {}
    await delay(200);
  }
  throw new Error(`Next.js did not start: ${logs}`);
});
after(async () => {
  next?.kill("SIGTERM");
  await new Promise((resolve) => backend?.close(resolve));
});
const request = (path, options = {}) =>
  fetch(`${origin}/api/backend/${path}`, options);
const headers = () => ({ "Content-Type": "application/json", Origin: origin });
async function login() {
  return request("login", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({
      email: "test@example.com",
      password: "password",
      device_name: "web",
    }),
  });
}
test("workspace renders the welcome layout", async () => {
  const r = await fetch(origin);
  const html = await r.text();
  assert.ok(html.includes("What’s on your mind?"));
  assert.ok(html.includes("New chat"));
  assert.ok(html.includes("Ask anything"));
});
test("protected requests reject missing cookies", async () => {
  assert.equal((await request("me")).status, 401);
});
test("cross-origin mutations are rejected", async () => {
  const r = await request("login", {
    method: "POST",
    headers: { ...headers(), Origin: "https://evil.example" },
    body: "{}",
  });
  assert.equal(r.status, 403);
});
test("token stays in an HttpOnly cookie and is removed from JSON", async () => {
  const r = await login();
  assert.equal(r.status, 200);
  assert.match(r.headers.get("set-cookie"), /HttpOnly/i);
  assert.match(r.headers.get("set-cookie"), /SameSite=lax/i);
  assert.match(r.headers.get("set-cookie"), /Secure/i);
  const data = await r.json();
  assert.equal(data.token, undefined);
  assert.equal(data.user.name, "Test User");
});
test("cookie authenticates upstream, and chat response contract is preserved", async () => {
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  const r = await request("chat", {
    method: "POST",
    headers: { ...headers(), Cookie: cookie },
    body: JSON.stringify({ model_id: 1, message: "Hi" }),
  });
  assert.equal(r.status, 200);
  assert.equal((await r.json()).message.content, "Fixture response");
  assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  assert.equal(JSON.parse(lastRequest.body).message, "Hi");
});
test("only numeric pagination reaches the upstream", async () => {
  await request("conversations?page=2&url=https://evil.example", {
    headers: { Cookie: "online_model_token=fixture-secret-token" },
  });
  assert.equal(lastRequest.url, "/api/v1/conversations?page=2");
});
test("arbitrary upstream paths and non-JSON mutations are blocked", async () => {
  assert.equal((await request("admin/users")).status, 404);
  assert.equal(
    (
      await request("login", {
        method: "POST",
        headers: { Origin: origin },
        body: "{}",
      })
    ).status,
    415,
  );
});
test("rate limit status and retry header are preserved", async () => {
  const r = await request("models", {
    headers: { Cookie: "online_model_token=fixture-secret-token" },
  });
  assert.equal(r.status, 429);
  assert.equal(r.headers.get("retry-after"), "25");
});
test("invalid upstream HTML is not exposed", async () => {
  const r = await request("tokens", {
    headers: { Cookie: "online_model_token=fixture-secret-token" },
  });
  assert.equal(r.status, 502);
  assert.ok(!(await r.text()).includes("<html>"));
});
test("logout removes the browser cookie", async () => {
  const r = await request("logout", {
    method: "POST",
    headers: {
      ...headers(),
      Cookie: "online_model_token=fixture-secret-token",
    },
    body: "{}",
  });
  assert.equal(r.status, 204);
  assert.match(r.headers.get("set-cookie"), /online_model_token=;/);
});

test("oversized JSON is rejected before forwarding", async () => {
  const r = await request("login", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({ email: "x".repeat(100_001) }),
  });
  assert.equal(r.status, 413);
});
