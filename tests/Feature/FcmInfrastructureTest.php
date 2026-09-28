<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\FcmTokenController;
use App\Http\Controllers\Api\FirestoreCrudController;
use App\Http\Controllers\Api\SupervisionController;
use App\Jobs\SendAppointmentReminder;
use App\Jobs\SendFcmNotification;
use App\Models\User;
use App\Models\UserFcmToken;
use App\Services\FcmNotificationDispatcher;
use App\Services\FcmRecipientAuthorizer;
use App\Services\FcmService;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FcmInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_firestore_crud_controller_resolves_the_fcm_dispatcher(): void
    {
        $firebase = $this->createMock(FirebaseService::class);
        $firebase->expects($this->once())->method('db')->willReturn(new \stdClass);
        $dispatcher = $this->createMock(FcmNotificationDispatcher::class);

        $this->app->instance(FirebaseService::class, $firebase);
        $this->app->instance(FirestoreAccessService::class, $this->createStub(FirestoreAccessService::class));
        $this->app->instance(FcmNotificationDispatcher::class, $dispatcher);

        $controller = $this->app->make(FirestoreCrudController::class);
        $property = new \ReflectionProperty($controller, 'fcmNotifications');

        $this->assertSame($dispatcher, $property->getValue($controller));
    }

    public function test_supervision_controller_resolves_the_fcm_dispatcher(): void
    {
        $firebase = $this->createMock(FirebaseService::class);
        $firebase->expects($this->once())->method('db')->willReturn(new \stdClass);
        $dispatcher = $this->createMock(FcmNotificationDispatcher::class);

        $this->app->instance(FirebaseService::class, $firebase);
        $this->app->instance(FirestoreAccessService::class, $this->createStub(FirestoreAccessService::class));
        $this->app->instance(FcmNotificationDispatcher::class, $dispatcher);

        $controller = $this->app->make(SupervisionController::class);
        $property = new \ReflectionProperty($controller, 'fcmNotifications');

        $this->assertSame($dispatcher, $property->getValue($controller));
    }

    public function test_fcm_routes_are_protected_by_firebase_authentication(): void
    {
        foreach ([
            ['POST', 'api/notifications/fcm-token'],
            ['DELETE', 'api/notifications/fcm-token'],
            ['POST', 'api/notifications/test'],
        ] as [$method, $uri]) {
            $route = Route::getRoutes()->match(request()->create($uri, $method));

            $this->assertContains('firebase.auth', $route->gatherMiddleware());
        }
    }

    public function test_fcm_token_is_hidden_and_casts_operational_fields(): void
    {
        $token = new UserFcmToken([
            'fcm_token' => str_repeat('a', 80),
            'platform' => 'android',
        ]);
        $token->user_id = 'firebase-user-id';
        $token->is_active = true;
        $token->last_seen_at = now();

        $serialized = $token->toArray();

        $this->assertArrayNotHasKey('fcm_token', $serialized);
        $this->assertTrue($token->is_active);
        $this->assertInstanceOf(BelongsTo::class, $token->user());
        $this->assertInstanceOf(HasMany::class, (new User)->fcmTokens());
    }

    public function test_registration_uses_authenticated_firebase_uid_and_revoke_is_scoped(): void
    {
        $rawToken = str_repeat('token-', 20);
        $registerRequest = Request::create('/api/notifications/fcm-token', 'POST', [
            'fcm_token' => $rawToken,
            'platform' => 'android',
            'device_id' => 'test-device',
            'timezone' => 'America/Mexico_City',
        ]);
        $registerRequest->attributes->set('firebase_uid', 'firebase-user-id');

        $response = (new FcmTokenController)->register($registerRequest);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString($rawToken, $response->getContent());
        $this->assertDatabaseHas('user_fcm_tokens', [
            'user_id' => 'firebase-user-id',
            'fcm_token' => $rawToken,
            'is_active' => true,
        ]);

        $revokeRequest = Request::create('/api/notifications/fcm-token', 'DELETE', [
            'fcm_token' => $rawToken,
        ]);
        $revokeRequest->attributes->set('firebase_uid', 'firebase-user-id');

        $revokeResponse = (new FcmTokenController)->revoke($revokeRequest);

        $this->assertSame(204, $revokeResponse->getStatusCode());
        $this->assertStringNotContainsString($rawToken, $revokeResponse->getContent());
        $this->assertDatabaseHas('user_fcm_tokens', [
            'user_id' => 'firebase-user-id',
            'fcm_token' => $rawToken,
            'is_active' => false,
        ]);

        $secondRevokeResponse = (new FcmTokenController)->revoke($revokeRequest);

        $this->assertSame(204, $secondRevokeResponse->getStatusCode());
    }

    public function test_same_device_token_moves_between_roles_and_only_current_owner_can_revoke_it(): void
    {
        $rawToken = str_repeat('role-switch-token-', 8);
        $patientRequest = Request::create('/api/notifications/fcm-token', 'POST', [
            'fcm_token' => $rawToken,
            'platform' => 'android',
            'device_id' => 'qa-role-switch-device',
        ]);
        $patientRequest->attributes->set('firebase_uid', 'qa-patient');
        (new FcmTokenController)->register($patientRequest);

        $supervisorRequest = Request::create('/api/notifications/fcm-token', 'POST', [
            'fcm_token' => $rawToken,
            'platform' => 'android',
            'device_id' => 'qa-role-switch-device',
        ]);
        $supervisorRequest->attributes->set('firebase_uid', 'qa-supervisor');
        (new FcmTokenController)->register($supervisorRequest);

        $this->assertDatabaseCount('user_fcm_tokens', 1);
        $this->assertDatabaseHas('user_fcm_tokens', [
            'user_id' => 'qa-supervisor',
            'is_active' => true,
        ]);

        $oldOwnerRevoke = Request::create('/api/notifications/fcm-token', 'DELETE', [
            'fcm_token' => $rawToken,
        ]);
        $oldOwnerRevoke->attributes->set('firebase_uid', 'qa-patient');
        (new FcmTokenController)->revoke($oldOwnerRevoke);

        $this->assertDatabaseHas('user_fcm_tokens', [
            'user_id' => 'qa-supervisor',
            'is_active' => true,
            'revoked_at' => null,
        ]);

        $currentOwnerRevoke = Request::create('/api/notifications/fcm-token', 'DELETE', [
            'fcm_token' => $rawToken,
        ]);
        $currentOwnerRevoke->attributes->set('firebase_uid', 'qa-supervisor');
        (new FcmTokenController)->revoke($currentOwnerRevoke);

        $this->assertDatabaseHas('user_fcm_tokens', [
            'user_id' => 'qa-supervisor',
            'is_active' => false,
        ]);
        $this->assertNotNull(UserFcmToken::query()->firstOrFail()->revoked_at);
    }

    public function test_endpoint_sends_data_only_validation_to_authenticated_users_active_tokens(): void
    {
        config([
            'fcm.enabled' => true,
            'fcm.dry_run' => true,
        ]);

        $this->activeToken('test-user', 'safe-one');
        $this->activeToken('test-user', 'safe-two');

        $messages = [];
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (CloudMessage $message, bool $validateOnly) use (&$messages): array {
                $this->assertTrue($validateOnly);
                $messages[] = $message->jsonSerialize();

                return [];
            });

        $request = Request::create('/api/notifications/test', 'POST');
        $request->attributes->set('firebase_uid', 'test-user');
        $response = (new FcmTokenController)->test($request, new FcmService($messaging));
        $responseData = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($responseData['ok']);
        $this->assertTrue($responseData['sent']);
        $this->assertSame('validation_only', $responseData['mode']);
        $this->assertSame(2, $responseData['processed_tokens']);
        $this->assertSame(2, $responseData['successful_tokens']);
        $this->assertMatchesRegularExpression('/^test_[0-9a-f-]{36}$/', $responseData['notification_id']);

        $messageData = $messages[0];

        $this->assertArrayNotHasKey('notification', $messageData);
        $this->assertSame('test_notification', $messageData['data']['type']);
        $this->assertSame('Rehabianex', $messageData['data']['title']);
        $this->assertSame('Tienes una actualización en Rehabianex.', $messageData['data']['body']);
        $this->assertSame('', $messageData['data']['route']);
        $this->assertSame('', $messageData['data']['entity_id']);
        $this->assertSame($responseData['notification_id'], $messageData['data']['notification_id']);
        $this->assertSame($responseData['notification_id'], $messageData['android']['collapse_key']);
        $this->assertArrayNotHasKey('notification', $messageData['android']);
        $this->assertSame(
            $messages[0]['data']['notification_id'],
            $messages[1]['data']['notification_id'],
        );
        $this->assertStringNotContainsString('safe-one-token', $response->getContent());
        $this->assertStringNotContainsString('safe-two-token', $response->getContent());
    }

    public function test_endpoint_returns_not_found_when_authenticated_user_has_no_active_token(): void
    {
        config([
            'fcm.enabled' => true,
            'fcm.dry_run' => true,
        ]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('send');
        $request = Request::create('/api/notifications/test', 'POST');
        $request->attributes->set('firebase_uid', 'user-without-token');

        try {
            (new FcmTokenController)->test($request, new FcmService($messaging));
            $this->fail('El endpoint debía responder 404 sin tokens activos.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_test_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/notifications/test')->assertUnauthorized();
    }

    public function test_endpoint_rejects_disabled_fcm_and_arbitrary_payload(): void
    {
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('send');
        $service = new FcmService($messaging);

        config(['fcm.enabled' => false]);
        $disabledRequest = Request::create('/api/notifications/test', 'POST');
        $disabledRequest->attributes->set('firebase_uid', 'test-user');

        try {
            (new FcmTokenController)->test($disabledRequest, $service);
            $this->fail('El endpoint debía rechazar FCM deshabilitado.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        config(['fcm.enabled' => true]);
        $payloadRequest = Request::create('/api/notifications/test', 'POST', [
            'title' => 'Texto arbitrario',
            'fcm_token' => str_repeat('forbidden-token-', 4),
        ]);
        $payloadRequest->attributes->set('firebase_uid', 'test-user');

        try {
            (new FcmTokenController)->test($payloadRequest, $service);
            $this->fail('El endpoint debía rechazar cualquier payload.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_endpoint_blocks_real_delivery_outside_local_or_staging(): void
    {
        config([
            'fcm.enabled' => true,
            'fcm.dry_run' => false,
        ]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('send');
        $request = Request::create('/api/notifications/test', 'POST');
        $request->attributes->set('firebase_uid', 'test-user');

        try {
            (new FcmTokenController)->test($request, new FcmService($messaging));
            $this->fail('El envío real debía quedar bloqueado fuera de local/staging.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_delivery_to_test_user_uses_safe_minimal_payload_and_dedupe_keys(): void
    {
        config([
            'fcm.enabled' => true,
            'fcm.dry_run' => true,
        ]);

        $token = $this->activeToken('test-user');
        $messageData = [];
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (CloudMessage $message, bool $validateOnly) use (&$messageData): array {
                $this->assertTrue($validateOnly);
                $messageData = $message->jsonSerialize();

                return [];
            });

        $result = (new FcmService($messaging))->sendToUser(
            'test-user',
            'appointment_reminder',
            '/agenda-events/event-1',
            'event-1',
            'rn_deterministic',
            '2026-09-12T12:00:00-06:00',
        );

        $this->assertSame('processed', $result['status']);
        $this->assertSame(1, $result['sent']);
        $this->assertArrayNotHasKey('notification', $messageData);
        $this->assertSame([
            'type' => 'appointment_reminder',
            'title' => 'Rehabianex',
            'body' => 'Tienes un recordatorio pendiente.',
            'route' => '/agenda-events/event-1',
            'entity_id' => 'event-1',
            'notification_id' => 'rn_deterministic',
            'created_at' => '2026-09-12T12:00:00-06:00',
            'priority' => 'normal',
        ], $messageData['data']);
        $this->assertSame('rn_deterministic', $messageData['android']['collapse_key']);
        $this->assertArrayNotHasKey('notification', $messageData['android']);
        $this->assertTrue($token->fresh()->is_active);
    }

    public function test_disabled_fcm_and_user_without_active_tokens_do_not_send(): void
    {
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('send');
        $service = new FcmService($messaging);

        config(['fcm.enabled' => false]);
        $disabled = $service->sendToUser(
            'test-user', 'system_notice', '/', null, 'rn_disabled', now()->toIso8601String()
        );

        config(['fcm.enabled' => true]);
        $withoutTokens = $service->sendToUser(
            'missing-user',
            'system_notice',
            '/interventions/intervention-1',
            'intervention-1',
            'rn_missing',
            now()->toIso8601String(),
        );

        $this->assertSame('disabled', $disabled['status']);
        $this->assertSame('no_active_tokens', $withoutTokens['status']);
    }

    public function test_invalid_fcm_token_is_marked_inactive_without_exposing_it(): void
    {
        config([
            'fcm.enabled' => true,
            'fcm.dry_run' => true,
        ]);

        $token = $this->activeToken('test-user');
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->willThrowException(new NotFound('Token no registrado.'));

        $result = (new FcmService($messaging))->sendSafeTestToUser('test-user');

        $this->assertSame(1, $result['invalid']);
        $this->assertSame(0, $result['sent']);
        $this->assertMatchesRegularExpression('/^test_[0-9a-f-]{36}$/', $result['notification_id']);
        $this->assertFalse($token->fresh()->is_active);
        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_only_active_non_revoked_android_tokens_are_selected(): void
    {
        config([
            'fcm.enabled' => true,
            'fcm.dry_run' => true,
        ]);

        $this->activeToken('test-user', 'active');
        $this->activeToken('test-user', 'inactive')->forceFill(['is_active' => false])->save();
        $this->activeToken('test-user', 'revoked')->forceFill(['revoked_at' => now()])->save();
        $this->activeToken('test-user', 'ios')->forceFill(['platform' => 'ios'])->save();

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())->method('send')->willReturn([]);

        $result = (new FcmService($messaging))->sendToUser(
            'test-user',
            'system_notice',
            '/interventions/intervention-1',
            'intervention-1',
            'rn_active_android_only',
            now()->toIso8601String(),
        );

        $this->assertSame(1, $result['sent']);
    }

    public function test_revoke_validation_requires_a_well_formed_token(): void
    {
        $request = Request::create('/api/notifications/fcm-token', 'DELETE', [
            'fcm_token' => 'short',
        ]);
        $request->attributes->set('firebase_uid', 'firebase-user-id');

        try {
            (new FcmTokenController)->revoke($request);
            $this->fail('La revocación debía rechazar un token corto.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('fcm_token', $exception->errors());
        }
    }

    public function test_revoke_route_requires_authentication(): void
    {
        $response = $this->deleteJson('/api/notifications/fcm-token', [
            'fcm_token' => str_repeat('unauthenticated-token-', 4),
        ]);

        $response->assertUnauthorized();
    }

    public function test_backend_rejects_types_outside_the_official_contract(): void
    {
        config(['fcm.enabled' => true]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('send');
        $service = new FcmService($messaging);

        foreach (['exercise_reminder', 'jitai_prompt'] as $type) {
            try {
                $service->sendToUser(
                    'test-user',
                    $type,
                    '/agenda-events/event-1',
                    'event-1',
                    'rn_reserved',
                    now()->toIso8601String(),
                );
                $this->fail("El tipo {$type} no debe poder emitirse.");
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Tipo FCM no soportado.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_route_must_match_type_and_entity(): void
    {
        config(['fcm.enabled' => true]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('send');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La ruta FCM no coincide con el tipo y la entidad.');

        (new FcmService($messaging))->sendToUser(
            'test-user',
            'appointment_reminder',
            '/agenda-events/different-event',
            'event-1',
            'rn_route_mismatch',
            now()->toIso8601String(),
        );
    }

    public function test_only_approved_resources_map_to_unique_single_user_jobs(): void
    {
        Queue::fake();
        config(['fcm.enabled' => true]);
        $authorizer = $this->createMock(FcmRecipientAuthorizer::class);
        $authorizer->method('canNotifySupervisor')->willReturn(true);
        $authorizer->method('canNotifyPatient')->willReturn(true);
        $dispatcher = new FcmNotificationDispatcher($authorizer);

        $agenda = [
            'event_id' => 'event-1',
            'patient_uid' => 'patient-1',
            'starts_at' => '2026-10-01T12:00:00-06:00',
            'status' => 'scheduled',
        ];

        $dispatcher->created('agenda-events', $agenda);
        $dispatcher->created('agenda-events', $agenda);
        $dispatcher->created('supervision-requests', [
            'request_id' => 'request-1',
            'patient_uid' => 'patient-1',
            'supervisor_uid' => 'supervisor-1',
            'created_at' => '2026-09-12T12:00:00-06:00',
        ]);
        $dispatcher->created('interventions', [
            'intervention_id' => 'intervention-1',
            'patient_uid' => 'patient-1',
            'created_at' => '2026-09-12T12:00:00-06:00',
        ]);
        $dispatcher->created('patient-notes', [
            'note_id' => 'note-1',
            'patient_uid' => 'patient-1',
        ]);

        $appointmentJobs = Queue::pushed(SendAppointmentReminder::class);
        $this->assertNotEmpty($appointmentJobs);
        $this->assertCount(1, $appointmentJobs
            ->map(fn (SendAppointmentReminder $job): string => $job->notificationId)
            ->unique());
        $this->assertInstanceOf(ShouldBeUnique::class, $appointmentJobs->first());

        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job): bool {
            return $job->userId === 'supervisor-1'
                && $job->type === 'supervision_request'
                && $job->entityId === 'request-1';
        });
        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job): bool {
            return $job->userId === 'patient-1'
                && $job->type === 'intervention_update'
                && $job->entityId === 'intervention-1';
        });
        Queue::assertNotPushed(SendFcmNotification::class, function (SendFcmNotification $job): bool {
            return $job->entityId === 'note-1';
        });
    }

    private function activeToken(string $userId, string $marker = 'active'): UserFcmToken
    {
        $token = new UserFcmToken([
            'fcm_token' => str_repeat($marker.'-token-', 10),
            'platform' => 'android',
        ]);
        $token->user_id = $userId;
        $token->is_active = true;
        $token->last_seen_at = now();
        $token->save();

        return $token;
    }
}
