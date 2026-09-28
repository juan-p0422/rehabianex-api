<?php

namespace App\Services;

use App\Jobs\SendAppointmentReminder;
use App\Jobs\SendFcmNotification;
use App\Support\FcmNotificationTypes;
use Carbon\Carbon;
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
                'agenda-events' => $this->sendAppointmentReminder($data),
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
                'agenda-events' => $this->sendAppointmentReminder($data),
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

        SendAppointmentReminder::dispatch(
            $eventId,
            $patientUid,
            $startsAt,
            $this->notificationId('appointment', $eventId, $patientUid, $startsAt),
        )->delay(Carbon::parse($startsAt));
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
            (string) ($intervention['updated_at'] ?? $intervention['created_at'] ?? now()->toIso8601String()),
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
    ): void {
        if (! config('fcm.enabled') || $userId === '' || $entityId === '') {
            return;
        }

        SendFcmNotification::dispatch(
            $userId,
            $type,
            $route,
            $entityId,
            $this->notificationIdFor(
                $notificationPrefix,
                $type,
                $event,
                $entityId,
                $userId,
            ),
            $createdAt,
            $priority,
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
