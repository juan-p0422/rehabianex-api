<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AuthController;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthEmulatorRoutingTest extends TestCase
{
    public function test_production_auth_url_remains_https_when_emulator_is_disabled(): void
    {
        config(['services.firebase.auth_emulator_host' => null]);

        $url = $this->url('identitytoolkit.googleapis.com', '/v1/accounts:signInWithPassword');

        $this->assertSame(
            'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=safe-test-key',
            $url
        );
    }

    public function test_local_auth_emulator_url_uses_loopback_host(): void
    {
        config(['services.firebase.auth_emulator_host' => '127.0.0.1:9199']);

        $url = $this->url('securetoken.googleapis.com', '/v1/token');

        $this->assertSame(
            'http://127.0.0.1:9199/securetoken.googleapis.com/v1/token?key=safe-test-key',
            $url
        );
    }

    public function test_auth_emulator_rejects_non_loopback_host(): void
    {
        config(['services.firebase.auth_emulator_host' => 'external.example:9199']);

        $this->expectException(HttpException::class);
        $this->url('identitytoolkit.googleapis.com', '/v1/accounts:signInWithPassword');
    }

    private function url(string $service, string $path): string
    {
        $reflection = new ReflectionClass(AuthController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod('firebaseAuthUrl')->invoke(
            $controller,
            $service,
            $path,
            'safe-test-key'
        );
    }
}
