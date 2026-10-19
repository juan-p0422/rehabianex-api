<?php

return [
    /*
    | Version vigente del aviso que Android debe mostrar antes del registro.
    | No contiene el texto legal: solamente su identificador versionado.
    */
    'privacy_notice_version' => env('PRIVACY_NOTICE_VERSION', '2026-10-18'),
    'privacy_notice_published_on' => '2026-10-18',
    'privacy_notice_file' => resource_path('legal/privacy-notice-2026-10-18.md'),
];
