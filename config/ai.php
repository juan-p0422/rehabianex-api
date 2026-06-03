<?php

return [
    'provider' => env('AI_PROVIDER', 'openrouter'),

    'api_key' => env('AI_API_KEY'),

    'base_url' => env('AI_BASE_URL', 'https://openrouter.ai/api/v1/chat/completions'),

    'model' => env('AI_MODEL', 'google/gemini-flash-1.5'),

    'app_name' => env('AI_APP_NAME', 'RehabiAnex'),

    'site_url' => env('AI_SITE_URL', 'http://127.0.0.1:8000'),

    'store_responses' => env('AI_STORE_RESPONSES', false),
];
