# Private Hugging Face media runtime

This service runs downloaded weights on your hardware. It never calls an inference provider. Laravel and Next.js may run on cPanel; this Python service normally runs on a separate GPU server. A general text GGUF does not become a vision, speech or diffusion model. Each adapter needs a compatible model. Models are **not bundled or downloaded in this development environment**.

| Kind | Adapter | Example architecture / constraint |
| --- | --- | --- |
| image | Diffusers AutoPipelineForText2Image | SDXL-compatible safetensors snapshot; 512×512, 25 steps |
| vision | Transformers image-text-to-text | Qwen3-VL-Instruct compatible with installed Transformers; one image, 1024 output tokens |
| transcription | Transformers automatic-speech-recognition | Whisper-compatible safetensors; local FFmpeg; maximum 120 seconds |
| speech | Transformers text-to-audio | VITS/MMS-compatible safetensors; maximum 1000 characters; language/voice comes from the selected model |
| video | Diffusers WanPipeline | Wan text-to-video Diffusers snapshot; 832×480, 17 frames at 8 FPS; not arbitrary video architectures |
| document | pypdf / standard-library DOCX XML | Text extraction, not OCR; PDF ≤100 pages, no encrypted PDF; output ≤100 KB |

Compatibility and licenses must be checked for each exact model revision. The downloader excludes pickle checkpoints and custom Python. `use_safetensors=True`, `trust_remote_code=False`, offline HF flags and a local snapshot path are enforced for model loading. Not all HF repositories satisfy these constraints. For example, the Meta MMS model card describes a noncommercial license; do not assume every downloadable model permits your intended use.

## Provision on the runtime host

