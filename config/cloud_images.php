<?php

return [
    'enabled' => (bool) env('CLOUD_IMAGE_ENABLED', true),
    'key' => env('NVIDIA_API_KEY'),
    'fast_model' => 'black-forest-labs/flux.1-schnell',
    'fast_endpoint' => 'https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.1-schnell',
    'ratio_model' => 'stabilityai/stable-diffusion-3-medium',
    'ratio_endpoint' => 'https://ai.api.nvidia.com/v1/genai/stabilityai/stable-diffusion-3-medium',
    // Image editing needs an operator-managed NIM that accepts real uploaded images.
    // The public NVIDIA preview endpoint only accepts canned example IDs.
    'edit_url' => env('CLOUD_IMAGE_EDIT_NIM_URL'),
    'edit_key' => env('CLOUD_IMAGE_EDIT_NIM_KEY'),
    'edit_model' => env('CLOUD_IMAGE_EDIT_MODEL', 'qwen/qwen-image-edit-2511'),
    'mask_url' => env('CLOUD_IMAGE_MASK_NIM_URL'),
    'mask_key' => env('CLOUD_IMAGE_MASK_NIM_KEY'),
    'mask_model' => env('CLOUD_IMAGE_MASK_MODEL'),
    'prompt_model' => env('CLOUD_IMAGE_PROMPT_MODEL', 'nvidia/nemotron-3.5-lightning-30b-a3b'),
];