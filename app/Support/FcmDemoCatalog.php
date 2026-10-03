<?php

namespace App\Support;

use InvalidArgumentException;

final class FcmDemoCatalog
{
    public const TYPES_BY_ROLE = [
        'patient' => [
            FcmNotificationTypes::APPOINTMENT_REMINDER,
            FcmNotificationTypes::PROGRESS_CHECKIN,
            FcmNotificationTypes::SOBER_DAY_UPDATE,
            FcmNotificationTypes::ACHIEVEMENT_UNLOCKED,
            FcmNotificationTypes::INTERVENTION_UPDATE,
            FcmNotificationTypes::SUPERVISION_RESPONSE,
            FcmNotificationTypes::SYSTEM_NOTICE,
            FcmNotificationTypes::TEST_NOTIFICATION,
        ],
        'supervisor' => [
            FcmNotificationTypes::SUPERVISION_REQUEST,
            FcmNotificationTypes::UNLINK_REQUEST,
            FcmNotificationTypes::CONSENT_SUSPENDED,
            FcmNotificationTypes::RELAPSE_ALERT,
            FcmNotificationTypes::RISK_ALERT,
            FcmNotificationTypes::VULNERABLE_USER_PRIORITY,
            FcmNotificationTypes::SUPERVISOR_VALIDATION_APPROVED,
            FcmNotificationTypes::SYSTEM_NOTICE,
            FcmNotificationTypes::TEST_NOTIFICATION,
        ],
        'admin' => [
            FcmNotificationTypes::USER_VALIDATION_REQUIRED,
            FcmNotificationTypes::SUPERVISOR_VALIDATION_REQUIRED,
            FcmNotificationTypes::SUPERVISION_REQUEST_CONFLICT,
            FcmNotificationTypes::SYSTEM_NOTICE,
            FcmNotificationTypes::TEST_NOTIFICATION,
        ],
    ];

    public static function isAllowedForRole(string $type, string $role): bool
    {
        return in_array($type, self::TYPES_BY_ROLE[$role] ?? [], true);
    }

    public static function routeFor(string $type, string $entityId): string
    {
        return match ($type) {
            FcmNotificationTypes::APPOINTMENT_REMINDER => '/agenda-events/'.$entityId,
            FcmNotificationTypes::PROGRESS_CHECKIN => '/checkins/new',
            FcmNotificationTypes::SOBER_DAY_UPDATE,
            FcmNotificationTypes::SUPERVISOR_VALIDATION_APPROVED,
            FcmNotificationTypes::TEST_NOTIFICATION => '/home',
            FcmNotificationTypes::ACHIEVEMENT_UNLOCKED => '/achievements/'.$entityId,
            FcmNotificationTypes::SUPERVISION_REQUEST,
            FcmNotificationTypes::SUPERVISION_RESPONSE => '/supervision-requests/'.$entityId,
            FcmNotificationTypes::UNLINK_REQUEST => '/unlink-requests/'.$entityId,
            FcmNotificationTypes::CONSENT_SUSPENDED => '/consents/'.$entityId,
            FcmNotificationTypes::INTERVENTION_UPDATE,
            FcmNotificationTypes::SYSTEM_NOTICE => '/interventions/'.$entityId,
            FcmNotificationTypes::RELAPSE_ALERT,
            FcmNotificationTypes::RISK_ALERT,
            FcmNotificationTypes::VULNERABLE_USER_PRIORITY => '/patients/priority',
            FcmNotificationTypes::USER_VALIDATION_REQUIRED => '/admin/users/pending/'.$entityId,
            FcmNotificationTypes::SUPERVISOR_VALIDATION_REQUIRED => '/admin/supervisors/pending/'.$entityId,
            FcmNotificationTypes::SUPERVISION_REQUEST_CONFLICT => '/admin/supervision-conflicts/'.$entityId,
            default => throw new InvalidArgumentException('Tipo FCM de demostración no soportado.'),
        };
    }
}
