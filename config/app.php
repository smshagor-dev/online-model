<?php

return ['frontend_url' => env('FRONTEND_URL'), 'name' => env('APP_NAME', 'Openmindai'), 'env' => env('APP_ENV', 'production'), 'debug' => (bool) env('APP_DEBUG', false), 'url' => env('APP_URL', 'http://localhost'), 'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en', 'faker_locale' => 'en_US', 'key' => env('APP_KEY'), 'cipher' => 'AES-256-CBC', 'previous_keys' => [], 'maintenance' => ['driver' => 'file']];
