# Openmindai feature coverage

Openmindai is an independent self-hosted chat application. This implementation reproduces selected familiar workflows; it is not complete ChatGPT product parity. All inference uses the administrator's own runtime and Hugging Face downloadable models. No commercial model-provider integration is included.

| Feature | Current implementation |
| --- | --- |
| App branding | Openmindai, with the user's supplied Gradient Brain Chat Bubble Logo |
| Accounts | Registration, verified email, password reset/change, bearer devices, admin suspension, account deletion API |
| Authentication | HttpOnly frontend token cookie; TOTP and recovery codes; request rate limits |
| Chat | Genuine upstream SSE deltas, stop request, explicit completion/failure/cancelled status |
| History | User-scoped database history, server-side title/message search, pagination, rename, pin, archive, delete |
| Edit / regenerate | Creates a separate branch before the chosen user message; preserves the original conversation |
| Projects | Owned projects, editable instructions, chat assignment and sidebar filtering |
| Personalization | Custom instructions, explicit user-managed memories, enable/disable memory, dark/light/system preference |
| Temporary chat | Browser-held conversation context; no conversation/message database records; ignores saved memories/custom instructions |
| Attachments / library | Text, Markdown, CSV, JSON and source-code files; owned database storage, reuse, download and delete; attachment context survives later turns |
| Sharing | Explicit user-created 30-day public snapshots; later messages excluded; revocation supported |
| Export | User profile, projects, memories, saved chats, attachments and canvases; excludes password/2FA/token secrets |
| Canvas | Private Markdown/code documents, edit/preview/save, optimistic version checks, reuse in chat; code is never executed |
| Feedback | Owned assistant-message thumbs up/down |
| Read aloud | Browser speech synthesis with an installed on-device voice only; availability depends on the device |
| Web search | Optional self-hosted SearXNG JSON adapter; up to five sanitized snippets/source URLs; not full-page browsing or deep research |

## Self-hosted media integrations

Media studio now provides queued image generation, image understanding, audio transcription, speech generation, short Wan video generation and PDF/DOCX text extraction. Includes task ownership, quotas, status/failure handling, private previews/downloads, deletion and reuse of text in chat. The Laravel/Next workflows and Python HTTP contract are tested with fixtures. Real model/GPU inference has not been validated in this environment. These capabilities remain disabled until the administrator provisions compatible revision-pinned HF snapshots, runs the private Python runtime and queue worker, and enables the kinds. See [runtime setup](../runtime/media/README.md). Media snapshot provisioning is a CLI operation; the existing admin click-to-download form remains GGUF-only.

## Not implemented

The following are absent, rather than simulated by buttons: continuous realtime speech conversation, screen/video sharing, autonomous browser agents, live interactive data-analysis charts, a public assistant marketplace, external account connectors/MCP administration, subscriptions/payments and team collaboration. Bounded research, scheduled tasks and an optional reviewed Python runner are described below. These require separate implementation, secure execution environments, suitable self-hosted engines/models and deployment capacity. Downloading a GGUF file does not provide those capabilities.

The read-aloud feature and queued speech generation are not realtime voice chat. Canvas is a text/document editor, not a browser or code-execution sandbox. Search snippets are not a verified multi-page research report. Memory is explicit stored user notes, not automatic cross-conversation learning.

## Privacy and operational details

The streaming endpoint saves a user question and a generating assistant record before contacting inference. Completed, failed and stopped responses remain in history, including any partial text. A stream terminated by a process crash can remain generating until scheduled cleanup marks it failed after ten minutes. The older non-streaming `/chat` endpoint keeps its atomic success-only storage behavior.

Temporary chat sends its in-memory previous turns back to the runtime without creating chat records. Files uploaded to the separate library are still stored until explicitly deleted; selecting temporary chat does not delete existing library files. Export/share are opt-in user actions. Share snapshots contain completed message text, not raw library file contents or authentication secrets; users should still review what their message text reveals.

A stop request needs a second available PHP worker while the streaming worker is active. Disable response buffering for SSE at the reverse proxy. A slow/buffered server can delay deltas and cancellation. An inference runtime must support chat-completion SSE; there is no fake typewriter fallback. Actual inference, hosting, SMTP, browser voice and visual/responsive QA require deployment-specific validation.

