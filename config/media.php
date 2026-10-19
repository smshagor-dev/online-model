<?php

return [
    'url' => env('MEDIA_RUNTIME_URL'),
    'key' => env('MEDIA_RUNTIME_KEY'),
    'kinds' => array_values(array_filter(explode(',', env('MEDIA_ENABLED_KINDS', '')))),
    'image_daily_limit' => (int) env('IMAGE_DAILY_LIMIT', env('MEDIA_DAILY_LIMIT', 20)),
    'daily_limit' => (int) env('MEDIA_DAILY_LIMIT', 20),
    'storage_bytes' => (int) env('MEDIA_USER_STORAGE_BYTES', 200000000),
];
