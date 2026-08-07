<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRouteProtectionTest extends TestCase
{
    public function test_admin_route_requires_firebase_authentication_and_admin_guard(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/admin/ping');

        $this->assertNotNull($route);
        $this->assertContains('firebase.auth', $route->gatherMiddleware());
        $this->assertContains('admin', $route->gatherMiddleware());
    }

    public function test_all_admin_supervisor_routes_use_both_guards(): void
    {
        $expected = [
            'GET' => [
                'api/admin/supervisors',
                'api/admin/supervisors/{uid}',
            ],
            'PATCH' => [
                'api/admin/supervisors/{uid}/authorize',
                'api/admin/supervisors/{uid}/reject',
                'api/admin/supervisors/{uid}/suspend',
                'api/admin/supervisors/{uid}/reactivate',
            ],
        ];

        foreach ($expected as $method => $uris) {
            foreach ($uris as $uri) {
                $route = collect(Route::getRoutes()->getRoutes())->first(
                    fn ($route) => $route->uri() === $uri
                        && in_array($method, $route->methods(), true)
                );

                $this->assertNotNull($route, "{$method} {$uri} no está registrada.");
                $this->assertContains('firebase.auth', $route->gatherMiddleware());
                $this->assertContains('admin', $route->gatherMiddleware());
            }
        }
    }

    public function test_admin_route_without_token_returns_401(): void
    {
        $this->getJson('/api/admin/ping')
            ->assertStatus(401)
            ->assertExactJson([
                'ok' => false,
                'message' => 'Autenticacion requerida. Envia Authorization: Bearer {id_token}.',
                'errors' => [],
            ]);
    }

    public function test_patient_cannot_pass_admin_guard(): void
    {
        $response = $this->runGuard('patient', ['status' => 'active']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Acceso restringido a administradores.', $response->getData(true)['message']);
    }

    public function test_supervisor_cannot_pass_admin_guard(): void
    {
        $response = $this->runGuard('supervisor', ['status' => 'active']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Acceso restringido a administradores.', $response->getData(true)['message']);
    }

    public function test_inactive_admin_cannot_pass_admin_guard(): void
    {
        $response = $this->runGuard('admin', ['status' => 'suspended']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('La cuenta administradora no está activa.', $response->getData(true)['message']);
    }

    public function test_active_admin_passes_admin_guard(): void
    {
        $response = $this->runGuard('admin', ['status' => 'active']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'ok' => true,
            'message' => 'Acceso administrativo autorizado.',
        ], $response->getData(true));
    }

    private function runGuard(string $role, array $profile)
    {
        $request = Request::create('/api/admin/ping', 'GET');
        $request->attributes->set('firebase_role', $role);
        $request->attributes->set('firebase_profile', $profile);

        return (new RequireAdmin())->handle(
            $request,
            fn () => response()->json([
                'ok' => true,
                'message' => 'Acceso administrativo autorizado.',
            ])
        );
    }
}
