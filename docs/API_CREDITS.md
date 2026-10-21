# Admin API credit reporting

Admin dashboard and /admin/api-credits show per-model and per-user request units. Frontend-only provider keys never enter the browser or this database. The secure Next.js-to-Laravel bridge reports provider/endpoint names and successful completions.

One successfully completed hosted API request is one *application request credit*, not a provider token, cost, charge, NVIDIA billing credit or official remaining balance. Remaining is calculated from administrator-defined monthly model/user allocations. If no allocation is configured, remaining is shown as Not configured. Allocation values are reporting guides, not enforced provider spending limits or prepaid credit wallets. Existing rate limits remain independent. Requests before this feature was deployed cannot be reconstructed.

Only successful completions are tracked. A call can incur upstream provider usage even when its result fails. Research subcalls, memory extraction and local-only models are not separately accounted. The model list shows the upstream identifier if known, otherwise a named API endpoint (not a guessed submodel). The latest successful timestamp is historical evidence, not a live connectivity or health check.

Deploy the Laravel migration and frontend/backend revisions together:
    php artisan migrate --force
    php artisan optimize:clear

Tests:
    php artisan test --filter=ApiCreditsTest
