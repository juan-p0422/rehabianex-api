<?php

return [
    'self_service_export_enabled' => env('DATA_CONTROL_SELF_SERVICE_EXPORT_ENABLED', false),

    'recent_auth_seconds' => 300,

    'deactivation_confirmation' => 'DESACTIVAR MI CUENTA',

    // Compatibilidad temporal con clientes anteriores. No se anuncia en el
    // resumen nuevo y no significa eliminación física inmediata.
    'legacy_deletion_confirmation' => 'ELIMINAR MI CUENTA',

    'retention_notice' => 'La cuenta se bloquea de inmediato y la eliminación definitiva requiere revisión operativa. '
        .'Respaldos, registros de seguridad y datos ya procesados por proveedores pueden conservarse durante plazos '
        .'técnicos o legales aplicables. Esta solicitud no certifica su borrado inmediato.',

    'manual_attention' => [
        'channel' => 'phone',
        'phone' => '3325356657',
        'location' => 'Tonalá, Jalisco, México',
    ],

    'permanent_deletion_request' => [
        'channel' => 'email',
        'email' => env('PERMANENT_DELETION_REQUEST_EMAIL', 'rehabianex@gmail.com'),
        'subject' => env(
            'PERMANENT_DELETION_REQUEST_SUBJECT',
            'Solicitud de eliminación de cuenta RehabiAnex'
        ),
    ],
];
