<?php

return ['inference_url' => env('INFERENCE_URL', 'http://127.0.0.1:8080/v1'), 'inference_key' => env('INFERENCE_KEY'), 'hf_token' => env('HF_TOKEN'), 'max_bytes' => (int) env('MODEL_MAX_BYTES', 10000000000), 'storage_budget' => (int) env('MODEL_STORAGE_BUDGET_BYTES', 50000000000), 'daily_limit' => (int) env('CHAT_DAILY_LIMIT', 200)];
