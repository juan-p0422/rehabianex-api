<?php

namespace Tests\Feature;

use App\Contracts\FcmTokenRepository;
use App\Http\Controllers\Api\FcmDemoController;
use App\Services\FcmService;
use App\Support\FcmDemoCatalog;
use App\Support\FcmNotificationTypes;
use App\Support\FcmSafeTexts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\InMemoryFcmTokenRepository;
use Tests\TestCase;

class FcmDemoEndpointTest extends TestCase
{
    private InMemoryFcmTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fcm.enabled' => true,
            'fcm.dry_run' => true,
            'fcm.demo.enabled' => true,
            'fcm.production_send_enabled' => false,
        ]);

        $this->tokens = new InMemoryFcmTokenRepository;
        $this->app->instance(FcmTokenRepository::class, $this->tokens);
    }

    public function test_demo_route_is_authenticated_and_rate_limited_by_the_named_uid_limiter(): void
    {
        $route = Route::getRoutes()->match(request()->create('api/notifications/demo', 'POST'));

        $this->assertContains('firebase.auth', $route->gatherMiddleware());
        $this->assertContains('throttle:fcm-demo', $route->gatherMiddleware());
        $this->postJson('/api/notifications/demo', ['type' => 'appointment_reminder'])
            ->assertUnauthorized();
    }

    public function test_catalog_covers_all_18_types_and_each_type_has_at_least_one_compatible_role(): void
    {
        $covered = array_values(array_unique(array_merge(...array_values(FcmDemoCatalog::TYPES_BY_ROLE))));

        sort($covered);
        $expected = FcmNotificationTypes::ALL;
        sort($expected);

        $this->assertCount(18, $covered);
        $this->assertSame($expected, $covered);
    }

    public function test_demo_rate_limit_key_uses_firebase_uid_instead_of_shared_ip(): void
    {
        $limiter = RateLimiter::limiter('fcm-demo');
        $first = Request::create('/api/notifications/demo', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $sameUserOtherIp = Request::create('/api/notifications/demo', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.11']);
        $otherUserSameIp = Request::create('/api/notifications/demo', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $first->attributes->set('firebase_uid', 'first-user');
        $sameUserOtherIp->attributes->set('firebase_uid', 'first-user');
        $otherUserSameIp->attributes->set('firebase_uid', 'second-user');

        $firstLimit = $limiter($first);
        $sameUserLimit = $limiter($sameUserOtherIp);
        $otherUserLimit = $limiter($otherUserSameIp);

        $this->assertSame($firstLimit->key, $sameUserLimit->key);
        $this->assertNotSame($firstLimit->key, $otherUserLimit->key);
        $this->assertSame(30, $firstLimit->maxAttempts);
    }

    public function test_each_demo_type_sends_only_to_the_authenticated_users_active_token(): void
    {
        $messages = [];
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->exactly(18))
            ->method('send')
            ->willReturnCallback(function (CloudMessage $message, bool $validateOnly) use (&$messages): array {
                $this->assertTrue($validateOnly);
                $messages[] = $message->jsonSerialize();

                return [];
            });

        $service = new FcmService($this->tokens, $messaging);
        $controller = new FcmDemoController;
        $sentTypes = [];

        foreach (FcmDemoCatalog::TYPES_BY_ROLE as $role => $types) {
            $uid = 'demo-'.$role;
            $this->tokens->seedToken($uid, str_repeat($role.'-token-', 10));
            $this->tokens->seedToken('another-user', str_repeat('other-token-', 10));

            foreach ($types as $type) {
                if (in_array($type, $sentTypes, true)) {
                    continue;
                }

                $request = Request::create('/api/notifications/demo', 'POST', ['type' => $type]);
                $request->attributes->set('firebase_uid', $uid);
                $request->attributes->set('firebase_role', $role);

                $response = $controller($request, $service);
                $data = $response->getData(true);

                $this->assertSame(200, $response->getStatusCode());
                $this->assertTrue($data['ok']);
                $this->assertTrue($data['sent']);
                $this->assertSame($type, $data['type']);
                $this->assertSame($data['notification_id'], $data['notification']['notification_id']);
                $this->assertSame('validation_only', $data['mode']);
                $this->assertSame($type, $data['notification']['type']);
                $this->assertSame(FcmSafeTexts::TITLE, $data['notification']['title']);
                $this->assertSame(FcmSafeTexts::bodyFor($type), $data['notification']['body']);
                $this->assertStringStartsWith('demo_', $data['notification']['entity_id']);
                $this->assertStringStartsWith('demo_', $data['notification']['notification_id']);
                $this->assertSame(1, $data['delivery']['processed_tokens']);
                $sentTypes[] = $type;
            }
        }

        $this->assertCount(18, $sentTypes);
        $this->assertCount(18, $messages);

        foreach ($messages as $message) {
            $this->assertArrayNotHasKey('notification', $message);
            $this->assertContains($message['data']['type'], FcmNotificationTypes::ALL);
            $this->assertSame(FcmSafeTexts::bodyFor($message['data']['type']), $message['data']['body']);
            $this->assertStringStartsWith('demo_', $message['data']['entity_id']);
            $this->assertStringStartsWith('demo_', $message['data']['notification_id']);
        }
    }

    public function test_demo_rejects_a_type_that_does_not_match_the_authenticated_role(): void
    {
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('send');
        $request = Request::create('/api/notifications/demo', 'POST', [
            'type' => FcmNotificationTypes::RELAPSE_ALERT,
        ]);
        $request->attributes->set('firebase_uid', 'patient-user');
        $request->attributes->set('firebase_role', 'patient');

        try {
            (new FcmDemoController)($request, new FcmService($this->tokens, $messaging));
            $this->fail('El endpoint debía rechazar un tipo incompatible con el rol.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_production_demo_without_explicit_switch_remains_validation_only(): void
    {
        config(['fcm.dry_run' => false, 'fcm.production_send_enabled' => false]);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->tokens->seedToken('patient-user', str_repeat('patient-token-', 8));

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(CloudMessage::class), true)
            ->willReturn([]);
        $request = Request::create('/api/notifications/demo', 'POST', [
            'type' => FcmNotificationTypes::APPOINTMENT_REMINDER,
        ]);
        $request->attributes->set('firebase_uid', 'patient-user');
        $request->attributes->set('firebase_role', 'patient');

        $response = (new FcmDemoController)($request, new FcmService($this->tokens, $messaging));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('validation_only', $response->getData(true)['mode']);
    }

    public function test_explicit_production_switch_overrides_global_dry_run_only_for_demo(): void
    {
        config(['fcm.dry_run' => true, 'fcm.production_send_enabled' => true]);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->tokens->seedToken('patient-user', str_repeat('patient-token-', 8));

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(CloudMessage::class), false)
            ->willReturn([]);
        $request = Request::create('/api/notifications/demo', 'POST', [
            'type' => FcmNotificationTypes::APPOINTMENT_REMINDER,
        ]);
        $request->attributes->set('firebase_uid', 'patient-user');
        $request->attributes->set('firebase_role', 'patient');

        $response = (new FcmDemoController)(
            $request,
            new FcmService($this->tokens, $messaging),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('real_delivery', $response->getData(true)['mode']);
    }

    public function test_production_switch_does_not_override_dry_run_for_regular_service_calls(): void
    {
        config(['fcm.dry_run' => true, 'fcm.production_send_enabled' => true]);
        $this->tokens->seedToken('patient-user', str_repeat('patient-token-', 8));

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(CloudMessage::class), true)
            ->willReturn([]);

        (new FcmService($this->tokens, $messaging))->sendToUser(
            'patient-user',
            FcmNotificationTypes::APPOINTMENT_REMINDER,
            '/agenda-events/domain-event',
            'domain-event',
            'domain-notification',
            now()->toIso8601String(),
        );
    }
}
