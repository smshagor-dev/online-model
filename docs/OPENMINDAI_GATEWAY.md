# OpenMindAI hosted coding API

## Server setup

Run Laravel migrations. Set `NVIDIA_API_KEY` in the Laravel server's private `.env` (not in Git or `NEXT_PUBLIC_`). The Next.js hosted chat also needs its own private `NVIDIA_API_KEY` if its original hosted-chat path remains in use. Both servers can use the same key, but keep it server-side.

In Admin → Code agents enable OpenMindAI Super, Ultra or Lite. Their NVIDIA model IDs are stored in the database. Model availability and provider quota are controlled by NVIDIA and must be tested with your key.

The public API base URL is `{APP_URL}/api/v1/openmindai`, for example `https://models.example.com/api/v1/openmindai`. Replace the example hostname with your deployed Laravel APP_URL.

- `GET /models`
- `POST /chat/completions` (OpenAI-compatible chat-completions shape)
- `Authorization: Bearer <developer-api-key>`

Each user can create up to ten developer keys (90-day expiry) in Account → Developer API; a key is visible only when created, and can be revoked.

Example:

```bash
curl "$BASE_URL/chat/completions" \
  -H "Authorization: Bearer $OPENMINDAI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"openmindai-lite","messages":[{"role":"user","content":"Write a Python function to sum a list"}],"stream":false}'
```

Available aliases are `openmindai-super`, `openmindai-ultra`, and `openmindai-lite`. Send up to 40 messages with `system`, `user` or `assistant` roles. Maximum output is 8192 tokens. `stream: true` streams SSE completion chunks.

These developer keys are **secrets**, not public/publishable frontend keys. Keep keys on trusted servers. The gateway enforces each model's monthly credit allocation and the app owner's monthly allocation; usage counts successful calls as one application unit, not NVIDIA billing tokens.

## First-party applications without per-user keys

In Admin → Code agents, register an owned app and copy its one-time **App ID** and **signing secret** into that app's *server environment*. There is no secure anonymous client-side API without a secret or user session. First-party calls use a server-held signing secret rather than individual user API keys.

Use `{APP_URL}/api/v1/first-party/models` and `{APP_URL}/api/v1/first-party/chat/completions`. Every request must have:

- `X-OpenMindAI-App`: App ID
- `X-OpenMindAI-Timestamp`: Unix seconds (within five minutes)
- `X-OpenMindAI-Nonce`: unique 32–64 lowercase hex
- `X-OpenMindAI-Signature`: lowercase hex HMAC-SHA256 with the signing secret

Construct the canonical input as `timestamp + "\n" + nonce + "\n" + uppercase_method + "\n" + "/" + relative_path + "\n" + sha256(raw_request_body)` and sign the resulting UTF-8 bytes using the secret as a UTF-8 HMAC key. For example, the path is `/api/v1/first-party/chat/completions`. Requests cannot be replayed and are rate-limited per app. Registered app usage is charged to its administrative owner, respecting the same credit limits.

**Security:** First-party secrets must stay on a server or trusted backend, never in a web/mobile binary or JavaScript bundle. Public browser users should use the existing authenticated site and Next.js proxy instead.

**Scope:** The gateway currently exposes hosted code-model listing and chat completion. Image, speech, video and document workflows remain on their existing authenticated hosted-media routes; they have not been added as developer-key endpoints here.


## NVIDIA Build free-endpoint catalog (2026-10-21)

The NVIDIA Build catalog advertised 42 free endpoint models on October 21, 2026. The migration `2026_10_21_000006_nvidia_free_model_catalog.php` seeds them into the existing `hosted_code_agents` database table. The three original OpenMindAI chat aliases remain enabled and ready; the other 39 are disabled and not marked API-ready until an administrator has checked the exact NVIDIA model identifier, endpoint path, request schema and account entitlement.

Admin → NVIDIA models (`/admin/nvidia-models`) displays all 42, with search, categories, editable display labels, NVIDIA model IDs, JSON API paths, whether the endpoint expects a model field, readiness and visibility. This is a database-backed configuration, not a .env list. Changing availability immediately affects the frontend catalog; no Next.js code change is needed for model discovery.

Authenticated developer-key endpoints:

- `GET /api/v1/openmindai/catalog` lists **enabled** models, including category, readiness, supported operation and relative API path when ready.
- `GET /api/v1/openmindai/models` lists only enabled, API-ready chat models.
- `POST /api/v1/openmindai/chat/completions` retains the existing streaming and non-streaming chat gateway.
- `POST /api/v1/openmindai/models/{slug}/invoke` routes admin-verified **non-chat JSON** models to their configured NVIDIA `/v1/` API path, provided the service uses the `integrate.api.nvidia.com` origin.

Invoke request body:

```json
{"input":{"input":"Text to embed"}}
```

The backend removes any caller-supplied `model` or `stream`, optionally inserts the server-side model ID and returns `{"model":"alias","output":{...provider JSON...}}`. It enforces the same user and model monthly limits and records successful requests. API keys are never sent to arbitrary user-selected hosts. HMAC-protected first-party equivalents exist under `/api/v1/first-party/catalog` and `/api/v1/first-party/models/{slug}/invoke`.

**Important:** NVIDIA's free endpoint badge does not guarantee unrestricted or commercial availability. Models have different schemas and not all accept JSON-only invocation. No binary uploads, streaming audio, asynchronous media jobs, signed URLs or unsupported endpoint families are exposed through this gateway. These require their own reviewed integrations. Do not enable an untested specialized model solely because it appears in the catalog.
