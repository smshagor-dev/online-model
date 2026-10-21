<?php

return [
    // Developer clients connect to Next.js; Laravel is an internal service.
    // Configure the public URL on the Laravel host.
    'public_base_url' => rtrim((string) env('DEVELOPER_API_BASE_URL',
        rtrim((string) env('APP_URL', ''), '/').'/api/v1/openmindai'), '/'),
];
