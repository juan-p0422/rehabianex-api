<?php

namespace Tests\Feature;

use App\Services\FirebaseService;
use Kreait\Firebase\Exception\Auth\EmailExists;
use Mockery;
use Tests\TestCase;

class AuthDuplicateEmailContractTest extends TestCase
{
    public function test_register_returns_conflict_when_email_already_exists(): void
    {
        $auth = Mockery::mock();
        $auth->shouldReceive('createUser')
            ->once()
            ->andThrow(new EmailExists('Internal provider detail must not be exposed.'));

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $firebase->shouldReceive('db')->once()->andReturn(Mockery::mock());
        $this->app->instance(FirebaseService::class, $firebase);

        $this->postJson('/api/auth/register', [
            'email' => 'duplicate@example.test',
            'password' => 'Strong-Temporary-4937!',
            'full_name' => 'Cuenta duplicada',
            'role' => 'patient',
            'privacy_notice_accepted' => true,
            'privacy_notice_version' => '2026-08-01',
        ])->assertConflict()
            ->assertExactJson([
                'ok' => false,
                'message' => 'Ya existe una cuenta registrada con este correo.',
                'errors' => [
                    'email' => ['El correo ya está registrado.'],
                ],
            ])
            ->assertJsonMissing(['Internal provider detail must not be exposed.']);
    }
}
