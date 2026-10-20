# Openmindai Web

Next.js 16.4.2 / React 19.3.1 / TypeScript. A responsive chat workspace inspired by the familiar ChatGPT layout, with Openmindai branding.

## Run locally

Start the Laravel backend from the repository root with `php artisan serve`, after configuring its database and SMTP.

```bash
cd frontend
npm ci
cp .env.example .env.local
npm run dev
```

Open `http://localhost:3000`. Set `LARAVEL_API_URL` to your Laravel `/api/v1` address and `FRONTEND_URL` to the exact frontend origin (including port locally). These variables are server-only. Do not prefix them with `NEXT_PUBLIC_`.

For matching password-reset pages, set Laravel's separate `FRONTEND_URL` environment variable to the frontend origin, then refresh Laravel's config cache. Verification links continue to use Laravel's signed email-verification route.

## Features

- Dark chat workspace, collapsible conversation sidebar, mobile navigation, model picker and prompt starters.
- Sign in, registration, verification-email resend, forgotten/reset password and authenticator/recovery code sign-in.
- Saved chat history from Laravel, paginated conversations/messages, loaded-history search and confirmed deletion.
- Markdown responses, code blocks, copy responses, auto-growing composer, IME-safe Enter to send and Ctrl/Cmd+K for a new chat.
- Account settings, password change, TOTP QR/setup/confirmation, recovery code replacement/download, and device revocation.
- HttpOnly cookie authentication through a Next.js backend-for-frontend; bearer tokens never enter browser JavaScript or localStorage.
- First-party bundled fonts, lazily loaded account/Markdown UI and clear empty/error/loading states.

Only enabled models from the existing Hugging Face backend appear. The frontend uses genuine inference SSE, with partial/failure status saved in history. Text/code attachments and on-device read-aloud are supported. Image/audio processing, realtime voice, model-provider fallback and code execution are absent. See the feature-coverage document before assuming ChatGPT feature parity.

## Production

```bash
npm ci
npm run build
npm start
```

Requires Node.js 20.9+ (Node 24 recommended) and a Node-capable host. On cPanel, enable a supported Node application/process manager for `frontend` and serve it behind HTTPS. A PHP-only cPanel plan cannot run this Next.js server. Laravel may remain on a PHP subdomain, with `LARAVEL_API_URL` pointing to it over a private network or HTTPS. It need not allow browser CORS because only the Next.js server contacts it.

Set production `FRONTEND_URL` to your public HTTPS origin; login cookies are Secure in production. Keep your reverse proxy's forwarded host/protocol configuration correct. For a Passenger/custom-server setup, `node server.cjs` is provided; use the startup-file format and Node path required by your provider. Standard hosts can use `npm start` directly.

This app uses dynamic server routes. Do not use `output: 'export'` or upload only static assets. Do not expose `.env.local`, `.next/server`, source files or server credentials through static file hosting.

### Client IP and rate limits behind the proxy

By default no client-supplied forwarding headers are trusted. That is safe for direct development, but Laravel will see the Next.js server IP for public authentication limits. For a production multi-user deployment:

1. Put Next.js behind an ingress that replaces or appends the actual client IP to `X-Forwarded-For`, and prevent direct untrusted access around that ingress.
2. Set Next.js `TRUST_PROXY_HEADERS=true` only with that trusted ingress.
3. Set Laravel `TRUSTED_PROXIES` to the exact private IPs of the Next.js proxy and any trusted ingress IPs present in that chain. Do not use `*`.
4. Refresh Laravel's config cache and verify that authentication limits apply to the intended client address. Chat/account limits remain keyed by authenticated user.

## Checks

```bash
npm run typecheck
npm run build
npm run test:integration
npm audit --omit=dev
```

The integration suite starts a production Next.js process and an isolated fake Laravel upstream, verifies rendered HTML and tests cookie security, origin enforcement, API forwarding, route restrictions, pagination, error handling, logout and oversized JSON rejection. It does not launch a browser or perform real model inference.

## Expanded workspace

Openmindai now uses real SSE streaming and database-backed projects, explicit memory/custom instructions, full-history search, pinned/archived chats, editable/regenerated branches, share snapshots, text/code file library, feedback, canvas and data export. See [feature coverage](../docs/FEATURE_COVERAGE.md) for supported and absent capabilities. Existing deployments must run the new Laravel migrations and rebuild this frontend. The frontend auth cookie was renamed to `openmindai_token`; sign in again after upgrading.

Set Laravel `APP_NAME=Openmindai`. Optional `SEARXNG_SEARCH_URL` must point to your own SearXNG `/search` endpoint with JSON output enabled. The web-search control appears only when that backend capability is configured. There is no commercial AI fallback. Production SSE needs proxy buffering disabled and concurrent PHP workers for stop requests. On Unix, a local PHP development server can use `PHP_CLI_SERVER_WORKERS=4`; a single-worker server cannot process a cancellation request until its stream finishes.

## NVIDIA Nemotron chat

See [NVIDIA setup](../docs/NVIDIA_FRONTEND_CHAT.md) for direct Next.js server-to-NVIDIA chat with Laravel message persistence. This is an explicit chat provider selection. Keep the API key in server environment variables. Scheduled tools and media continue to use the self-hosted runtime.

## Teams, connectors and live conversation

Open the Workspace dialog and select Teams, Connectors or Live. Team discussion/document sharing has role checks and revision conflict handling. Connectors use the administrator's MCP catalogue and per-call approval, with credentials held by Laravel. Live requires the private warm transcription/vision service; it supports automatic microphone turns, streamed chat answers, browser speech and optional sampled camera/screen context.

See [deployment and limitations](../docs/COLLABORATION_AND_LIVE.md). OAuth connectors and native duplex audio/video model streaming are not included. Billing remains excluded.
