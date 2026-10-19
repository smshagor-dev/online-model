# Openmindai

Laravel 12 / PHP 8.2 backend for Hugging Face model storage and self-hosted chat. Optional NVIDIA Nemotron chat connects directly from the Next.js server while Laravel stores chat history.

## Web frontend

The `frontend/` folder contains the Next.js 16.4.2 chat application. See [frontend setup](frontend/README.md) for local development, secure Laravel integration and Node/cPanel deployment.

See the [current feature coverage](docs/FEATURE_COVERAGE.md) for the expanded Openmindai workspace and the features that are not implemented.

## Features

- Openmindai branding and supplied logo across web and administration.
- SSE streaming, stop, temporary chat, branches for editing/regeneration and partial-response persistence.
- Projects, explicit memories, custom instructions, canvas, text/code file library and optional own-server web search.
- Server-side history search, rename/pin/archive, snapshot sharing/revocation, feedback and data export.
- Versioned REST API, Sanctum bearer tokens with 30-day expiry and device revocation.
- Registration, email verification, password reset, profile updates and account deletion.
- Authenticator TOTP setup/confirmation, replay protection and hashed single-use recovery codes.
- Login, sensitive-action, chat-per-minute and daily request limits; one concurrent chat per user.
- Private conversation history, bounded context, upstream timeouts and atomic history writes.
- Session-based administrator panel: dashboard, user search/detail, suspension and device revocation.
- Hugging Face GGUF discovery by family, repository file browser and license acknowledgement.
- Queued downloads pinned to a commit, expected byte size and SHA-256; private disk storage, quota limits and failed-download cleanup.
- Self-hosted llama.cpp-compatible inference endpoint, with explicit model ID mapping.

Only downloadable Hugging Face weights are supported. Nemotron, Qwen3, Llama, Mistral, Gemma and DeepSeek are discoverable families, subject to repository availability, licenses and GGUF/runtime compatibility. Proprietary ChatGPT and Gemini weights are not included. Gemma is a separate downloadable model family.

## Install

Requires PHP 8.2+ (64-bit), Composer 2, cURL, mbstring, OpenSSL, XML, PDO MySQL or SQLite, writable private storage and a queue worker. Enable `pcntl` for worker timeouts where hosting supports it.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
# Configure database, SMTP, APP_URL and your own inference endpoint in .env.
php artisan migrate --force
php artisan admin:create admin@example.com "Administrator"
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Open `/admin/login`. The admin command asks for a password interactively; there is no default credential. Enable administrator 2FA through the account API after signing in with a bearer token.

For local development, use SQLite, `APP_ENV=local`, `SESSION_SECURE_COOKIE=false`, and `php artisan serve`. Keep secure cookies enabled on HTTPS deployments.

## Download and serve a model

1. In Admin → Models, search a model family or paste a Hugging Face repository ID.
2. Browse GGUF files, review the model card/license, and import a file that fits your storage/RAM/VRAM limits.
3. Click **Download model**. Run the queue worker; refresh the page to see status. A file is ready only after size and checksum verification.
4. Mount/copy `storage/app/models/{id}.gguf` to your own inference machine. For a split GGUF, all shards must be imported/downloaded and transferred before loading it. Other architectures and safetensors are not supported by this GGUF workflow.
5. Start a compatible runtime, for example:

```bash
llama-server -m /private/models/1.gguf --alias local-model --host 127.0.0.1 --port 8080
```

6. Set `INFERENCE_URL=http://127.0.0.1:8080/v1`. Map the downloaded model to runtime ID `local-model` in Admin → Models, then enable it.

For a separate inference host, use a private network or HTTPS with `INFERENCE_KEY`, and configure the runtime with the matching access key. The example above binds to localhost. Runtime provisioning, hardware and transferring files to a separate host are deployment responsibilities. Downloading weights does not start inference or load models automatically. One endpoint is configured; its runtime determines whether multiple models can be loaded/routed concurrently.

See [cPanel deployment](docs/CPANEL.md), [API reference](docs/API.md), and [validation](docs/VALIDATION.md).

## Development checks

```bash
composer install
composer validate --strict
vendor/bin/pint --test
php artisan test
composer audit
```

The lockfile resolves dependencies against PHP 8.2. CI tests PHP 8.2 and 8.3. Do not commit `.env`, tokens, downloaded weights, user data, or `vendor/`.

## Self-hosted media studio

Queued image/vision/audio/speech/Wan-video integrations and PDF/DOCX text extraction, with private DB task records and file storage. Requires separate compatible HF snapshots and a private runtime. See [runtime setup](runtime/media/README.md) and [feature coverage](docs/FEATURE_COVERAGE.md). Media inference uses only the configured self-hosted runtime. Real GPU inference remains deployment-specific validation.

## User settings

Profile photo/name, confirmed email changes, password and 2FA/device controls, persisted appearance/accessibility/notification preferences, response personalization, memory management, archived chats and data deletion/export are available from Settings. Run `php artisan migrate --force` after updating. Email changes need SMTP and `FRONTEND_URL`. See [feature coverage](docs/FEATURE_COVERAGE.md) for behavior and deployment limits.

## Workspace tools

Tools & library includes assistant builder, scheduled tasks, bounded sourced research, unified library, voice input and an optional reviewed Python runner. Composer supports file drop/preview and inline media previews; Canvas supports split preview and version comparison; chats have a saved-branch navigator. Run migrations and the tasks queue worker/scheduler after updating. See [feature coverage](docs/FEATURE_COVERAGE.md) and [runtime deployment](runtime/media/README.md) for the exact supported scope and remaining live-host/device validation.

## NVIDIA hosted chat

Interactive Nemotron chat can connect directly from the Next.js server to NVIDIA while Laravel stores user/assistant messages and owns authentication/context. See [NVIDIA frontend setup](docs/NVIDIA_FRONTEND_CHAT.md). Keys are server-only; no live NVIDIA account/key is bundled. Existing local models remain available.

## Web search and deep research

Chat supports search snippets or a deeper report with multiple queries, diverse sources, public-page reading, freshness filters and source cards. NVIDIA reports keep inference on the Next.js server and chat storage in Laravel. Configure your own SearXNG service; see [search and research setup](docs/WEB_RESEARCH.md) for limits, privacy and background research requirements.
