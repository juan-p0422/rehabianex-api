<?php

namespace App\Support;

use RuntimeException;

final class FcmSafeTexts
{
    public const TITLE = 'Rehabianex';

    public const SYSTEM = 'Tienes una actualización en Rehabianex.';

    public static function bodyFor(string $type): string
    {
        return match ($type) {
            FcmNotificationTypes::APPOINTMENT_REMINDER => 'Tienes un evento de agenda próximo.',
            FcmNotificationTypes::PROGRESS_CHECKIN => 'Es momento de registrar tu seguimiento.',
            FcmNotificationTypes::ACHIEVEMENT_UNLOCKED => 'Has alcanzado un nuevo logro.',
            FcmNotificationTypes::SOBER_DAY_UPDATE => 'Has alcanzado un nuevo avance en tu proceso.',
            FcmNotificationTypes::INTERVENTION_UPDATE => 'Tu plan de apoyo tiene una actualización.',
            FcmNotificationTypes::SUPERVISION_RESPONSE => 'Tu solicitud de acompañamiento fue actualizada.',
            FcmNotificationTypes::SUPERVISION_REQUEST => 'Tienes una nueva solicitud de acompañamiento.',
            FcmNotificationTypes::UNLINK_REQUEST => 'Tienes una solicitud de cambio de consentimiento.',
            FcmNotificationTypes::CONSENT_SUSPENDED => 'Se actualizó el estado de un consentimiento.',
            FcmNotificationTypes::RISK_ALERT,
            FcmNotificationTypes::VULNERABLE_USER_PRIORITY => 'Hay una actualización importante de seguimiento.',
            FcmNotificationTypes::RELAPSE_ALERT => 'Hay una actualización prioritaria de seguimiento.',
            FcmNotificationTypes::SUPERVISOR_VALIDATION_APPROVED => 'Tu cuenta de supervisor fue aprobada.',
            FcmNotificationTypes::SUPERVISOR_VALIDATION_REQUIRED => 'Hay una cuenta de supervisor pendiente de validación.',
            FcmNotificationTypes::USER_VALIDATION_REQUIRED => 'Hay una cuenta pendiente de validación.',
            FcmNotificationTypes::SUPERVISION_REQUEST_CONFLICT => 'Hay una solicitud de supervisión que requiere revisión.',
            FcmNotificationTypes::SYSTEM_NOTICE,
            FcmNotificationTypes::TEST_NOTIFICATION => self::SYSTEM,
            default => throw new RuntimeException('Tipo FCM no soportado.'),
        };
    }
}
