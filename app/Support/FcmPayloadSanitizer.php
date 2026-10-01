<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class FcmPayloadSanitizer
{
    public const FIELDS = [
        'type',
        'title',
        'body',
        'route',
        'entity_id',
        'notification_id',
        'created_at',
        'priority',
    ];

    public const FORBIDDEN_FIELDS = [
        'name',
        'alias',
        'patient_name',
        'patient_alias',
        'display_name',
        'email',
        'phone',
        'clinical_note',
        'relapse_detail',
        'craving_level',
        'anxiety_level',
        'diagnosis',
        'substance',
        'free_text_message',
        'location',
        'patient_uid',
        'supervisor_uid',
        'user_uid',
        'uid',
    ];

    public static function sanitize(array $payload): array
    {
        $keys = array_keys($payload);
        $unexpected = array_diff($keys, self::FIELDS);
        $missing = array_diff(self::FIELDS, $keys);

        if ($unexpected !== [] || $missing !== []) {
            throw new RuntimeException('El payload FCM no cumple el contrato de campos permitido.');
        }

        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                throw new RuntimeException('El payload FCM contiene un campo sensible prohibido.');
            }
        }

        foreach ($payload as $value) {
            if (! is_string($value)) {
                throw new RuntimeException('Todos los valores data-only deben ser cadenas.');
            }
        }

        if (! in_array($payload['type'], FcmNotificationTypes::ALL, true)) {
            throw new RuntimeException('Tipo FCM no soportado.');
        }

        if ($payload['title'] !== FcmSafeTexts::TITLE
            || $payload['body'] !== FcmSafeTexts::bodyFor($payload['type'])) {
            throw new RuntimeException('El texto visible FCM no pertenece al catálogo seguro.');
        }

        if (! in_array($payload['priority'], ['normal', 'high', 'critical'], true)) {
            throw new RuntimeException('Prioridad FCM inválida.');
        }

        if (! FcmNotificationTypes::routeIsAllowed(
            $payload['type'],
            $payload['route'],
            $payload['entity_id'],
        )) {
            throw new RuntimeException('La ruta FCM no coincide con el tipo y la entidad.');
        }

        if ($payload['type'] !== FcmNotificationTypes::TEST_NOTIFICATION
            && $payload['entity_id'] === '') {
            throw new RuntimeException('La notificación requiere una entidad opaca válida.');
        }

        if ($payload['notification_id'] === '' || strlen($payload['notification_id']) > 160) {
            throw new RuntimeException('notification_id no es válido.');
        }

        try {
            $payload['created_at'] = CarbonImmutable::parse($payload['created_at'])->toIso8601String();
        } catch (Throwable) {
            throw new RuntimeException('created_at debe ser una fecha ISO-8601 válida.');
        }

        return $payload;
    }
}
