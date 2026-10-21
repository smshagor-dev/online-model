# Hosted image generation

Image generation calls NVIDIA from the Next.js server, using the private
NVIDIA_API_KEY, while Laravel authenticates users, enforces quotas and
stores the generated image. No image model download or GPU
runtime is required for this path. The model/provider name is not displayed in
the customer-facing interface.

## Deployment

On the Next.js server, set:
- FRONTEND_CHAT_BRIDGE_KEY to the same 32+ character value as Laravel.
- LARAVEL_API_URL=https://cloud.openmindai.org/api/v1 and FRONTEND_URL.
- Set NVIDIA_API_KEY in the Next.js **server environment**, never NEXT_PUBLIC_.

On Laravel, set FRONTEND_CHAT_BRIDGE_KEY to the same secret as Next.js.
A Laravel-side NVIDIA key is optional and unrelated to this hosted image flow. Run
php artisan migrate --force to ensure media_tasks and media_daily_usage exist,
then run php artisan config:cache. The normal media GPU runtime
(MEDIA_RUNTIME_URL, MEDIA_RUNTIME_KEY, MEDIA_ENABLED_KINDS) is not needed
for hosted image creation; it remains necessary for other self-hosted media
kinds. Keep the Laravel scheduler running so interrupted tasks are eventually
marked failed.

Allow JSON image uploads/responses up to 28 MB on Next.js-to-Laravel ingress
(PHP post_max_size at least 32M, adequate memory_limit, proxy request limits);
generated image binaries are limited to 20 MB and 16 megapixels.
Ensure the Next.js host can reach Laravel and Laravel can reach the fixed provider HTTPS endpoint.
Provider calls can take up to 110 seconds. Configure host request timeouts
accordingly; serverless platforms with short execution limits may require
moving provider work to a durable worker. The request is synchronous, not a
guaranteed background job; an interrupted request can remain running until
the scheduler cleans it up.

## Request path

1. The signed-in browser requests GET /api/backend/capabilities. Next.js
   advertises image creation only when its API key and shared bridge secret are
   configured. Other media capabilities still depend on Laravel configuration.
2. Create image from Media studio or Chat's image mode.
3. Browser POSTs a kind=image and prompt JSON object to
   /api/backend/media/image. Same-origin checks and the HttpOnly auth cookie
   are enforced by the existing Next.js proxy.
4. Next.js calls authenticated POST /api/v1/media/hosted/begin with the shared
   X-Chat-Bridge-Key. Laravel checks signed-in ownership, daily image quota,
   active task concurrency and private storage quota, then creates a running
   task. Requests are rejected before contacting the inference provider if
   the user lacks quota.
5. Next.js invokes the fixed NVIDIA image API from the server using its
   server-only credential; Laravel is not the inference transport. The response must include a successful image artifact. The response JSON
   is read with a hard 28 MB streamed byte limit even when Content-Length is
   missing or incorrect. Invalid HTML or malformed JSON fails safely.
   Filtered outputs and rate limits are not falsely recorded as successful generations.
6. Next.js verifies base64 and PNG/JPEG magic bytes, then sends the artifact
   through authenticated POST /api/v1/media/{id}/hosted/finish with the bridge
   secret. Laravel checks MIME, image dimensions and ownership before writing
   the file to private storage. On failure Next.js attempts
   /media/{id}/hosted/fail.
7. Image previews, task history, private downloads and deletion reuse the
   existing Media studio, chat cards and Library components.

The API key and bridge secret must not appear in browser payloads, image
metadata, error strings or source maps.

## Verification

- Run: cd frontend && npm ci && npm run typecheck && npm run build
- Run: cd frontend && npm run test:integration
- Run: php artisan test --filter=MediaTest
- With a configured account and actual provider access, sign in, generate an
  ordinary prompt, verify a nonempty PNG/JPEG, reopen it from Library,
  download and delete it. Check quotas, rate limits and provider refusal.
  Confirm browser network traffic never exposes an API key.
- Test with MEDIA_RUNTIME_URL unset to confirm hosted image creation does not
  require a local GPU model.

Automated tests use provider fixtures, not billed live generation.
Repository checks alone do not prove the API key has model permission, live
inference succeeds, or the deployment supports long HTTP requests.

Reference: https://docs.api.nvidia.com/nim/reference/black-forest-labs-flux_1-schnell-infer
