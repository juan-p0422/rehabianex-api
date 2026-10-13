<?php

return [
    'provider' => env('AI_PROVIDER', 'openrouter'),

    'api_key' => env('AI_API_KEY'),

    'base_url' => env('AI_BASE_URL', 'https://openrouter.ai/api/v1/chat/completions'),

    'model' => env('AI_MODEL', 'google/gemini-flash-1.5'),

    'app_name' => env('AI_APP_NAME', 'RehabiAnex'),

    'site_url' => env('AI_SITE_URL', 'http://127.0.0.1:8000'),

    'store_responses' => env('AI_STORE_RESPONSES', false),

    'question_max_chars' => env('AI_QUESTION_MAX_CHARS', 800),

    'context_max_notes' => env('AI_CONTEXT_MAX_NOTES', 20),

    'context_days' => env('AI_CONTEXT_DAYS', 7),

    'context_timezone' => env('AI_CONTEXT_TIMEZONE', 'America/Mexico_City'),

    'rate_limit_per_minute' => env('AI_RATE_LIMIT_PER_MINUTE', 10),

    'provider_calls_per_day' => env('AI_PROVIDER_CALLS_PER_DAY', 50),

    'connect_timeout_seconds' => env('AI_CONNECT_TIMEOUT_SECONDS', 10),

    'timeout_seconds' => env('AI_TIMEOUT_SECONDS', 45),

    'max_output_tokens' => env('AI_MAX_OUTPUT_TOKENS', 700),
];
