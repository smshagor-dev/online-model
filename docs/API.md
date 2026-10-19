# API v1

Base URL: `/api/v1`. Send `Accept: application/json` and `Content-Type: application/json`. Protected endpoints require `Authorization: Bearer <token>`. Administrator session cookies do not authenticate API requests. Errors use Laravel JSON validation/error responses. Rate limits return HTTP 429 and retry headers.

## Authentication

| Method | Path | Body / behavior |
| --- | --- | --- |
| POST | `/register` | `name`, `email`, `password`, `password_confirmation`; sends verification email; no token until verified |
| POST | `/email/resend` | `email`; generic response |
| POST | `/login` | `email`, `password`, `device_name`, optional `code` (TOTP or recovery); returns token and user |
| POST | `/forgot-password` | `email`; generic response |
| POST | `/reset-password` | `email`, `token`, `password`, `password_confirmation`; revokes devices/sessions |
| POST | `/logout` | Revoke current token |
| POST | `/logout-all` | Revoke all bearer tokens |

Passwords require at least 12 characters with uppercase, lowercase and digits. Login issues up to 10 device tokens per user, each expiring after 30 days. Email verification uses a signed, expiring link. Password reset link opens `/reset-password`.

## Account and 2FA

| Method | Path | Body / behavior |
| --- | --- | --- |
| GET | `/me` | Profile; secrets omitted |
| PATCH | `/me` | `name` |
| PUT | `/me/password` | `current_password`, `password`, `password_confirmation`; signs out all devices |
| DELETE | `/me` | `password`, `code` if 2FA enabled; deletes account and conversations; admins excluded |
| GET | `/tokens` | Device token metadata, never plaintext tokens |
| DELETE | `/tokens/{id}` | Revoke owned token |
| POST | `/2fa/setup` | `password`; returns `secret` and `otpauth_url` for an authenticator |
| POST | `/2fa/confirm` | `code`; enables 2FA and returns eight recovery codes once |
| POST | `/2fa/recovery-codes` | `password`, `code`; replaces recovery codes |
| DELETE | `/2fa` | `password`, `code`; disables 2FA |

Show `otpauth_url` as a QR code in your client, or enter `secret` manually. Store recovery codes offline. TOTP codes cannot be replayed, including reuse of the confirmation code for immediate login; wait for the next time step or use a recovery code. Recovery codes are single-use. Enabling/disabling 2FA revokes other devices and administrator sessions. Keep the current bearer token secure.

## Chat and history

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/models` | Enabled/downloaded models only |
| POST | `/chat` | `model_id`, `message`, optional `conversation_id`, optional `max_tokens` (1–2048) |
| GET | `/conversations` | Paginated owned conversations |
| GET | `/conversations/{id}` | Owned conversation with messages |
| DELETE | `/conversations/{id}` | Delete owned conversation and messages |

```json
{"model_id":1,"message":"Explain dependency injection","max_tokens":1024}
```

```json
{"conversation_id":1,"message":{"role":"assistant","content":"..."}}
```

Default limits: authentication 5/minute per IP and 20/hour per submitted email; account APIs 60/minute; sensitive account actions 5/minute; chat 10/minute and `CHAT_DAILY_LIMIT` (default 200)/day per user. One concurrent chat per user. Prompt text max 16,000 characters; history includes up to 20 previous messages with a 32,000-byte history budget. These character/byte bounds are not a tokenizer-based model context guarantee. Choose compatible runtime context limits. The legacy `/chat` responses are non-streaming; its runtime failures return 502/503 without saving a partial turn. The frontend uses `/chat/stream` described below, which preserves failed/interrupted turns. No external fallback is used.

## Openmindai workspace APIs

The frontend uses `POST /chat/stream` with `model_id`, `message`, optional `conversation_id`, `project_id`, `attachment_ids` (up to five), `temporary`, `history` (temporary chats only, user/assistant turns), and `web_search` (requires own SearXNG).

Response is `text/event-stream`. Events are `meta` (conversation/user-message/assistant-message IDs and sources), `delta` (`content` text), optional `error`, and `done` (`status`: complete, failed or cancelled). Stream errors are events after headers have been sent; callers must consume `done`, not rely solely on HTTP 200. Temporary chat IDs are null.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/conversations/{id}/messages/{message}/stop` | Request cancellation of owned generating assistant message |
| PATCH | `/conversations/{id}` | `title`, `pinned`, `archived`, `project_id` |
| POST | `/conversations/{id}/branch` | `message_id` of an owned user message; returns a new conversation containing only preceding history and the target prompt/attachment IDs |
| PUT | `/conversations/{id}/messages/{message}/feedback` | `feedback`: up, down or null |
| GET | `/conversations?q=...&project_id=...&archived=1` | Search title and message text; active chats by default; pinned first |
| GET/PATCH | `/preferences` | `custom_instructions`, `memory_enabled`, `theme` |
| GET/POST | `/projects` | List/create own project with `name`, `instructions` |
| PUT/DELETE | `/projects/{id}` | Edit/delete project; deleting retains chats without project assignment |
| GET/POST | `/memories` | List/create own explicit memory with `content` |
| PUT/DELETE | `/memories/{id}` | Edit/delete own memory |
| GET/POST | `/attachments` | List/upload text file using JSON `name`, `content`; 100 KB per file, 10 MB user quota |
| GET/DELETE | `/attachments/{id}` | Read/delete own file; deletion removes its future inference context |
| GET/POST | `/canvases` | List/create private canvas (`title`, `format`: markdown/code, `content`) |
| GET/PUT/DELETE | `/canvases/{id}` | Read/update/delete; PUT requires the last observed `version` and rejects stale writes with 409 |
| POST | `/conversations/{id}/share` | Create 30-day public snapshot; returns secret token once |
| GET/DELETE | `/shares` / `/shares/{id}` | List/revoke owned share snapshots |
| GET | `/shared/{token}` | Public snapshot, no bearer required; expired/revoked links return 404 |
| GET | `/export` | User data export without auth secrets |
| GET | `/capabilities` | Discover available/unsupported tools |
| POST | `/web-search` | `query`; optional own SearXNG snippets |

See [feature coverage](FEATURE_COVERAGE.md) for precise capability boundaries. The older non-streaming API remains available for clients that need it.
