<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

class AdminRoleContractTest extends TestCase
{
    public function test_public_registration_does_not_accept_admin_role(): void
    {
        $roles = $this->invokeAuthController('publicRegistrationRoles');

        $this->assertSame(['patient', 'supervisor'], $roles);
        $this->assertNotContains('admin', $roles);
    }

    public function test_admin_profile_collection_is_configured(): void
    {
        $this->assertSame('admins', config('firestore.profile_collections.admin'));
    }

    public function test_admin_auth_me_profile_has_stable_safe_shape(): void
    {
        $profile = $this->invokeAuthController('stableProfile', [[
            'uid' => 'admin-uid',
            'role' => 'admin',
            'full_name' => 'Administrador',
            'email' => 'admin@example.com',
            'status' => 'active',
            'created_at' => '2026-07-24T10:00:00-06:00',
            'updated_at' => '2026-07-24T10:00:00-06:00',
            'unexpected_private_field' => 'must-not-leak',
        ], 'admin']);

        $this->assertSame([
            'uid' => 'admin-uid',
            'role' => 'admin',
            'collection' => 'admins',
            'full_name' => 'Administrador',
            'email' => 'admin@example.com',
            'status' => 'active',
            'created_at' => '2026-07-24T10:00:00-06:00',
            'updated_at' => '2026-07-24T10:00:00-06:00',
        ], $profile);
    }

    public function test_admin_auth_aliases_are_stable(): void
    {
        $auth = $this->invokeAuthController('stableAuth', [[
            'uid' => 'admin-uid',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'display_name' => 'Administrador',
        ], [
            'full_name' => 'Administrador',
        ]]);

        $this->assertSame('admin', $auth['role']);
        $this->assertSame('Administrador', $auth['display_name']);
        $this->assertSame('Administrador', $auth['full_name']);
    }

    public function test_admin_bootstrap_command_is_registered(): void
    {
        $this->assertArrayHasKey('rehabianex:create-admin', Artisan::all());
    }

    public function test_admin_bootstrap_command_has_no_visible_password_option(): void
    {
        $command = Artisan::all()['rehabianex:create-admin'];

        $this->assertFalse($command->getDefinition()->hasOption('password'));
        $this->assertTrue($command->getDefinition()->hasOption('link-existing'));
    }

    public function test_admin_password_reset_command_requires_explicit_confirmation(): void
    {
        $this->assertArrayHasKey('rehabianex:reset-admin-password', Artisan::all());
        $command = Artisan::all()['rehabianex:reset-admin-password'];

        $this->assertFalse($command->getDefinition()->hasOption('password'));
        $this->assertTrue($command->getDefinition()->hasOption('confirm'));
    }

    private function invokeAuthController(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass(AuthController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod($method)->invokeArgs($controller, $arguments);
    }
}
