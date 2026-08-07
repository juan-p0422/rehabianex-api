<?php

namespace Tests\Feature;

use App\Services\FirebaseService;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    public function test_protected_endpoints_reject_requests_without_a_firebase_token(): void
    {
        $this->app->instance(FirebaseService::class, Mockery::mock(FirebaseService::class));

        $requests = [
            ['GET', '/api/patients'],
            ['GET', '/api/patients/another-user'],
            ['POST', '/api/patients/another-user/notes'],
            ['POST', '/api/ai/supervisor-chat'],
            ['GET', '/api/achievements'],
            ['POST', '/api/support-contacts'],
            ['PATCH', '/api/supervision-requests/request-1/respond'],
            ['GET', '/api/ping'],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertUnauthorized()
                ->assertJson([
                    'ok' => false,
                    'errors' => [],
                ])
                ->assertJsonStructure([
                    'ok',
                    'message',
                    'errors',
                ]);
        }
    }

    public function test_every_non_auth_api_route_uses_firebase_authentication(): void
    {
        $publicApiRoutes = [
            'api/health',
            'api/auth/register',
            'api/auth/login',
            'api/auth/refresh',
            'api/auth/google',
            'api/auth/firebase',
        ];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            if (in_array($route->uri(), $publicApiRoutes, true)) {
                continue;
            }

            $this->assertContains(
                'firebase.auth',
                $route->gatherMiddleware(),
                "La ruta {$route->uri()} debe exigir autenticacion Firebase."
            );
        }
    }

    public function test_protected_route_rejects_invalid_firebase_token(): void
    {
        $auth = Mockery::mock();
        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->andThrow(new \RuntimeException('invalid test token'));
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $this->app->instance(FirebaseService::class, $firebase);

        $this->withToken('invalid.firebase.token')
            ->getJson('/api/ping')
            ->assertUnauthorized()
            ->assertExactJson([
                'ok' => false,
                'message' => 'Token invalido, expirado o revocado.',
                'errors' => [],
            ]);
    }
}
