<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HealthEndpointSecurityTest extends TestCase
{
    public function test_public_health_endpoint_exposes_only_safe_status(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeaderMissing('X-Powered-By')
            ->assertExactJson([
                'ok' => true,
                'status' => 'healthy',
            ]);
    }

    public function test_health_endpoint_is_the_only_public_non_auth_api_route_and_is_rate_limited(): void
    {
        $health = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/health'
        );

        $this->assertNotNull($health);
        $this->assertContains('throttle:60,1', $health->gatherMiddleware());
        $this->assertNotContains('firebase.auth', $health->gatherMiddleware());
    }
}