## Profile and settings

The Settings dialog now groups General, Personalization, Data controls, Account, Security and Devices. General settings persist theme (including live system-theme changes), accent color, chat text size, reduced motion, Enter-to-send and optional hidden-tab completion notifications. Browser notifications require permission on each supported desktop browser; no background/mobile push service is included. Response language affects inference instructions, not translated interface labels.

Account settings include display name, locally resized profile photo, verified email-change workflow, password change, TOTP/recovery codes and current-device identification/revocation. Email changes keep the existing address active until explicit single-use confirmation at the new address (one-hour expiry); confirmation revokes tokens and server sessions. Password changes/reset cancel pending email changes. SMTP and a correct `FRONTEND_URL` are required. Photos are stored in the user's DB record as validated PNG/JPEG/WebP data URLs, never SVG or arbitrary remote URLs.

Personalization includes nickname, occupation, background, custom instructions, response style/detail/language, a customization switch, independent memory opt-in and memory CRUD. These choices are injected into future saved-chat inference contexts. Temporary chat excludes personal customization and memory.

Data controls include export with profile/preferences, paginated archived-chat restoration/deletion, share revocation, bulk archive/delete chats, clear all memories, revoke all shared links, and account deletion. Bulk actions require a typed action confirmation plus password and enabled 2FA. Account deletion requires password/2FA; the frontend also requires typing the account email. Deleting chats removes messages/share snapshots; independent library files, media tasks, canvases and memories remain until separately deleted or the account is deleted. Infrastructure backup retention is controlled by the host operator. No training, billing, subscription, connected-app, parental-control or enterprise-admin product is implemented by these settings; there are no pretend toggles for absent services.

## Workspace tools UI

The Tools & library workspace adds private assistant creation/editing/deletion, model selection and up to five owned text knowledge files. Assistant instructions are carried into saved conversations and their branches. The gallery is private to each user; it is not a public marketplace.

Tasks support manual runs and one-time/daily/weekly scheduling, edit/pause/delete, paginated run history, progress steps, cancellation and downloadable results. Research uses self-hosted search, reads up to five public HTML/text pages with DNS pinned to validated public addresses, rejects redirects/private targets, and synthesizes a report with source text/status available in expandable panels. It is bounded research, not an autonomous browser or a guarantee of factual verification. A cancelled active HTTP inference may finish server-side; its result is discarded.

Library combines searchable, paginated text files and media tasks, type filters, text/image/audio/video previews, private downloads, deletion and reuse in chat. Composer supports drag/drop and local image/PDF/audio preview before submitting to owned media tasks; DOCX becomes extracted text after processing. Media creation modes produce inline preview cards, and results remain in the Library. Inline preview placement is local to the current browser workspace view, not persisted as multimodal message blocks in chat history.

Voice input provides microphone permission, a live level meter, stop, queued private transcription and editable transcript before sending. It is turn-based, not continuous realtime speech conversation or screen/video sharing. Canvas now supports simultaneous editor/preview, lexical code highlighting, retained versions (latest 50), side-by-side comparison and loading an old version into a new draft. Comparison is side-by-side text, not a full semantic/line-level diff editor. A branch navigator lets users switch saved conversations in the same owned branch family.

The Python/data panel requires explicit code review/approval before submitting to an optional isolated container runner. It shows output and downloads an artifact ZIP; it does not execute code in the web application or offer live interactive charts. See runtime setup. General autonomous tool-calling/browser agents, team collaboration, payments/billing and external connectors/MCP management still require separate backend products. No disabled service is represented as a working commercial feature.

Deployment: migrate the DB, restart workers, run `php artisan queue:work --queue=tasks --timeout=240 --tries=1` alongside existing queues and run the scheduler every minute. Research requires the configured self-hosted SearXNG endpoint and PHP cURL/DOM extensions. Voice recording requires HTTPS and browser microphone permission. All newly added engine features remain gated by actual server configuration. Browser visual/device QA, real microphone recording, GPU output, Docker isolation and public-page extraction on the deployed network remain acceptance checks.
