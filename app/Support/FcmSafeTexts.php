<?php

namespace App\Support;

use RuntimeException;

final class FcmSafeTexts
{
    public const TITLE = 'Rehabianex';

    public const REMINDER = 'Tienes un recordatorio pendiente.';

    public const SYSTEM = 'Tienes una actualización en Rehabianex.';

    public const VALIDATION = 'Hay una validación pendiente.';

    public const REQUEST = 'Tienes una solicitud pendiente.';

    public const CRITICAL_FOLLOW_UP = 'Hay una actualización importante de seguimiento.';

    public static function bodyFor(string $type): string
    {
        return match ($type) {
            FcmNotificationTypes::APPOINTMENT_REMINDER,
            FcmNotificationTypes::PROGRESS_CHECKIN => self::REMINDER,
            FcmNotificationTypes::SUPERVISION_REQUEST,
            FcmNotificationTypes::UNLINK_REQUEST => self::REQUEST,
            FcmNotificationTypes::RELAPSE_ALERT,
            FcmNotificationTypes::RISK_ALERT,
            FcmNotificationTypes::VULNERABLE_USER_PRIORITY => self::CRITICAL_FOLLOW_UP,
            FcmNotificationTypes::USER_VALIDATION_REQUIRED,
            FcmNotificationTypes::SUPERVISOR_VALIDATION_REQUIRED,
            FcmNotificationTypes::SUPERVISION_REQUEST_CONFLICT => self::VALIDATION,
            FcmNotificationTypes::SOBER_DAY_UPDATE,
            FcmNotificationTypes::ACHIEVEMENT_UNLOCKED,
            FcmNotificationTypes::SUPERVISION_RESPONSE,
            FcmNotificationTypes::INTERVENTION_UPDATE,
            FcmNotificationTypes::SYSTEM_NOTICE,
            FcmNotificationTypes::TEST_NOTIFICATION => self::SYSTEM,
            default => throw new RuntimeException('Tipo FCM no soportado.'),
        };
    }
}
