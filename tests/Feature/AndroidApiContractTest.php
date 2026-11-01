<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateFirebase;
use App\Http\Middleware\RequirePrivacyNoticeRead;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AndroidApiContractTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requiredEndpoints(): array
    {
        return [
            'register' => ['POST', 'api/auth/register'],
            'login' => ['POST', 'api/auth/login'],
            'refresh' => ['POST', 'api/auth/refresh'],
            'logout' => ['POST', 'api/auth/logout'],
            'me' => ['GET', 'api/auth/me'],
            'sync verified email' => ['POST', 'api/auth/email/sync'],
            'data control summary' => ['GET', 'api/data-control/summary'],
            'data export' => ['GET', 'api/data-control/export'],
            'account deletion request' => ['POST', 'api/data-control/account-deletion-requests'],
            'account deactivation request' => ['POST', 'api/data-control/account-deactivation-requests'],
            'update patient' => ['PATCH', 'api/patients/{id}'],
            'show patient' => ['GET', 'api/patients/{id}'],
            'list patient notes' => ['GET', 'api/patients/{id}/notes'],
            'create patient note' => ['POST', 'api/patients/{id}/notes'],
            'list consents' => ['GET', 'api/consents'],
            'create consent' => ['POST', 'api/consents'],
            'update consent' => ['PATCH', 'api/consents/{id}'],
            'list supervision requests' => ['GET', 'api/supervision-requests'],
            'create supervision request' => ['POST', 'api/supervision-requests'],
            'cancel supervision request' => ['PATCH', 'api/supervision-requests/{id}'],
            'respond supervision request' => ['PATCH', 'api/supervision-requests/{id}/respond'],
            'list supervised patients' => ['GET', 'api/supervisors/{id}/patients'],
            'unlink supervised patient' => [
                'POST',
                'api/supervisors/{supervisor_uid}/patients/{patient_uid}/unlink',
            ],
            'resolve supervisor code legacy' => ['GET', 'api/supervisors/resolve'],
            'resolve supervisor code without url disclosure' => ['POST', 'api/supervisors/resolve'],
            'list local resources' => ['GET', 'api/local-resources'],
            'list agenda events' => ['GET', 'api/agenda-events'],
            'create agenda event' => ['POST', 'api/agenda-events'],
            'update agenda event' => ['PATCH', 'api/agenda-events/{id}'],
            'delete agenda event' => ['DELETE', 'api/agenda-events/{id}'],
            'list support contacts' => ['GET', 'api/support-contacts'],
            'create support contact' => ['POST', 'api/support-contacts'],
            'update support contact' => ['PATCH', 'api/support-contacts/{id}'],
            'delete support contact' => ['DELETE', 'api/support-contacts/{id}'],
            'list notification settings' => ['GET', 'api/notification-settings'],
            'create notification settings' => ['POST', 'api/notification-settings'],
            'update notification settings' => ['PATCH', 'api/notification-settings/{id}'],
            'supervisor AI chat' => ['POST', 'api/ai/supervisor-chat'],
            'supervisor AI eligibility' => ['GET', 'api/ai/supervisor-chat/eligibility'],
        ];
    }

    #[DataProvider('requiredEndpoints')]
    public function test_android_required_endpoint_is_registered(string $method, string $uri): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, "{$method} /{$uri} debe permanecer registrado para Android.");
    }

    public function test_public_authentication_contract_keeps_expected_throttles(): void
    {
        $this->assertRouteUsesMiddleware('POST', 'api/auth/register', ['api', 'throttle:5,1']);
        $this->assertRouteUsesMiddleware('POST', 'api/auth/login', ['api', 'throttle:10,1']);
        $this->assertRouteUsesMiddleware('POST', 'api/auth/refresh', ['api', 'throttle:10,1']);
    }

    public function test_every_required_non_public_endpoint_requires_firebase_authentication(): void
    {
        $public = [
            'POST api/auth/register',
            'POST api/auth/login',
            'POST api/auth/refresh',
        ];

        foreach (self::requiredEndpoints() as [$method, $uri]) {
            if (in_array("{$method} {$uri}", $public, true)) {
                continue;
            }

            $route = $this->findRoute($method, $uri);

            $this->assertNotNull($route);
            $this->assertContains(
                'firebase.auth',
                $route->gatherMiddleware(),
                "{$method} /{$uri} debe exigir autenticacion Firebase."
            );
        }
    }

    public function test_ai_chat_keeps_rate_limit(): void
    {
        $this->assertRouteUsesMiddleware(
            'POST',
            'api/ai/supervisor-chat',
            ['api', 'firebase.auth', 'throttle:ai-chat']
        );

        $this->assertRouteUsesMiddleware(
            'GET',
            'api/ai/supervisor-chat/eligibility',
            ['api', 'firebase.auth', 'throttle:30,1']
        );
    }

    public function test_ai_chat_rate_limit_isolated_by_authenticated_uid(): void
    {
        $limiter = RateLimiter::limiter('ai-chat');
        $firstRequest = Request::create('/api/ai/supervisor-chat', 'POST');
        $firstRequest->attributes->set('firebase_uid', 'supervisor-one');
        $secondRequest = Request::create('/api/ai/supervisor-chat', 'POST');
        $secondRequest->attributes->set('firebase_uid', 'supervisor-two');

        $first = $limiter($firstRequest);
        $second = $limiter($secondRequest);

        $this->assertSame('firebase:supervisor-one', $first->key);
        $this->assertSame('firebase:supervisor-two', $second->key);
        $this->assertSame(10, $first->maxAttempts);
        $this->assertSame(60, $first->decaySeconds);
    }

    public function test_supervisor_resolution_post_is_rate_limited_by_uid_and_ip(): void
    {
        $this->assertRouteUsesMiddleware(
            'POST',
            'api/supervisors/resolve',
            ['api', 'firebase.auth', 'throttle:supervisor-resolve']
        );

        $limiter = RateLimiter::limiter('supervisor-resolve');
        $request = Request::create('/api/supervisors/resolve', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
        $request->attributes->set('firebase_uid', 'patient-one');
        $limits = $limiter($request);

        $this->assertCount(2, $limits);
        $this->assertSame('firebase:patient-one', $limits[0]->key);
        $this->assertSame(20, $limits[0]->maxAttempts);
        $this->assertSame('ip:192.0.2.10', $limits[1]->key);
        $this->assertSame(60, $limits[1]->maxAttempts);
    }

    public function test_authentication_and_privacy_run_before_uid_rate_limits(): void
    {
        $route = $this->findRoute('POST', 'api/ai/supervisor-chat');
        $this->assertNotNull($route);

        $middleware = app('router')->gatherRouteMiddleware($route);
        $authIndex = array_search(AuthenticateFirebase::class, $middleware, true);
        $privacyIndex = array_search(RequirePrivacyNoticeRead::class, $middleware, true);
        $throttleIndex = null;

        foreach ($middleware as $index => $item) {
            if (str_starts_with($item, ThrottleRequests::class.':')) {
                $throttleIndex = $index;
                break;
            }
        }

        $this->assertIsInt($authIndex);
        $this->assertIsInt($privacyIndex);
        $this->assertIsInt($throttleIndex);
        $this->assertLessThan($privacyIndex, $authIndex);
        $this->assertLessThan($throttleIndex, $privacyIndex);
    }

    public function test_email_sync_is_authenticated_and_rate_limited(): void
    {
        $this->assertRouteUsesMiddleware(
            'POST',
            'api/auth/email/sync',
            ['api', 'firebase.auth', 'throttle:10,1']
        );
    }

    public function test_data_control_routes_are_authenticated_and_rate_limited(): void
    {
        $this->assertRouteUsesMiddleware(
            'GET',
            'api/data-control/summary',
            ['api', 'firebase.auth', 'throttle:20,1']
        );
        $this->assertRouteUsesMiddleware(
            'GET',
            'api/data-control/export',
            ['api', 'firebase.auth', 'throttle:3,1']
        );
        $this->assertRouteUsesMiddleware(
            'POST',
            'api/data-control/account-deletion-requests',
            ['api', 'firebase.auth', 'throttle:3,1']
        );
        $this->assertRouteUsesMiddleware(
            'POST',
            'api/data-control/account-deactivation-requests',
            ['api', 'firebase.auth', 'throttle:3,1']
        );
    }

    public function test_privacy_gate_can_be_completed_without_a_428_loop(): void
    {
        $show = $this->findRoute('GET', 'api/legal/privacy-notice');
        $confirm = $this->findRoute('POST', 'api/legal/privacy-notice/read');
        $me = $this->findRoute('GET', 'api/auth/me');
        $export = $this->findRoute('GET', 'api/data-control/export');

        $this->assertNotNull($show);
        $this->assertNotNull($confirm);
        $this->assertNotNull($me);
        $this->assertNotNull($export);

        $this->assertNotContains('privacy.notice.read', $show->gatherMiddleware());
        $this->assertNotContains('privacy.notice.read', $confirm->gatherMiddleware());
        $this->assertNotContains('privacy.notice.read', $me->gatherMiddleware());
        $this->assertContains('firebase.auth', $confirm->gatherMiddleware());
        $this->assertContains('privacy.notice.read', $export->gatherMiddleware());
    }

    private function findRoute(string $method, string $uri): ?Route
    {
        foreach (RouteFacade::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $middleware
     */
    private function assertRouteUsesMiddleware(string $method, string $uri, array $middleware): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route);

        foreach ($middleware as $expected) {
            $this->assertContains(
                $expected,
                $route->gatherMiddleware(),
                "{$method} /{$uri} debe usar middleware {$expected}."
            );
        }
    }
}
