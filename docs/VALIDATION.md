# Validation

The automated suite covers registration privilege isolation, email verification before login, bearer login/logout, suspended/guest access, conversation ownership, admin authorization/rendering, chat forwarding/history and upstream failure, authentication throttling, TOTP setup/confirmation/replay prevention, recovery code reuse rejection, model import integrity/pinned revision, cookie/API separation, successful streamed download finalization, corrupt-download cleanup and unsafe redirect rejection.

HTTP fakes stand in for Hugging Face and the inference runtime. They verify backend policy, not real GPU inference, multi-GB transfer performance, cPanel quotas or delivered SMTP. Those require deployment credentials, a running compatible model/runtime and actual hosting access. Local validation uses PHP 8.3 with dependency resolution constrained to PHP 8.2; CI additionally targets PHP 8.2.

Before production use, perform registration → delivered verification email → login → 2FA setup → fresh code login → model import/download → runtime loading → chat response on the actual host. Check denied access from another account, failed upstream behavior, worker restarts and hosting disk quota.

## Execution results

- 19 feature tests passed, 58 assertions, on local PHP 8.3.
- Composer strict validation, Pint formatting, route/view/config compilation and dependency advisory audit passed.
- A live Hugging Face GGUF search and repository metadata request succeeded. No multi-GB model was downloaded during validation.
- Browser automation was attempted; browser installation availability is environment-dependent. The admin views are rendered by feature tests.

## Next.js frontend verification

- Production Next.js 16.4.2 build and TypeScript compilation passed.
- 22 Laravel feature tests passed with 64 assertions, including updated-activity history ordering, frontend password reset URLs, and explicit proxy-IP trust.
- 11 production Next.js HTTP integration tests passed: rendered workspace HTML, cookie authentication, CSRF origin enforcement, route restrictions, numeric pagination, chat forwarding, upstream errors, rate-limit headers, logout and oversized-body rejection.
- An additional local Next.js → real Laravel → SQLite flow passed: login, model listing, chat, two persisted messages, logout/login, restored history, deletion and logout. Inference used a local HTTP fixture, not downloaded model weights.
- Frontend production dependency audit reported zero vulnerabilities.
- No interactive browser/visual QA or real hosting deployment was performed for this frontend. HTTP rendering and API tests do not establish mobile visual correctness or real GPU inference performance.


## Expanded Openmindai workspace verification

37 Laravel tests passed (125 assertions), including streaming persistence/errors/stop, truncated-stream persistence and recovery after a failed turn, temporary-chat isolation, projects/memory injection, message-text search and archive, branching, file ownership and later-turn context, share snapshot/revocation, feedback, canvas optimistic concurrency and optional self-hosted search sanitization. Four database migrations extend existing installations without removing their chats.

14 production Next.js HTTP integration tests passed, including SSE passthrough, anonymous share reads and the supplied brand asset. The production build and TypeScript compilation passed. An additional real Next.js → Laravel → SQLite SSE fixture flow confirmed streamed deltas, final stored messages, restored history after signing in again, deletion and logout. The inference fixture was local test code, not an actual downloaded model. Browser visual QA, actual runtime cancellation latency and live hosting remain unverified.
