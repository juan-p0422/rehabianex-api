<?php

return [
    /*
    | Version vigente del aviso que Android debe mostrar antes del registro.
    | No contiene el texto legal: solamente su identificador versionado.
    */
    'privacy_notice_version' => env('PRIVACY_NOTICE_VERSION', '2026-11-03'),
    'privacy_notice_published_on' => '2026-11-03',
    'privacy_notice_file' => resource_path('legal/privacy-notice-2026-11-03.md'),

    'sensitive_data_consent_version' => env('SENSITIVE_DATA_CONSENT_VERSION', '2026-10-27'),
    'closed_beta_minimum_age' => 18,
    'closed_beta_age_policy_status' => 'confirmed_adults_only',

    'responsible' => [
        'name' => 'Juan Oswaldo Emilio Olivares Pantoja',
        'location' => 'Tonalá, Jalisco, México',
        'phone' => '3325356657',
        'email' => 'rehabianex@gmail.com',
        'applicable_address' => 'Calle Circuito Loma Norte número 8962, Colonia Loma Dorada, C.P. 45402, Tonalá, Jalisco, México',
    ],
];
