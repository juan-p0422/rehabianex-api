<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Support\FcmNotificationTypes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FcmNotificationDispatcher
{
    public function __construct(private ?FcmRecipientAuthorizer $authorizer = null) {}

    public function created(string $resource, array $data): void
    {
        if (! config('fcm.enabled')) {
            return;
        }

        $this->safely($resource, function () use ($resource, $data): void {
            match ($resource) {
                'supervision-requests' => ($data['type'] ?? 'link') === 'unlink'
                    ? $this->sendUnlinkRequest($data)
                    : $this->sendSupervisionRequest($data),
                'interventions' => $this->sendInterventionUpdate($data, 'created'),
                'patient-notes' => $this->sendClinicalAlertForNote($data),
                default => null,
            };
        });
    }

    public function updated(string $resource, array $data): void
    {
        if (! config('fcm.enabled')) {
            return;
        }

        $this->safely($resource, function () use ($resource, $data): void {
            match ($resource) {
                'interventions' => $this->sendInterventionUpdate($data, 'updated'),
                default => null,
            };
        });
    }

    public function supervisionResponded(array $data): void
    {
        if (! config('fcm.enabled')) {
            return;
        }

        $this->safely('supervision-requests', fn () => $this->sendSupervisionResponse($data));
    }

    public function sendAppointmentReminder(array $event): void
    {
        $eventId = (string) ($event['event_id'] ?? '');
        $patientUid = (string) ($event['patient_uid'] ?? '');
        $startsAt = (string) ($event['starts_at'] ?? '');

        if ($eventId === '' || $patientUid === '' || $startsAt === ''
            || ($event['status'] ?? 'scheduled') !== 'scheduled') {
            return;
        }

        if (! $this->recipients()->canNotifyPatient($patientUid)) {
            return;
        }

        $this->queue(
            $patientUid,
            FcmNotificationTypes::APPOINTMENT_REMINDER,
            '/agenda-events/'.$eventId,
            $eventId,
            'appointment-reminder',
            now()->toIso8601String(),
            'normal',
            null,
            'appointment_reminder:'.$eventId.':'.$startsAt,
        );
    }

    public function sendProgressCheckin(string $patientUid, string $checkinId, string $createdAt): void
    {
        if (! $this->recipients()->canNotifyPatient($patientUid)) {
            return;
        }

        $this->queue(
            $patientUid,
            FcmNotificationTypes::PROGRESS_CHECKIN,
            '/checkins/new',
            $checkinId,
            'progress-checkin',
            $createdAt,
            'normal',
            null,
            'progress_checkin:'.$patientUid.':'.$createdAt,
        );
    }

    public function sendSoberDayUpdate(string $patientUid, string $confirmationId, string $createdAt): void
    {
        if (! $this->recipients()->canNotifyPatient($patientUid)) {
            return;
        }

        $this->queue(
            $patientUid,
            FcmNotificationTypes::SOBER_DAY_UPDATE,
            '/home',
            $confirmationId,
            'sober-day-confirmed',
            $createdAt,
            'normal',
            null,
            'sober_day_update:'.$confirmationId.':'.$patientUid,
        );
    }

    public function sendAchievementUnlocked(array $achievement): void
    {
        $patientUid = (string) ($achievement['patient_uid'] ?? '');
        $achievementId = (string) ($achievement['achievement_id'] ?? '');
        $createdAt = (string) ($achievement['unlocked_at'] ?? $achievement['created_at'] ?? now()->toIso8601String());

        if ($achievementId === '' || ! $this->recipients()->canNotifyPatient($patientUid)) {
            return;
        }

        $this->queue(
            $patientUid,
            FcmNotificationTypes::ACHIEVEMENT_UNLOCKED,
            '/achievements/'.$achievementId,
            $achievementId,
            'achievement-unlocked',
            $createdAt,
            'normal',
            null,
            'achievement_unlocked:'.$achievementId.':'.$patientUid,
        );
    }

    public function sendSupervisionRequest(array $request): void
    {
        $requestId = (string) ($request['request_id'] ?? '');
        $supervisorUid = (string) ($request['supervisor_uid'] ?? '');

        if ($requestId === '' || ! $this->recipients()->canNotifySupervisor($supervisorUid)) {
            return;
        }

        $this->queue(
            $supervisorUid,
            FcmNotificationTypes::SUPERVISION_REQUEST,
            '/supervision-requests/'.$requestId,
            $requestId,
            'supervision-request-created',
            (string) ($request['created_at'] ?? now()->toIso8601String()),
            'normal',
            null,
            'supervision_request:'.$requestId.':'.$supervisorUid,
        );
    }

    public function sendSupervisionResponse(array $request): void
    {
        $requestId = (string) ($request['request_id'] ?? '');
        $patientUid = (string) ($request['patient_uid'] ?? '');

        if ($requestId === '' || ! $this->recipients()->canNotifyPatient($patientUid)) {
            return;
        }

        $this->queue(
            $patientUid,
            FcmNotificationTypes::SUPERVISION_RESPONSE,
            '/supervision-requests/'.$requestId,
            $requestId,
            'supervision-response-'.($request['status'] ?? 'updated'),
            (string) ($request['responded_at'] ?? now()->toIso8601String()),
            'normal',
            null,
            'supervision_response:'.$requestId.':'.($request['status'] ?? 'updated').':'.$patientUid,
        );
    }

    public function sendUnlinkRequest(array $request): void
    {
        $requestId = (string) ($request['request_id'] ?? '');
        $supervisorUid = (string) ($request['supervisor_uid'] ?? '');

        if ($requestId === '' || ! $this->recipients()->canNotifySupervisor($supervisorUid)) {
            return;
        }

        $this->queue(
            $supervisorUid,
            FcmNotificationTypes::UNLINK_REQUEST,
            '/unlink-requests/'.$requestId,
            $requestId,
            'unlink-request-created',
            (string) ($request['created_at'] ?? now()->toIso8601String()),
            'high',
            'unlink',
            'unlink_request:'.$requestId.':'.$supervisorUid,
        );
    }

    public function sendConsentSuspended(array $consent): void
    {
        $consentId = (string) ($consent['consent_id'] ?? '');
        $supervisorUid = (string) ($consent['supervisor_uid'] ?? '');
        $transitionAt = (string) ($consent['paused_at'] ?? $consent['updated_at'] ?? '');

        if ($consentId === '' || $transitionAt === ''
            || ! in_array(($consent['status'] ?? null), ['paused', 'suspended'], true)
            || ! $this->recipients()->canNotifySupervisor($supervisorUid)) {
            return;
        }

        $entityId = $this->opaqueReference('consent', $consentId);
        $this->queue(
            $supervisorUid,
            FcmNotificationTypes::CONSENT_SUSPENDED,
            '/consents/'.$entityId,
            $entityId,
            'consent-suspended',
            $transitionAt,
            'high',
            'consent',
            'consent_suspended:'.$consentId.':'.$transitionAt.':'.$supervisorUid,
        );
    }

    public function sendInterventionUpdate(array $intervention, string $event = 'updated'): void
    {
        $interventionId = (string) ($intervention['intervention_id'] ?? '');
        $patientUid = (string) ($intervention['patient_uid'] ?? '');

        if ($interventionId === '' || ! $this->recipients()->canNotifyPatient($patientUid)) {
            return;
        }

        $this->queue(
            $patientUid,
            FcmNotificationTypes::INTERVENTION_UPDATE,
            '/interventions/'.$interventionId,
            $interventionId,
            'intervention-'.$event,
            $updatedAt = (string) ($intervention['updated_at'] ?? $intervention['created_at'] ?? now()->toIso8601String()),
            'normal',
            null,
            'intervention_update:'.$interventionId.':'.$updatedAt.':'.$patientUid,
        );
    }

    public function sendRelapseAlert(array $note): void
    {
        if (! $this->clinicalAlertsEnabled() || ($note['had_relapse'] ?? false) !== true) {
            return;
        }

        $this->sendClinicalAlert($note, FcmNotificationTypes::RELAPSE_ALERT, 'critical');
    }

    public function sendRiskAlert(array $note): void
    {
        $risk = (string) ($note['ai_risk_level'] ?? '');

        if (! $this->clinicalAlertsEnabled() || ! in_array($risk, ['high', 'critical'], true)) {
            return;
        }

        $this->sendClinicalAlert(
            $note,
            FcmNotificationTypes::RISK_ALERT,
            'critical',
        );
    }

    public function sendVulnerableUserPriority(
        string $patientUid,
        string $eventId,
        string $createdAt,
    ): void {
        if (! config('fcm.vulnerable_priority_enabled') || ! $this->clinicalAlertsEnabled()) {
            return;
        }

        $supervisorUid = $this->recipients()->authorizedSupervisorUidForPatient($patientUid);

        if ($supervisorUid === null || $eventId === '') {
            return;
        }

        $this->queue(
            $supervisorUid,
            FcmNotificationTypes::VULNERABLE_USER_PRIORITY,
            '/patients/priority',
            'priority_followup',
            'vulnerable-priority-'.$eventId,
            $createdAt,
            'high',
            'priority',
        );
    }

    public function sendUserValidationRequired(string $userId, string $createdAt): void
    {
        $this->sendAdminNotification(
            FcmNotificationTypes::USER_VALIDATION_REQUIRED,
            'user',
            $userId,
            '/admin/users/pending/',
            $createdAt,
        );
    }

    public function sendSupervisorValidationRequired(string $supervisorUid, string $createdAt): void
    {
        $this->sendAdminNotification(
            FcmNotificationTypes::SUPERVISOR_VALIDATION_REQUIRED,
            'supervisor',
            $supervisorUid,
            '/admin/supervisors/pending/',
            $createdAt,
        );
    }

    public function sendSupervisionRequestConflict(string $conflictId, string $createdAt): void
    {
        $this->sendAdminNotification(
            FcmNotificationTypes::SUPERVISION_REQUEST_CONFLICT,
            'conflict',
            $conflictId,
            '/admin/supervision-conflicts/',
            $createdAt,
        );
    }

    public function sendTestNotification(string $userId): void
    {
        $notificationId = 'test_'.Str::uuid()->toString();
        SendFcmNotification::dispatch(
            $userId,
            FcmNotificationTypes::TEST_NOTIFICATION,
            '',
            '',
            $notificationId,
            now()->toIso8601String(),
            'normal',
        );
    }

    private function sendClinicalAlertForNote(array $note): void
    {
        if (($note['had_relapse'] ?? false) === true) {
            $this->sendRelapseAlert($note);

            return;
        }

        $this->sendRiskAlert($note);
    }

    private function sendClinicalAlert(array $note, string $type, string $priority): void
    {
        $patientUid = (string) ($note['patient_uid'] ?? '');
        $noteId = (string) ($note['note_id'] ?? '');
        $supervisorUid = $this->recipients()->authorizedSupervisorUidForPatient($patientUid);

        if ($supervisorUid === null || $noteId === '') {
            return;
        }

        $this->queue(
            $supervisorUid,
            $type,
            '/patients/priority',
            'critical_followup',
            $type.'-'.$noteId,
            (string) ($note['created_at'] ?? now()->toIso8601String()),
            $priority,
            $type === FcmNotificationTypes::RELAPSE_ALERT ? 'relapse' : 'risk',
            $type.':'.$noteId.':'.$supervisorUid,
        );
    }

    public function sendSupervisorValidationApproved(string $supervisorUid, string $authorizedAt): void
    {
        if ($authorizedAt === '' || ! $this->recipients()->canNotifySupervisor($supervisorUid)) {
            return;
        }

        $entityId = $this->opaqueReference('approval', $supervisorUid.'|'.$authorizedAt);
        $this->queue(
            $supervisorUid,
            FcmNotificationTypes::SUPERVISOR_VALIDATION_APPROVED,
            '/home',
            $entityId,
            'supervisor-validation-approved',
            $authorizedAt,
            'normal',
            'approval',
            'supervisor_validation_approved:'.$supervisorUid.':'.$authorizedAt,
        );
    }

    private function sendAdminNotification(
        string $type,
        string $entityKind,
        string $sourceId,
        string $routePrefix,
        string $createdAt,
    ): void {
        if (! config('fcm.enabled')
            || ! config('fcm.admin_notifications_enabled')
            || $sourceId === '') {
            return;
        }

        $entityId = $this->opaqueReference($entityKind, $sourceId);

        foreach ($this->recipients()->activeAdminUids() as $adminUid) {
            $this->queue(
                $adminUid,
                $type,
                $routePrefix.$entityId,
                $entityId,
                $type.'-'.$sourceId,
                $createdAt,
                'normal',
                null,
                $type.':'.$sourceId.':'.$adminUid,
            );
        }
    }

    private function queue(
        string $userId,
        string $type,
        string $route,
        string $entityId,
        string $event,
        string $createdAt,
        string $priority = 'normal',
        ?string $notificationPrefix = null,
        ?string $dedupeKey = null,
    ): void {
        if (! config('fcm.enabled') || $userId === '' || $entityId === '') {
            return;
        }

        $notificationId = $this->notificationIdFor(
            $notificationPrefix,
            $type,
            $event,
            $entityId,
            $userId,
        );
        $dedupeKey ??= implode(':', [$type, $event, $entityId, $userId]);

        SendFcmNotification::dispatch(
            $userId,
            $type,
            $route,
            $entityId,
            $notificationId,
            $createdAt,
            $priority,
            hash('sha256', $dedupeKey),
        );
    }

    private function notificationId(string ...$parts): string
    {
        return 'rn_'.substr(hash('sha256', implode('|', $parts)), 0, 40);
    }

    private function notificationIdFor(?string $prefix, string ...$parts): string
    {
        if ($prefix === null) {
            return $this->notificationId(...$parts);
        }

        return 'rn_'.$prefix.'_'.substr(hash('sha256', implode('|', $parts)), 0, 32);
    }

    private function opaqueReference(string $kind, string $value): string
    {
        return substr($kind, 0, 3).'_'.substr(hash('sha256', $kind.'|'.$value), 0, 32);
    }

    private function clinicalAlertsEnabled(): bool
    {
        return (bool) config('fcm.enabled')
            && (bool) config('fcm.critical_alerts_enabled')
            && (bool) config('fcm.supervisor_clinical_alerts_enabled');
    }

    private function recipients(): FcmRecipientAuthorizer
    {
        return $this->authorizer ??= app(FcmRecipientAuthorizer::class);
    }

    private function safely(string $resource, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('FCM notification dispatch skipped.', [
                'resource' => $resource,
                'exception' => get_class($exception),
            ]);
        }
    }
}
