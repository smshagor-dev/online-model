# OpenMindAI hosted chat — Laravel gateway

OpenMindAI chat runs through the backend at
https://cloud.openmindai.org/api/v1.

**Current network path:** Browser -> Next.js same-origin proxy ->
authenticated Laravel gateway -> NVIDIA hosted API.

There is no direct Next.js -> NVIDIA inference request. The provider key
is held exclusively on Laravel, never on Next.js or in browser code.

See [Laravel provider gateway](LARAVEL_PROVIDER_GATEWAY.md) for exact
deployment settings, protected request paths, server and PHP requirements,
and the functional acceptance checklist.

## Configuration

Next.js server:
- LARAVEL_API_URL=https://cloud.openmindai.org/api/v1
- FRONTEND_URL=your actual frontend origin
- CHAT_PROVIDER=nvidia
- FRONTEND_CHAT_BRIDGE_KEY=the same secure 32+ character value as Laravel
- NVIDIA_MODEL and optional NVIDIA_FAST_MODEL, NVIDIA_REASONING_MODEL,
  NVIDIA_ROUTER_MODEL, NVIDIA_VISION_MODEL selectors are model identifiers,
  not provider keys.

Laravel server:
- NVIDIA_API_KEY=your private NVIDIA credential
- FRONTEND_CHAT_BRIDGE_KEY=the matching secure bridge key
- FRONTEND_URL=the public frontend origin

Never configure NVIDIA_API_KEY or a secret in a NEXT_PUBLIC_ variable.
Clear Laravel config/route caches and restart PHP workers after deployment.
Restart Next.js after its environment values are updated.

## Chat routing and persistence

Next.js still orchestrates conversation UI, streaming event parsing, user
preferences, project/attachment context, Auto model classification, optional
Web research and Deep research planning. Every corresponding NVIDIA inference
call goes through Laravel's protected provider/invoke endpoint, which checks
the session and bridge secret and independently validates model availability
and the fixed upstream endpoint. No browser-specified provider URL is used.

Saved chat continues to use the Laravel chat/frontend/begin, status,
checkpoint, tool, memory and finish endpoints for conversation ownership,
model usage accounting, cancellation, recovery and history. Temporary chat
does not persist conversations, but its text is still transmitted to
the chosen hosted inference service, as disclosed in the user interface.

Image-related prompts can route to hosted media endpoints. All image,
speech, document and video work uses the same backend-only provider key
and separate authenticated media task storage. Models unavailable in
Laravel's admin catalog cannot be silently enabled in Next.js.

## Streaming

Laravel streams sanitized hosted chat SSE responses back to Next.js.
The frontend processes streamed deltas, retry/resume boundaries, end events
and cancellation while Laravel remains the only service contacting NVIDIA.
For reliable delivery, configure the Laravel reverse proxy and PHP stack
with sufficient SSE timeouts and disabled response buffering, plus
sufficient PHP workers for cancellation/status requests.

A 30-second shared hosting process timeout cannot be assumed to support
110-second hosted chat or 190-second video requests. Use a suitable
long-running PHP worker/proxy or durable queue for slow generation.

## Verification

Tests with fake provider responses check transport routing, ownership,
private bridge authorization and basic streamed response behavior. They do
not prove the deployed backend can resolve NVIDIA endpoints or that a
particular account has access to a hosted model.

The cloud.openmindai.org hostname could not be resolved from the development
environment when these changes were prepared. Deploy Laravel and Next.js
from compatible commits and verify the end-to-end flows on the live host
before claiming production readiness.
