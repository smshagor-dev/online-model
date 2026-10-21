# OpenMindAI Laravel-only inference gateway

Live backend: https://cloud.openmindai.org/api/v1

The browser calls the same-origin Next.js API. Next.js forwards authorized
requests to Laravel. **Only Laravel makes NVIDIA inference requests** and holds
the NVIDIA API key. Laravel also manages model availability, accounts, usage,
private media output, and database chat history.

## Deployment configuration

On the Laravel host (cloud.openmindai.org):
- NVIDIA_API_KEY = your actual provider credential, never exposed to browser.
- FRONTEND_CHAT_BRIDGE_KEY = secure random 32+ character value.
- FRONTEND_URL = actual Next.js site origin.
- Run the Laravel migrations, clear configuration cache, restart PHP workers.
- Enable PHP curl, openssl, mbstring, fileinfo and zip extensions.

On the Next.js server:
- LARAVEL_API_URL = https://cloud.openmindai.org/api/v1
- CHAT_PROVIDER = nvidia
- FRONTEND_CHAT_BRIDGE_KEY = same value as Laravel.
- Optional non-secret model-routing settings: NVIDIA_MODEL,
  NVIDIA_FAST_MODEL, NVIDIA_REASONING_MODEL, NVIDIA_VISION_MODEL,
  NVIDIA_ROUTER_MODEL.
- **Do not put NVIDIA_API_KEY in Next.js environment variables.**

The Laravel database catalog controls permitted chat models. Admin must
enable them; Next.js cannot select a disabled provider model.

## Connections

Browser -> Next.js (/api/backend/*) -> Laravel (/api/v1/*) -> NVIDIA.

Chat and Auto routing use existing Laravel chat begin/status/checkpoint/finish
leases for context and history. Every actual chat classifier, completion,
research-planning or memory-extraction request goes to Laravel's new internal
POST /api/v1/provider/invoke endpoint.

Image, speech-to-text, text-to-speech, document drafting, and video likewise
call the internal provider/invoke route. Their existing Laravel media
begin/complete/fail endpoints still enforce per-user ownership and quotas,
save private files, and record completion. The browser never selects a
provider host, never sees the NVIDIA token, and never gets raw upstream errors.

Provider/invoke requires an authenticated user plus X-Chat-Bridge-Key. It is
not reachable through the browser's Next.js proxy allowlist. Laravel accepts
only these fixed provider service types: chat, image, transcription, speech,
video. Model selection for chat is checked against enabled Laravel models;
image and video parameters are fixed; speech voices are server-whitelisted.
Next.js also refuses new unknown NVIDIA URLs rather than connecting directly.

The Laravel capability response advertises hosted_inference_enabled only
when the backend has both a provider key and shared bridge secret; Next.js
must not advertise hosted media on frontend configuration alone.

## Hosting and limitations

The provider response returns through Laravel to Next.js. Generated files
then travel back to Laravel's authenticated private media storage bridge.
Allow ingress payloads of at least 32 MB and enough PHP memory for base64
media and large JSON responses. Provider timeouts may reach 120 seconds for
chat and image and 190 seconds for video. Reverse proxies and PHP runtime
limits must permit those calls. Short-lived cPanel PHP processes may interrupt
long video generation: use a durable queue or async worker before calling
that production-ready.

The live cloud.openmindai.org hostname was not resolvable from the development
environment while preparing this change. Source-level changes and mocked
responses do not establish that the live deployment is updated or healthy.

## Functional acceptance checklist

1. Deploy compatible Laravel and Next.js main revisions. Restart both with
   the correct environment configuration.
2. Log in, check capabilities and confirm API keys are absent from browser
   source and network requests.
3. Exercise chat, Auto routing, Deep Research and image understanding.
4. Generate and save image, speech transcription, generated WAV, DOCX/PDF and
   video; reopen, preview, download and delete each privately.
5. Check disabled models, expired login, wrong bridge secret, timeout,
   quota and ownership. Confirm outbound NVIDIA requests originate from
   cloud.openmindai.org, not the Next.js host.
