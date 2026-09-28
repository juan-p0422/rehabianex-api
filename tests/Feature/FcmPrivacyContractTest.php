<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\UserFcmToken;
use App\Services\FcmNotificationDispatcher;
use App\Services\FcmRecipientAuthorizer;
use App\Services\FcmService;
use App\Support\FcmPayloadSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use RuntimeException;
use Tests\TestCase;

class FcmPrivacyContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_relapse_notifies_only_an_authorized_supervisor_with_opaque_data(): void
    {
        Queue::fake();
        $this->enableClinicalAlerts();
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('authorizedSupervisorUidForPatient')
            ->with('patient-secret-uid')
            ->willReturn('supervisor-1');

        (new FcmNotificationDispatcher($authorizer))->sendRelapseAlert($this->sensitiveNote());

        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job): bool {
            $serialized = json_encode($job, JSON_THROW_ON_ERROR);

            return $job->userId === 'supervisor-1'
                && $job->type === 'relapse_alert'
                && $job->priority === 'critical'
                && $job->route === '/patients/priority'
                && $job->entityId === 'critical_followup'
                && str_starts_with($job->notificationId, 'rn_relapse_')
                && ! str_contains($serialized, 'Paciente Privado')
                && ! str_contains($serialized, 'texto clínico privado');
        });
    }

    public function test_relapse_does_not_notify_an_unauthorized_supervisor(): void
    {
        Queue::fake();
        $this->enableClinicalAlerts();
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('authorizedSupervisorUidForPatient')->willReturn(null);

        (new FcmNotificationDispatcher($authorizer))->sendRelapseAlert($this->sensitiveNote());

        Queue::assertNothingPushed();
    }

    public function test_high_risk_notifies_an_authorized_supervisor(): void
    {
        Queue::fake();
        $this->enableClinicalAlerts();
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('authorizedSupervisorUidForPatient')->willReturn('supervisor-1');
        $note = $this->sensitiveNote();
        $note['had_relapse'] = false;
        $note['ai_risk_level'] = 'high';

        (new FcmNotificationDispatcher($authorizer))->sendRiskAlert($note);

        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->type === 'risk_alert'
            && $job->priority === 'critical'
            && $job->userId === 'supervisor-1'
            && $job->route === '/patients/priority'
            && $job->entityId === 'critical_followup'
            && str_starts_with($job->notificationId, 'rn_risk_')
        );
    }

    public function test_disabled_clinical_flags_prevent_dispatch(): void
    {
        Queue::fake();
        config([
            'fcm.enabled' => true,
            'fcm.critical_alerts_enabled' => false,
            'fcm.supervisor_clinical_alerts_enabled' => true,
        ]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->expects($this->never())->method('authorizedSupervisorUidForPatient');

        (new FcmNotificationDispatcher($authorizer))->sendRelapseAlert($this->sensitiveNote());

        Queue::assertNothingPushed();
    }

    public function test_admin_validation_targets_only_authorizer_approved_admins(): void
    {
        Queue::fake();
        config(['fcm.enabled' => true, 'fcm.admin_notifications_enabled' => true]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('activeAdminUids')->willReturn(['admin-1']);

        (new FcmNotificationDispatcher($authorizer))->sendSupervisorValidationRequired(
            'supervisor-sensitive-uid',
            '2026-09-23T12:00:00-06:00',
        );

        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job): bool {
            return $job->userId === 'admin-1'
                && $job->type === 'supervisor_validation_required'
                && $job->entityId !== 'supervisor-sensitive-uid'
                && ! str_contains($job->route ?? '', 'supervisor-sensitive-uid');
        });
        Queue::assertNotPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->userId === 'ordinary-user'
        );
    }

    public function test_unlink_request_targets_the_authorized_counterparty(): void
    {
        Queue::fake();
        config(['fcm.enabled' => true]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('canNotifySupervisor')->with('supervisor-1')->willReturn(true);

        (new FcmNotificationDispatcher($authorizer))->sendUnlinkRequest([
            'request_id' => 'unlink-1',
            'patient_uid' => 'patient-1',
            'supervisor_uid' => 'supervisor-1',
            'created_at' => '2026-09-23T12:00:00-06:00',
        ]);

        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->userId === 'supervisor-1'
            && $job->type === 'unlink_request'
            && $job->entityId === 'unlink-1'
            && $job->route === '/unlink-requests/unlink-1'
            && $job->priority === 'high'
            && str_starts_with($job->notificationId, 'rn_unlink_')
        );
    }

    public function test_supervision_request_is_not_sent_to_an_unauthorized_role(): void
    {
        Queue::fake();
        config(['fcm.enabled' => true]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('canNotifySupervisor')->willReturn(false);

        (new FcmNotificationDispatcher($authorizer))->sendSupervisionRequest([
            'request_id' => 'request-unauthorized',
            'supervisor_uid' => 'patient-role-user',
            'created_at' => '2026-09-23T12:00:00-06:00',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_admin_notification_is_not_sent_without_an_authorized_admin(): void
    {
        Queue::fake();
        config(['fcm.enabled' => true, 'fcm.admin_notifications_enabled' => true]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('activeAdminUids')->willReturn([]);

        (new FcmNotificationDispatcher($authorizer))->sendUserValidationRequired(
            'pending-user',
            '2026-09-23T12:00:00-06:00',
        );

        Queue::assertNothingPushed();
    }

    public function test_vulnerable_priority_never_exposes_patient_identity(): void
    {
        Queue::fake();
        config([
            'fcm.enabled' => true,
            'fcm.critical_alerts_enabled' => true,
            'fcm.supervisor_clinical_alerts_enabled' => true,
            'fcm.vulnerable_priority_enabled' => true,
        ]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('authorizedSupervisorUidForPatient')->willReturn('supervisor-1');

        (new FcmNotificationDispatcher($authorizer))->sendVulnerableUserPriority(
            'patient-secret-uid',
            'priority-event-1',
            '2026-09-23T12:00:00-06:00',
        );

        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->type === 'vulnerable_user_priority'
            && $job->route === '/patients/priority'
            && $job->entityId === 'priority_followup'
            && str_starts_with($job->notificationId, 'rn_priority_')
        );
    }

    public function test_payload_sanitizer_rejects_sensitive_and_arbitrary_fields(): void
    {
        foreach (FcmPayloadSanitizer::FORBIDDEN_FIELDS as $field) {
            try {
                FcmPayloadSanitizer::sanitize($this->safePayload() + [$field => 'secret']);
                $this->fail("El campo {$field} debía rechazarse.");
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'El payload FCM no cumple el contrato de campos permitido.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_deprecated_unlink_and_clinical_detail_routes_are_rejected(): void
    {
        $payloads = [
            array_replace($this->safePayload(), [
                'type' => 'unlink_request',
                'body' => 'Tienes una solicitud pendiente.',
                'route' => '/supervision-requests/unlink-1',
                'entity_id' => 'unlink-1',
                'priority' => 'high',
            ]),
            array_replace($this->safePayload(), [
                'route' => '/patients/patient-uid/risk',
                'entity_id' => 'patient-uid',
            ]),
        ];

        foreach ($payloads as $payload) {
            try {
                FcmPayloadSanitizer::sanitize($payload);
                $this->fail('La ruta FCM obsoleta debía rechazarse.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'La ruta FCM no coincide con el tipo y la entidad.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_transport_only_types_are_documented_and_have_no_false_generic_trigger(): void
    {
        Queue::fake();
        config(['fcm.enabled' => true]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $dispatcher = new FcmNotificationDispatcher($authorizer);

        foreach (['notification-settings', 'patient-achievements', 'patients'] as $resource) {
            $dispatcher->created($resource, ['id' => 'not-a-domain-event']);
            $dispatcher->updated($resource, ['id' => 'not-a-domain-event']);
        }

        Queue::assertNothingPushed();

        $documentation = file_get_contents(base_path('docs/FCM_LOCAL_MIGRATION.md'));

        foreach ([
            'progress_checkin',
            'sober_day_update',
            'achievement_unlocked',
            'vulnerable_user_priority',
            'user_validation_required',
            'supervision_request_conflict',
            'system_notice',
            'test_notification',
        ] as $type) {
            $this->assertStringContainsString("`{$type}` | `transport_only`", $documentation);
        }
    }

    public function test_existing_domain_events_dispatch_unlink_relapse_and_risk_notifications(): void
    {
        Queue::fake();
        $this->enableClinicalAlerts();
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('canNotifySupervisor')
            ->with('supervisor-1')
            ->willReturn(true);
        $authorizer->method('authorizedSupervisorUidForPatient')
            ->with('patient-secret-uid')
            ->willReturn('supervisor-1');

        $dispatcher = new FcmNotificationDispatcher($authorizer);
        $dispatcher->created('supervision-requests', [
            'request_id' => 'unlink-domain-1',
            'patient_uid' => 'patient-secret-uid',
            'supervisor_uid' => 'supervisor-1',
            'type' => 'unlink',
            'created_at' => '2026-09-26T12:00:00-06:00',
        ]);
        $dispatcher->created('patient-notes', [
            'note_id' => 'relapse-domain-1',
            'patient_uid' => 'patient-secret-uid',
            'had_relapse' => true,
            'ai_risk_level' => 'critical',
            'created_at' => '2026-09-26T12:01:00-06:00',
        ]);
        $dispatcher->created('patient-notes', [
            'note_id' => 'risk-domain-1',
            'patient_uid' => 'patient-secret-uid',
            'had_relapse' => false,
            'ai_risk_level' => 'high',
            'created_at' => '2026-09-26T12:02:00-06:00',
        ]);

        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->type === 'unlink_request'
            && $job->userId === 'supervisor-1'
            && $job->route === '/unlink-requests/unlink-domain-1'
            && $job->entityId === 'unlink-domain-1'
        );
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->type === 'relapse_alert'
            && $job->userId === 'supervisor-1'
            && $job->route === '/patients/priority'
            && $job->entityId === 'critical_followup'
        );
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->type === 'risk_alert'
            && $job->userId === 'supervisor-1'
            && $job->route === '/patients/priority'
            && $job->entityId === 'critical_followup'
        );
        Queue::assertPushed(SendFcmNotification::class, 3);
    }

    public function test_transport_only_contracts_use_individual_transport_without_domain_triggers(): void
    {
        Queue::fake();
        config([
            'fcm.enabled' => true,
            'fcm.admin_notifications_enabled' => true,
            'fcm.critical_alerts_enabled' => true,
            'fcm.supervisor_clinical_alerts_enabled' => true,
            'fcm.vulnerable_priority_enabled' => true,
        ]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('canNotifyPatient')
            ->with('patient-transport')
            ->willReturn(true);
        $authorizer->method('authorizedSupervisorUidForPatient')
            ->with('patient-transport')
            ->willReturn('supervisor-transport');
        $authorizer->method('activeAdminUids')
            ->willReturn(['admin-transport']);

        $dispatcher = new FcmNotificationDispatcher($authorizer);
        $createdAt = '2026-09-26T13:00:00-06:00';
        $dispatcher->sendProgressCheckin('patient-transport', 'checkin-transport', $createdAt);
        $dispatcher->sendSoberDayUpdate('patient-transport', 'sober-transport', $createdAt);
        $dispatcher->sendAchievementUnlocked([
            'patient_uid' => 'patient-transport',
            'achievement_id' => 'achievement-transport',
            'unlocked_at' => $createdAt,
        ]);
        $dispatcher->sendVulnerableUserPriority('patient-transport', 'priority-transport', $createdAt);
        $dispatcher->sendUserValidationRequired('user-pending-transport', $createdAt);
        $dispatcher->sendSupervisionRequestConflict('conflict-transport', $createdAt);

        foreach ([
            'progress_checkin',
            'sober_day_update',
            'achievement_unlocked',
            'vulnerable_user_priority',
            'user_validation_required',
            'supervision_request_conflict',
        ] as $type) {
            Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->type === $type
            );
        }

        Queue::assertPushed(SendFcmNotification::class, 6);
    }

    public function test_cloud_message_is_data_only_without_sensitive_fields_topics_or_multicast(): void
    {
        config(['fcm.enabled' => true, 'fcm.dry_run' => true]);
        $this->activeToken('supervisor-1');
        $captured = [];
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (CloudMessage $message) use (&$captured): array {
                $captured = $message->jsonSerialize();

                return [];
            });

        (new FcmService($messaging))->sendToUser(
            'supervisor-1',
            'risk_alert',
            '/patients/priority',
            'critical_followup',
            'rn_safe_contract',
            '2026-09-23T12:00:00-06:00',
            'critical',
        );

        $this->assertArrayNotHasKey('notification', $captured);
        $this->assertArrayNotHasKey('topic', $captured);
        $this->assertSame(FcmPayloadSanitizer::FIELDS, array_keys($captured['data']));

        foreach (FcmPayloadSanitizer::FORBIDDEN_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $captured['data']);
        }

        $serviceSource = file_get_contents(app_path('Services/FcmService.php'));
        $this->assertStringNotContainsString('sendMulticast', $serviceSource);
        $this->assertStringNotContainsString('toTopic', $serviceSource);
    }

    public function test_supervision_conflict_uses_the_canonical_validation_body(): void
    {
        config(['fcm.enabled' => true, 'fcm.dry_run' => true]);
        $this->activeToken('admin-1');
        $captured = [];
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (CloudMessage $message) use (&$captured): array {
                $captured = $message->jsonSerialize();

                return [];
            });

        (new FcmService($messaging))->sendToUser(
            'admin-1',
            'supervision_request_conflict',
            '/admin/supervision-conflicts/conflict_followup',
            'conflict_followup',
            'rn_conflict_contract',
            '2026-09-24T12:00:00-06:00',
            'normal',
        );

        $this->assertSame(
            'Hay una validación pendiente.',
            $captured['data']['body'],
        );
        $this->assertArrayNotHasKey('notification', $captured);
    }

    private function enableClinicalAlerts(): void
    {
        config([
            'fcm.enabled' => true,
            'fcm.critical_alerts_enabled' => true,
            'fcm.supervisor_clinical_alerts_enabled' => true,
        ]);
    }

    private function sensitiveNote(): array
    {
        return [
            'note_id' => 'note-1',
            'patient_uid' => 'patient-secret-uid',
            'patient_name' => 'Paciente Privado',
            'note_text' => 'texto clínico privado',
            'craving_level' => 10,
            'anxiety_level' => 10,
            'diagnosis' => 'dato prohibido',
            'had_relapse' => true,
            'ai_risk_level' => 'critical',
            'created_at' => '2026-09-23T12:00:00-06:00',
        ];
    }

    private function safePayload(): array
    {
        return [
            'type' => 'risk_alert',
            'title' => 'Rehabianex',
            'body' => 'Hay una actualización importante de seguimiento.',
            'route' => '/patients/priority',
            'entity_id' => 'critical_followup',
            'notification_id' => 'rn_safe',
            'created_at' => '2026-09-23T12:00:00-06:00',
            'priority' => 'critical',
        ];
    }

    private function activeToken(string $userId): void
    {
        $token = new UserFcmToken([
            'fcm_token' => str_repeat('safe-token-', 10),
            'platform' => 'android',
        ]);
        $token->user_id = $userId;
        $token->is_active = true;
        $token->save();
    }
}
