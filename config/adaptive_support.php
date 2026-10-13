<?php

return [
    'available' => env('ADAPTIVE_SUPPORT_AVAILABLE', true),
    'rules_version' => 'experimental-v1',

    // Valores de prueba configurables. No son umbrales clínicamente validados.
    'defaults' => [
        'suggestions_enabled' => false,
        'inactivity_reminder_enabled' => false,
        'inactivity_days' => 3,
        'inactivity_reminder_time' => '18:00',
        'craving_threshold' => 8,
        'anxiety_threshold' => 8,
    ],

    'ranges' => [
        'inactivity_days' => [2, 14],
        'scale_threshold' => [6, 10],
    ],

    'resources' => [
        'craving' => 'grounding_54321',
        'anxiety' => 'breathing_446',
    ],

    'third_party_notifications' => false,
];
