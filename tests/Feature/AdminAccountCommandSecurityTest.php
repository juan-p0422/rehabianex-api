<?php

namespace Tests\Feature;

use App\Services\FirebaseService;
use Illuminate\Support\Facades\Artisan;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Mockery;
use Tests\TestCase;

class AdminAccountCommandSecurityTest extends TestCase
{
    public function test_existing_admin_without_profile_requires_explicit_link_flag(): void
    {
        $auth = Mockery::mock();
        $auth->shouldReceive('getUserByEmail')->once()->andReturn($this->adminUser());
        $auth->shouldNotReceive('setCustomUserClaims');

        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturnFalse();
        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $collection = Mockery::mock();
        $collection->shouldReceive('document')->once()->andReturn($document);
        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('admins')->andReturn($collection);

        $this->bindFirebase($auth, $db);

        $exit = Artisan::call('rehabianex:create-admin', [
            'email' => 'admin@example.test',
            'full_name' => 'Administrador QA',
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--link-existing', Artisan::output());
    }

    public function test_weak_password_is_rejected_before_creating_new_admin(): void
    {
        config(['services.admin_bootstrap.password' => 'Oswaldo222003']);

        $auth = Mockery::mock();
        $auth->shouldReceive('getUserByEmail')->once()->andThrow(new UserNotFound());
        $auth->shouldNotReceive('createUser');

        $this->bindFirebase($auth, Mockery::mock());

        $exit = Artisan::call('rehabianex:create-admin', [
            'email' => 'admin@example.test',
            'full_name' => 'Administrador QA',
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('Oswaldo222003', Artisan::output());
    }

    public function test_reset_without_confirmation_never_changes_password(): void
    {
        $auth = Mockery::mock();
        $auth->shouldReceive('getUserByEmail')->once()->andReturn($this->adminUser());
        $auth->shouldNotReceive('changeUserPassword');

        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturnTrue();
        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $collection = Mockery::mock();
        $collection->shouldReceive('document')->once()->andReturn($document);
        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('admins')->andReturn($collection);

        $this->bindFirebase($auth, $db);

        $exit = Artisan::call('rehabianex:reset-admin-password', [
            'email' => 'admin@example.test',
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--confirm', Artisan::output());
    }

    private function bindFirebase(mixed $auth, mixed $db): void
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $firebase->shouldReceive('db')->once()->andReturn($db);
        $this->app->instance(FirebaseService::class, $firebase);
    }

    private function adminUser(): object
    {
        return (object) [
            'uid' => 'admin-test-uid',
            'customClaims' => ['role' => 'admin'],
        ];
    }
}
