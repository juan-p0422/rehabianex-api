<?php

namespace App\Support;

final class FcmNotificationTypes
{
    public const APPOINTMENT_REMINDER = 'appointment_reminder';

    public const PROGRESS_CHECKIN = 'progress_checkin';

    public const SOBER_DAY_UPDATE = 'sober_day_update';

    public const ACHIEVEMENT_UNLOCKED = 'achievement_unlocked';

    public const SUPERVISION_REQUEST = 'supervision_request';

    public const SUPERVISION_RESPONSE = 'supervision_response';

    public const UNLINK_REQUEST = 'unlink_request';

    public const CONSENT_SUSPENDED = 'consent_suspended';

    public const INTERVENTION_UPDATE = 'intervention_update';

    public const RELAPSE_ALERT = 'relapse_alert';

    public const RISK_ALERT = 'risk_alert';

    public const VULNERABLE_USER_PRIORITY = 'vulnerable_user_priority';

    public const USER_VALIDATION_REQUIRED = 'user_validation_required';

    public const SUPERVISOR_VALIDATION_REQUIRED = 'supervisor_validation_required';

    public const SUPERVISOR_VALIDATION_APPROVED = 'supervisor_validation_approved';

    public const SUPERVISION_REQUEST_CONFLICT = 'supervision_request_conflict';

    public const SYSTEM_NOTICE = 'system_notice';

    public const TEST_NOTIFICATION = 'test_notification';

    public const ALL = [
        self::APPOINTMENT_REMINDER,
        self::PROGRESS_CHECKIN,
        self::SOBER_DAY_UPDATE,
        self::ACHIEVEMENT_UNLOCKED,
        self::SUPERVISION_REQUEST,
        self::SUPERVISION_RESPONSE,
        self::UNLINK_REQUEST,
        self::CONSENT_SUSPENDED,
        self::INTERVENTION_UPDATE,
        self::RELAPSE_ALERT,
        self::RISK_ALERT,
        self::VULNERABLE_USER_PRIORITY,
        self::USER_VALIDATION_REQUIRED,
        self::SUPERVISOR_VALIDATION_REQUIRED,
        self::SUPERVISOR_VALIDATION_APPROVED,
        self::SUPERVISION_REQUEST_CONFLICT,
        self::SYSTEM_NOTICE,
        self::TEST_NOTIFICATION,
    ];

    public static function routeIsAllowed(string $type, string $route, string $entityId): bool
    {
        return match ($type) {
            self::APPOINTMENT_REMINDER => $route === '/agenda-events/'.$entityId,
            self::PROGRESS_CHECKIN => in_array($route, ['/checkins/new', '/emotional-checkin'], true),
            self::SOBER_DAY_UPDATE => $route === '/home',
            self::ACHIEVEMENT_UNLOCKED => $route === '/achievements/'.$entityId,
            self::SUPERVISION_REQUEST,
            self::SUPERVISION_RESPONSE => $route === '/supervision-requests/'.$entityId,
            self::UNLINK_REQUEST => $route === '/unlink-requests/'.$entityId,
            self::CONSENT_SUSPENDED => $route === '/consents/'.$entityId,
            self::INTERVENTION_UPDATE => $route === '/interventions/'.$entityId,
            self::RELAPSE_ALERT,
            self::RISK_ALERT,
            self::VULNERABLE_USER_PRIORITY => $route === '/patients/priority',
            self::USER_VALIDATION_REQUIRED => $route === '/admin/users/pending/'.$entityId,
            self::SUPERVISOR_VALIDATION_REQUIRED => $route === '/admin/supervisors/pending/'.$entityId,
            self::SUPERVISOR_VALIDATION_APPROVED => $route === '/home',
            self::SUPERVISION_REQUEST_CONFLICT => $route === '/admin/supervision-conflicts/'.$entityId,
            self::SYSTEM_NOTICE => in_array($route, [
                '/supervision-requests/'.$entityId,
                '/interventions/'.$entityId,
            ], true),
            self::TEST_NOTIFICATION => in_array($route, ['', '/home'], true),
            default => false,
        };
    }
}
