<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
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
            'resolve supervisor code' => ['GET', 'api/supervisors/resolve'],
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
            ['api', 'firebase.auth', 'throttle:10,1']
        );
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
     * @param array<int, string> $middleware
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
