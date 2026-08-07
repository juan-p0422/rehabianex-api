<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AnonymousProfileProjectionTest extends TestCase
{
    public function test_login_keeps_anonymous_display_across_later_sessions(): void
    {
        config(['services.firebase.web_api_key' => 'safe-test-key']);

        $user = $this->patientUser();
        $auth = Mockery::mock();
        $auth->shouldReceive('getUser')->twice()->with($user->uid)->andReturn($user);

        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->twice()->andReturnTrue();
        $snapshot->shouldReceive('data')->twice()->andReturn($this->anonymousProfile());
        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->twice()->andReturn($snapshot);
        $collection = Mockery::mock();
        $collection->shouldReceive('document')->twice()->with($user->uid)->andReturn($document);
        $db = Mockery::mock();
        $db->shouldReceive('collection')->twice()->with('patients')->andReturn($collection);

        Http::fake([
            'https://identitytoolkit.googleapis.com/*' => Http::response([
                'idToken' => 'test-id-token',
                'refreshToken' => 'test-refresh-token',
                'expiresIn' => '3600',
                'localId' => $user->uid,
            ], 200),
        ]);

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $firebase->shouldReceive('db')->once()->andReturn($db);
        $this->app->instance(FirebaseService::class, $firebase);

        $payload = [
            'email' => $user->email,
            'password' => 'SafePassword123!',
        ];

        foreach ([1, 2] as $loginNumber) {
            $this->postJson('/api/auth/login', $payload)
                ->assertOk()
                ->assertJsonPath('auth.full_name', 'Nombre Registrado')
                ->assertJsonPath('auth.display_name', 'Paciente anónimo')
                ->assertJsonPath('auth.safe_display_name', 'Paciente anónimo')
                ->assertJsonPath('profile.full_name', 'Nombre Registrado')
                ->assertJsonPath('profile.display_name', 'Paciente anónimo')
                ->assertJsonPath('profile.safe_display_name', 'Paciente anónimo')
                ->assertJsonPath('profile.is_anonymous', true)
                ->assertJsonPath('profile.privacy_mode', true);
        }
    }

    public function test_auth_me_uses_the_same_anonymous_projection(): void
    {
        $user = $this->patientUser();
        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturnTrue();
        $snapshot->shouldReceive('data')->once()->andReturn($this->anonymousProfile());
        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $patients = Mockery::mock();
        $patients->shouldReceive('document')->once()->with($user->uid)->andReturn($document);

        $consentDocuments = Mockery::mock();
        $consentDocuments->shouldReceive('getIterator')->andReturn(new \ArrayIterator([]));
        $consents = Mockery::mock();
        $consents->shouldReceive('where')->once()->with('patient_uid', '=', $user->uid)->andReturnSelf();
        $consents->shouldReceive('documents')->once()->andReturn($consentDocuments);

        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('patients')->andReturn($patients);
        $db->shouldReceive('collection')->once()->with('consents')->andReturn($consents);
        $firebase = $this->firebaseStub(Mockery::mock(), $db);

        $request = Request::create('/api/auth/me', 'GET');
        $request->attributes->set('firebase_uid', $user->uid);
        $request->attributes->set('firebase_user', $user);

        $response = (new AuthController($firebase))->me($request);
        $json = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Paciente anónimo', $json['auth']['display_name']);
        $this->assertSame('Paciente anónimo', $json['auth']['safe_display_name']);
        $this->assertSame('Nombre Registrado', $json['profile']['full_name']);
        $this->assertSame('Paciente anónimo', $json['profile']['display_name']);
        $this->assertSame('Paciente anónimo', $json['profile']['safe_display_name']);
    }

    private function firebaseStub(mixed $auth, mixed $db): FirebaseService
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $firebase->shouldReceive('db')->once()->andReturn($db);

        return $firebase;
    }

    private function anonymousProfile(): array
    {
        return [
            'uid' => 'patient-private-test',
            'role' => 'patient',
            'full_name' => 'Nombre Registrado',
            'nickname' => 'Alias privado',
            'is_anonymous' => true,
            'privacy_mode' => true,
            'status' => 'active',
            'updated_at' => '2026-08-04T12:00:00-06:00',
        ];
    }

    private function patientUser(): object
    {
        return (object) [
            'uid' => 'patient-private-test',
            'email' => 'private@example.test',
            'emailVerified' => true,
            'displayName' => 'Nombre Registrado',
            'phoneNumber' => null,
            'photoUrl' => null,
            'disabled' => false,
            'customClaims' => ['role' => 'patient'],
            'providerData' => [],
        ];
    }
}
