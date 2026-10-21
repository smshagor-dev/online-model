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
