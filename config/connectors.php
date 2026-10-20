<?php

return [
    'daily_limit' => (int) env('MCP_DAILY_LIMIT', 100),
    // Operator-managed catalogue. Users can never supply arbitrary network endpoints.
    'servers' => json_decode(env('MCP_SERVERS_JSON', '{}'), true) ?: [],
];
