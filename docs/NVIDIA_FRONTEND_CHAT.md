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


## Automatic prompt routing

The default hosted **OpenMindAI** chat entrypoint now selects a server-side model
per request. NVIDIA model IDs and credentials remain private to Next.js; the
browser does not select a provider, model ID or upstream URL. The existing
Laravel chat begin/status/checkpoint/finish bridge is unchanged.

- General conversation runs on NVIDIA_FAST_MODEL (or the existing NVIDIA_MODEL).
- Coding, mathematical reasoning and research use NVIDIA_REASONING_MODEL.
- Visual interpretation prompts prefer NVIDIA_VISION_MODEL for text chat. Actual
  raw image/video inputs are **not** attached to the chat stream by this change:
  existing file processing still uses its separate private media workflow.
- Direct requests to create an image, spoken audio, short video, or PDF/DOCX
  are sent through the existing hosted media bridge. The output appears in the
  existing Library/inline preview rather than as a newly saved text chat turn.
- Ambiguous actionable prompts can be classified by NVIDIA_ROUTER_MODEL using a
  constrained JSON decision. Unknown outputs, timeouts and errors safely
  return to ordinary chat. Set NVIDIA_ROUTER_CLASSIFIER=false to disable the
  extra classifier API request. A simple request never needs that extra call.
- Uploaded text attachments, deep research and web search requests stay in the
  chat workflow. Chat model IDs are saved in existing response checkpoints
  and honored on resume. On an early provider error (before any streamed answer)
  the configured NVIDIA_MODEL is tried once as fallback. No switching mid-stream.

No Laravel migrations are required. The composer defaults to OpenMindAI Auto when hosted code models are enabled.
Users can explicitly choose Lite, Super or Ultra, but automatic generation
intent is still honored. The automatic router only uses models enabled by
Laravel's existing hosted code-model catalog. Other existing local models
remain available independently. The frontend's media-mode selector remains available for explicit
user control; it is no longer required for clear creation requests.

See frontend/tests/prompt-router.integration.test.mjs for routing regression
coverage. The recorded test fixtures do not prove NVIDIA account eligibility.
Before production rollout verify both Nemotron IDs against the account key,
especially rate limits and output handling. For PDF output in Bangla or other
non-Latin scripts, use DOCX until the PDF font renderer gains Unicode support.


### Chat-style image routing (Next.js only)

The same chat composer now accepts PNG/JPEG/WebP images by file selection,
drag-and-drop, or clipboard paste (up to 4 MB each, one per turn). The browser
shows a removable preview. The protected Next.js route validates the binary
signature against the claimed MIME type and uses an authenticated, fixed NVIDIA
vision model endpoint with a base64 data URL in a multimodal user message.
The model defaults to the server-side NVIDIA_VISION_MODEL for these turns,
regardless of a previously selected local text model. Nothing in the browser
can override upstream URLs, credentials, or model IDs.

Laravel continues to own sign-in, session validation, text conversations,
quotas, checkpoints, API usage accounting, and generated private media.
The original image bytes **never pass to Laravel**, are not stored in history,
and are stripped from checkpoints. A failed image turn cannot be resumed without
reattachment, and image requests never fall back to a text-only model.
Follow-up turns contain previously saved *text* only; users must reattach an
image when further visual inspection is needed. This intentional transient
behavior preserves the existing Laravel database/storage design.

Uploaded PDFs/DOCX and audio still use their existing file-processing and
transcription paths where configured; image editing is not supported by the
current text-to-image-only generator. The existing PDF writer cannot embed
Bengali/other non-Latin glyphs. Use DOCX for those languages until a Unicode
PDF font/shaping engine is added.

To verify, run npm run typecheck and npm run test:integration in frontend,
then test image input and hosted-generation features with an authorized
NVIDIA key on the deployed host. Automated fixtures are not proof of actual
model access or provider limits.

OpenMindAI Auto is a frontend-only selection; it is not a fourth NVIDIA endpoint or a new Laravel database record. The existing three provider aliases remain unchanged.
