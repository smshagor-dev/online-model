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

Default limits: authentication 5/minute per IP and 20/hour per submitted email; account APIs 60/minute; sensitive account actions 5/minute; chat 10/minute and `CHAT_DAILY_LIMIT` (default 200)/day per user. One concurrent chat per user. Prompt text max 16,000 characters; history includes up to 20 previous messages with a 32,000-byte history budget. These character/byte bounds are not a tokenizer-based model context guarantee. Choose compatible runtime context limits. Responses are non-streaming; runtime failures return 502/503 without saving a partial turn. No external fallback is used.