Install Python 3.11+, FFmpeg/ffprobe and the appropriate PyTorch build for your GPU/driver. Use a dedicated unprivileged account and a dedicated virtual environment:

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
# Obtain the full revision SHA from the model's HF Files/History page.
python download.py OWNER/REPOSITORY FULL_40_CHARACTER_COMMIT_SHA /srv/openmindai/models/vision --accept-license
```

The provisioning command downloads from Hugging Face only, pins the revision, and writes provenance metadata. It does not execute repository code. Use a separate empty directory per model. Download enough disk space for the full snapshot; model files can be tens of gigabytes. The existing Laravel admin GGUF download flow is for the chat runtime; media snapshots use this provisioning command, not that GGUF form.

Set only the paths for engines actually provisioned and tested. Generate a long random shared secret; do not commit it:

```bash
export MEDIA_RUNTIME_KEY='REPLACE_WITH_RANDOM_SECRET_AT_LEAST_32_CHARACTERS'
export MEDIA_VISION_PATH=/srv/openmindai/models/vision
# Optional, separately provisioned:
# MEDIA_IMAGE_PATH, MEDIA_TRANSCRIPTION_PATH, MEDIA_SPEECH_PATH, MEDIA_VIDEO_PATH
export MEDIA_DOCUMENT_ENABLED=true
uvicorn server:app --host 127.0.0.1 --port 8090 --workers 1
```

Bind to a private interface or put authenticated TLS ingress in front when Laravel is on another host. The service must not be publicly exposed without private networking/firewall rules. Set Laravel PHP `post_max_size` to at least `12M` and `memory_limit` to at least `256M` for base64 uploads. Configure reverse-proxy request size 11 MB and timeout at least 850 seconds. Disable request-body logging. Limit the service's CPU/RAM/GPU and disable outbound networking during inference. Model loading and inference are serialized, one request at a time; engines unload between requests to avoid keeping every model in VRAM. This trades latency for bounded residency. Some models still exceed your available memory. `/health` requires the shared bearer secret and lists configured kinds; it is not a GPU smoke test.

Laravel `.env`:

```dotenv
MEDIA_RUNTIME_URL=http://PRIVATE_RUNTIME_HOST:8090
MEDIA_RUNTIME_KEY=SAME_RANDOM_SECRET
MEDIA_ENABLED_KINDS=vision,document
IMAGE_DAILY_LIMIT=20
MEDIA_DAILY_LIMIT=20
MEDIA_USER_STORAGE_BYTES=200000000
```

Enable only kinds confirmed by an actual inference test. If the runtime is down, tasks fail visibly; there is no provider fallback. Run:

```bash
php artisan migrate --force
php artisan config:cache
php artisan queue:work media --queue=media --timeout=900 --tries=1 --sleep=3
# Existing default/model download workers remain separate.
```

The dedicated `media` database connection uses a 1000-second retry window. Run one media worker per runtime instance. Run Laravel's scheduler every minute. It marks abandoned running jobs failed after 20 minutes and queued jobs failed after a day, deleting their inputs. Supervise workers and restart them after deployment. If shared hosting disallows persistent PHP workers, use a worker-capable application host.

## Storage and behavior

Authenticated API: `GET/POST /api/v1/media`, `GET/DELETE /media/{id}`, `GET /media/{id}/output`. JSON creation accepts `kind`, `prompt` and base64 `input` for vision/audio/document. The web frontend uses its HttpOnly-session proxy; runtime credentials never reach the browser. Private input/output files are stored under Laravel `storage/app/private/media`, never the public disk; task metadata/status and extracted text are stored in the DB. Uploads are limited to 8 MB, image inputs to 16 megapixels, output files to 20 MB, and text to 100 KB. Two pending tasks per user apply. Image creation has its own daily quota (`IMAGE_DAILY_LIMIT`, default 20); other media share `MEDIA_DAILY_LIMIT` (default 20). Daily usage is persisted independently of results, so deleting a task does not refund requests. Limits use the Laravel server calendar day; accepted jobs can finish after the quota is reached. Run `php artisan migrate --force` before deploying the new quota table. Storage admission reserves 20 MB per pending task. Submitting a new task, including a manual retry after failure, consumes a new request. Internal processing steps or redelivery of the same job do not create an additional request; failed accepted jobs still count. The runtime remains self-hosted.

Inputs are removed after processing/failure. Results remain until deleted. Account deletion removes private media files. Deleting a running task discards its eventual output; it does **not** immediately interrupt GPU execution. Temporary chat does not apply to separately saved media tasks. Saved chat exports contain task metadata and text, not binary media; download binary results individually before deleting your account. PDF/DOCX text can be sent to chat with “Use result in chat”; scanned PDFs have no OCR. Media studio is a separate workspace, not continuous realtime audiovisual chat.

## Verification

```bash
pip install fastapi httpx 'pypdf>=6,<7'
python -m unittest discover -s . -v
```

Contract tests mock inference and cover authentication, disabled engines, input limits, concurrency and failure cleanup. They do not validate model output. Production acceptance requires an actual sample of every enabled kind on your target GPU/CPU and a complete browser → Laravel queue → runtime → private download check.

Primary implementation references:
- https://huggingface.co/docs/transformers/tasks/image_text_to_text
- https://huggingface.co/docs/transformers/main_classes/pipelines
- https://huggingface.co/docs/diffusers/api/pipelines/wan
- https://huggingface.co/docs/diffusers/api/pipelines/auto_pipeline

## Optional Python code runner

The workspace includes an explicit-review Python execution panel. It is disabled until configured. It runs code in a separate disposable container, not in PHP, the web app process or the model process. Use a dedicated rootless Docker host (with resource limits supported by its cgroup configuration), keep the engine patched and validate its isolation for your deployment. This is not a claim that ordinary containers eliminate every hostile-code risk. Never expose the Docker daemon to users, mount its socket into execution containers, or run the service on a shared production application host.

Build `Dockerfile.sandbox`, scan/test the image, and configure `MEDIA_CODE_IMAGE=sha256:FULL_LOCAL_IMAGE_ID` (obtain the ID with `docker image inspect --format '{{.Id}}' openmindai-sandbox:local`) or an existing repository image digest on the runtime host. The runner uses `--pull=never`; provisioning must load the exact image into the local engine first. Add `code` to Laravel `MEDIA_ENABLED_KINDS`. Limits: 35 seconds, one CPU, 512 MB RAM, 64 processes, read-only root, no network, no capabilities, non-root user, bounded tmpfs and no host mounts. Input code is at most 4000 characters. Files under `/work/output` (at most 20 files / 10 MB total) and stdout are returned in a private ZIP download. Numpy, pandas and matplotlib are included in the example image; package installation/network access during execution is unavailable. Code is executed only after the user reviews it and selects Run; assistant text never triggers execution automatically.

The Python/API tests mock model inference and Docker calls. Actual GPU generation, microphone recording and container execution must be checked on their target hosts/browsers before enabling the corresponding capabilities.

Code artifact previews are produced outside the execution container from allowlisted ZIP entries without extracting files. PNG previews are capped at 500 KB and 16 megapixels, CSV preview at 101 rows including its header and 30 columns, text at 20,000 characters, and total serialized preview data at 1.5 MB. Laravel revalidates metadata before storing it. Full files remain in the ZIP; previews are samples. Rebuild/restart the media runtime and migrate Laravel to enable preview metadata for new tasks.
