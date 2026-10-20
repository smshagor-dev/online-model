# NVIDIA Nemotron chat from Next.js

The web client sends chat to its same-origin Next.js route. The Next.js **server** connects directly to NVIDIA's hosted chat-completions API; Laravel does not make the NVIDIA inference request. The API key is never included in browser JavaScript, public environment variables, model-list JSON or SSE events.

Flow: browser → Next.js → Laravel prepare/context + initial message records → Next.js → NVIDIA SSE → browser; Next.js finalizes the answer/status through authenticated Laravel persistence. Laravel remains responsible for login, ownership, rate limits, project/assistant/file context and history. The frontend exposes a virtual NVIDIA model alongside existing local models. Selecting NVIDIA uses this flow; local models continue using the local inference runtime. No silent provider fallback is performed.

## Configuration

Next.js server `.env.local` (or hosting environment):

```dotenv
LARAVEL_API_URL=https://backend.example.com/api/v1
FRONTEND_URL=https://chat.example.com
CHAT_PROVIDER=nvidia
NVIDIA_API_KEY=YOUR_PRIVATE_NVIDIA_API_KEY
NVIDIA_MODEL=nvidia/nemotron-3.5-lightning-30b-a3b
FRONTEND_CHAT_BRIDGE_KEY=SAME_RANDOM_SECRET_AT_LEAST_32_CHARACTERS
```

Laravel `.env`:

```dotenv
FRONTEND_CHAT_BRIDGE_KEY=SAME_RANDOM_SECRET_AT_LEAST_32_CHARACTERS
```

Generate your own random secret, for example `openssl rand -hex 32`. Do not commit either secret and do not use `NEXT_PUBLIC_` prefixes. Set `CHAT_PROVIDER=local` to hide the hosted model. NVIDIA account/API access and provider limits are external deployment requirements; no key or account is bundled. The shipped example targets Nemotron 3.5 Lightning. The previous `nvidia/nemotron-3-nano-30b-a3b` endpoint was retired on September 1, 2026 and returns HTTP 410; existing deployments must update `NVIDIA_MODEL` and restart Next.js. Its thinking mode is explicitly disabled for normal answer streaming. For another model ID, check that model's API parameters and account access before changing the setting.

After deployment, clear/rebuild Laravel config and route caches, restart the Next.js server and run the existing scheduler. Configure HTTPS and SSE response buffering/timeouts on the frontend host. NVIDIA outbound network access is required only from the Next.js host; the backend needs no NVIDIA key and no GPU for this chat path. The frontend host still needs a Node.js runtime; static Next.js export cannot run the connection.

## Persistence and cancellation

Private bridge endpoints `/api/v1/chat/frontend/begin`, `/status` and `/finish` require both the user's Sanctum token and the shared server secret. They are not allowlisted through the browser proxy. A short-lived opaque lease ties finalization to the signed-in owner. A per-user lock prevents concurrent local/hosted chat generation. Finalization is idempotent; replaying it cannot replace an already saved answer. Deleting a chat during inference does not recreate deleted messages.

Saved chats insert the user question and a generating assistant record before NVIDIA is contacted. Finish stores complete, failed or cancelled text, including partial output. Stop polls the existing backend cancellation flag; browser disconnect aborts NVIDIA and still attempts independent finalization. The frontend retries finalization three times and does not report `saved:true` unless the backend acknowledges it. A process crash or prolonged backend outage can still lose unsaved deltas; stale generating records are marked failed by the scheduler. There is no distributed exactly-once delivery guarantee.

Temporary mode does not create conversation/message records and omits saved personal preferences/memories. Its bounded history is supplied explicitly from the browser. A short-lived operational lease is cached but does not contain prompt or answer text. Temporary mode does not override NVIDIA's own service/data policies. The chat composer discloses hosted processing, including included context/files, before sending.

NVIDIA failures/rate limits surface as errors and saved failed turns; secrets and raw provider error bodies are not forwarded. Pending (`202`) responses are surfaced as unavailable rather than automatically reissuing a potentially billable request. The old 262,144-byte answer cap and 4096-token request cap are removed for hosted answers. Each provider call has a 110-second network timeout; output-token boundaries trigger continuation. Provider context/output constraints, hosting duration and physical database limits still apply. Deep research uses additional model planning and backend evidence calls until its completion decision; see [research policy](WEB_RESEARCH.md). Reasoning-only deltas are not displayed or stored as answer text. Background tasks/research and media generation retain their existing self-hosted runtimes. Interactive NVIDIA chat also supports Web search and Deep research through backend evidence collection; see [research setup](WEB_RESEARCH.md).

## Verification

Automated tests cover direct NVIDIA request construction with a provider fixture, token/context redaction from browser events, failed/truncated streams, persistence retries, cancellation, bridge ownership, idempotent saves, temporary-chat privacy and deletion. A minimal live streaming request to Nemotron 3.5 Lightning returned HTTP 200 and answer text during the model migration. Validate with your own account/key and hosting timeouts before production use.

Official references:
- https://build.nvidia.com/nvidia/nemotron-3.5-lightning-30b-a3b/build
- https://build.nvidia.com/nvidia/nemotron-3.5-lightning-30b-a3b/modelcard
