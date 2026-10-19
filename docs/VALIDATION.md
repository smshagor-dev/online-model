# Validation

The automated suite covers registration privilege isolation, email verification before login, bearer login/logout, suspended/guest access, conversation ownership, admin authorization/rendering, chat forwarding/history and upstream failure, authentication throttling, TOTP setup/confirmation/replay prevention, recovery code reuse rejection, model import integrity/pinned revision, cookie/API separation, successful streamed download finalization, corrupt-download cleanup and unsafe redirect rejection.

HTTP fakes stand in for Hugging Face and the inference runtime. They verify backend policy, not real GPU inference, multi-GB transfer performance, cPanel quotas or delivered SMTP. Those require deployment credentials, a running compatible model/runtime and actual hosting access. Local validation uses PHP 8.3 with dependency resolution constrained to PHP 8.2; CI additionally targets PHP 8.2.

Before production use, perform registration → delivered verification email → login → 2FA setup → fresh code login → model import/download → runtime loading → chat response on the actual host. Check denied access from another account, failed upstream behavior, worker restarts and hosting disk quota.

## Execution results

- 19 feature tests passed, 58 assertions, on local PHP 8.3.
- Composer strict validation, Pint formatting, route/view/config compilation and dependency advisory audit passed.
- A live Hugging Face GGUF search and repository metadata request succeeded. No multi-GB model was downloaded during validation.
- Browser automation was attempted; browser installation availability is environment-dependent. The admin views are rendered by feature tests.
