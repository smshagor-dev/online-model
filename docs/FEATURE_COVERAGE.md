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

## Not implemented

The following are absent, rather than simulated by buttons: image/video generation, image understanding, PDF/DOCX parsing, audio transcription, realtime speech conversation, screen/video sharing, autonomous browser agents, arbitrary code/data-analysis execution, a GPT/assistant marketplace, external account connectors/MCP administration, scheduled AI tasks, subscriptions/payments, team collaboration and advanced multi-page deep research. These require separate implementation, secure execution environments, suitable self-hosted engines/models and deployment capacity. Downloading a GGUF file does not provide those capabilities.

The read-aloud feature is not realtime voice chat. Canvas is a text/document editor, not a browser or code-execution sandbox. Search snippets are not a verified multi-page research report. Memory is explicit stored user notes, not automatic cross-conversation learning.

## Privacy and operational details

The streaming endpoint saves a user question and a generating assistant record before contacting inference. Completed, failed and stopped responses remain in history, including any partial text. A stream terminated by a process crash can remain generating until scheduled cleanup marks it failed after ten minutes. The older non-streaming `/chat` endpoint keeps its atomic success-only storage behavior.

Temporary chat sends its in-memory previous turns back to the runtime without creating chat records. Files uploaded to the separate library are still stored until explicitly deleted; selecting temporary chat does not delete existing library files. Export/share are opt-in user actions. Share snapshots contain completed message text, not raw library file contents or authentication secrets; users should still review what their message text reveals.

A stop request needs a second available PHP worker while the streaming worker is active. Disable response buffering for SSE at the reverse proxy. A slow/buffered server can delay deltas and cancellation. An inference runtime must support chat-completion SSE; there is no fake typewriter fallback. Actual inference, hosting, SMTP, browser voice and visual/responsive QA require deployment-specific validation.
