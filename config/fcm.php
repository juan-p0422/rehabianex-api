<?php

return [
    'enabled' => env('FCM_ENABLED', false),
    'dry_run' => env('FCM_DRY_RUN', true),
    'critical_alerts_enabled' => env('FCM_CRITICAL_ALERTS_ENABLED', false),
    'admin_notifications_enabled' => env('FCM_ADMIN_NOTIFICATIONS_ENABLED', false),
    'supervisor_clinical_alerts_enabled' => env('FCM_SUPERVISOR_CLINICAL_ALERTS_ENABLED', false),
    'vulnerable_priority_enabled' => env('FCM_VULNERABLE_PRIORITY_ENABLED', false),
    'offline_fallback_allowed' => env('FCM_OFFLINE_FALLBACK_ALLOWED', true),

    'scheduler' => [
        'secret' => env('FCM_SCHEDULER_SECRET', ''),
        'max_clock_skew_seconds' => env('FCM_SCHEDULER_MAX_CLOCK_SKEW_SECONDS', 300),
    ],

    'android' => [
        'channel_id' => env('FCM_DEFAULT_CHANNEL_ID', 'rehabianex_reminders'),
        'priority' => env('FCM_DEFAULT_ANDROID_PRIORITY', 'high'),
    ],

    'safe_public_notification' => [
        'title' => env('FCM_SAFE_PUBLIC_TITLE', 'Rehabianex'),
        'body' => env('FCM_SAFE_PUBLIC_BODY', 'Tienes una actualización pendiente.'),
    ],

];
