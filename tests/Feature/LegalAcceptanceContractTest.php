<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Services\FcmNotificationDispatcher;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class LegalAcceptanceContractTest extends TestCase
{
    public function test_admin_profile_projects_current_privacy_read_confirmation(): void
    {
        $controller = new AuthController(
            $this->firebaseStub(Mockery::mock(), Mockery::mock())
        );
        $method = new \ReflectionMethod($controller, 'stableProfile');
        $profile = $method->invoke($controller, [
            'uid' => 'admin-privacy-test',
            'role' => 'admin',
            'privacy_notice_read' => [
                'privacy_notice_version' => '2026-10-18',
                'confirmed_at' => '2026-10-19T10:00:00-06:00',
                'status' => 'confirmed',
                'uid' => 'admin-privacy-test',
            ],
        ], 'admin');

        $this->assertSame([
            'privacy_notice_version' => '2026-10-18',
            'confirmed_at' => '2026-10-19T10:00:00-06:00',
            'status' => 'confirmed',
            'is_current' => true,
        ], $profile['privacy_notice_read']);
        $this->assertArrayNotHasKey('uid', $profile['privacy_notice_read']);
    }

    public function test_registration_without_privacy_acceptance_returns_422(): void
    {
        $this->app->instance(FirebaseService::class, $this->firebaseStub(Mockery::mock(), Mockery::mock()));

        $this->postJson('/api/auth/register', [
            'email' => 'patient@example.test',
            'password' => 'SecurePassword123!',
            'full_name' => 'Paciente de prueba',
            'role' => 'patient',
        ])->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonStructure([
                'ok',
                'message',
                'errors' => ['privacy_notice_accepted', 'privacy_notice_version'],
            ]);
    }

    public function test_registration_with_current_acceptance_returns_201_and_persists_evidence(): void
    {
        config(['services.firebase.web_api_key' => 'safe-test-key']);

        $user = $this->firebaseUser();
        $auth = Mockery::mock();
        $auth->shouldReceive('createUser')->once()->andReturn($user);
        $auth->shouldReceive('setCustomUserClaims')->once()->with($user->uid, ['role' => 'patient']);
        $auth->shouldReceive('getUser')->once()->with($user->uid)->andReturn($user);

        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturnFalse();

        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $document->shouldReceive('set')->once()->with(Mockery::on(
            function (array $profile) use ($user): bool {
                $acceptance = $profile['legal_acceptance'] ?? [];

                return $profile['uid'] === $user->uid
                    && $acceptance['uid'] === $user->uid
                    && $acceptance['role'] === 'patient'
                    && $acceptance['privacy_notice_version'] === '2026-10-18'
                    && $acceptance['source'] === 'android'
                    && $acceptance['explicit_acceptance'] === true
                    && $acceptance['status'] === 'accepted'
                    && is_string($acceptance['accepted_at']);
            }
        ));

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

        $this->app->instance(FirebaseService::class, $this->firebaseStub($auth, $db));

        $this->postJson('/api/auth/register', [
            'email' => $user->email,
            'password' => 'SecurePassword123!',
            'full_name' => $user->displayName,
            'role' => 'patient',
            'privacy_notice_accepted' => true,
            'privacy_notice_version' => '2026-10-18',
        ])->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('profile.legal_acceptance.privacy_notice_version', '2026-10-18')
            ->assertJsonPath('profile.legal_acceptance.status', 'accepted')
            ->assertJsonMissingPath('profile.legal_acceptance.uid')
            ->assertJsonMissingPath('profile.legal_acceptance.source');
    }

    public function test_auth_me_returns_safe_legal_acceptance_summary(): void
    {
        $user = $this->firebaseUser();
        $profile = [
            'uid' => $user->uid,
            'role' => 'patient',
            'full_name' => $user->displayName,
            'legal_acceptance' => [
                'uid' => $user->uid,
                'role' => 'patient',
                'privacy_notice_version' => '2026-10-18',
                'accepted_at' => '2026-08-03T12:00:00-06:00',
                'source' => 'android',
                'explicit_acceptance' => true,
                'status' => 'accepted',
            ],
            'privacy_notice_read' => [
                'uid' => $user->uid,
                'privacy_notice_version' => '2026-10-18',
                'confirmed_at' => '2026-10-18T12:00:00-06:00',
                'source' => 'android',
                'status' => 'confirmed',
            ],
        ];

        $profileSnapshot = Mockery::mock();
        $profileSnapshot->shouldReceive('exists')->once()->andReturnTrue();
        $profileSnapshot->shouldReceive('data')->once()->andReturn($profile);
        $profileDocument = Mockery::mock();
        $profileDocument->shouldReceive('snapshot')->once()->andReturn($profileSnapshot);
        $patientCollection = Mockery::mock();
        $patientCollection->shouldReceive('document')->once()->with($user->uid)->andReturn($profileDocument);

        $consentDocuments = Mockery::mock();
        $consentDocuments->shouldReceive('getIterator')->andReturn(new \ArrayIterator([]));
        $consentQuery = Mockery::mock();
        $consentQuery->shouldReceive('where')->once()->with('patient_uid', '=', $user->uid)->andReturnSelf();
        $consentQuery->shouldReceive('documents')->once()->andReturn($consentDocuments);

        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('patients')->andReturn($patientCollection);
        $db->shouldReceive('collection')->once()->with('consents')->andReturn($consentQuery);

        $firebase = $this->firebaseStub(Mockery::mock(), $db);
        $request = Request::create('/api/auth/me', 'GET');
        $request->attributes->set('firebase_uid', $user->uid);
        $request->attributes->set('firebase_user', $user);

        $response = (new AuthController($firebase))->me($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'privacy_notice_version' => '2026-10-18',
            'accepted_at' => '2026-08-03T12:00:00-06:00',
            'status' => 'accepted',
        ], $response->getData(true)['profile']['legal_acceptance']);
        $this->assertSame([
            'privacy_notice_version' => '2026-10-18',
            'confirmed_at' => '2026-10-18T12:00:00-06:00',
            'status' => 'confirmed',
            'is_current' => true,
        ], $response->getData(true)['profile']['privacy_notice_read']);
        $this->assertNull($response->getData(true)['profile']['supervisor']);
    }

    public function test_auth_me_includes_linked_supervisor_name_and_type_for_patient(): void
    {
        $user = $this->firebaseUser();
        $profile = [
            'uid' => $user->uid,
            'role' => 'patient',
            'supervisor_uid' => 'supervisor-linked',
            'supervision_status' => 'accepted',
            'privacy_notice_read' => [
                'privacy_notice_version' => '2026-10-18',
                'required_since' => '2026-10-19T10:00:00-06:00',
                'status' => 'pending',
            ],
        ];

        $patientSnapshot = Mockery::mock();
        $patientSnapshot->shouldReceive('exists')->once()->andReturnTrue();
        $patientSnapshot->shouldReceive('data')->once()->andReturn($profile);
        $patientDocument = Mockery::mock();
        $patientDocument->shouldReceive('snapshot')->once()->andReturn($patientSnapshot);
        $patientCollection = Mockery::mock();
        $patientCollection->shouldReceive('document')->once()->with($user->uid)->andReturn($patientDocument);

        $supervisorSnapshot = Mockery::mock();
        $supervisorSnapshot->shouldReceive('exists')->once()->andReturnTrue();
        $supervisorSnapshot->shouldReceive('data')->once()->andReturn([
            'uid' => 'supervisor-linked',
            'full_name' => 'Supervisora Sintética',
            'supervisor_type' => 'therapist',
        ]);
        $supervisorDocument = Mockery::mock();
        $supervisorDocument->shouldReceive('snapshot')->once()->andReturn($supervisorSnapshot);
        $supervisorCollection = Mockery::mock();
        $supervisorCollection->shouldReceive('document')->once()->with('supervisor-linked')->andReturn($supervisorDocument);

        $consentDocuments = Mockery::mock();
        $consentDocuments->shouldReceive('getIterator')->andReturn(new \ArrayIterator([]));
        $consentQuery = Mockery::mock();
        $consentQuery->shouldReceive('where')->once()->with('patient_uid', '=', $user->uid)->andReturnSelf();
        $consentQuery->shouldReceive('documents')->once()->andReturn($consentDocuments);

        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('patients')->andReturn($patientCollection);
        $db->shouldReceive('collection')->once()->with('supervisors')->andReturn($supervisorCollection);
        $db->shouldReceive('collection')->once()->with('consents')->andReturn($consentQuery);
        $request = Request::create('/api/auth/me', 'GET');
        $request->attributes->set('firebase_uid', $user->uid);
        $request->attributes->set('firebase_user', $user);

        $response = (new AuthController($this->firebaseStub(Mockery::mock(), $db)))->me($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'uid' => 'supervisor-linked',
            'full_name' => 'Supervisora Sintética',
            'supervisor_type' => 'therapist',
        ], $response->getData(true)['profile']['supervisor']);
    }

    public function test_pending_supervisor_registration_dispatches_the_real_admin_validation_event(): void
    {
        config(['services.firebase.web_api_key' => 'safe-test-key']);

        $user = $this->firebaseUser('supervisor');
        $auth = Mockery::mock();
        $auth->shouldReceive('createUser')->once()->andReturn($user);
        $auth->shouldReceive('setCustomUserClaims')->once()->with($user->uid, ['role' => 'supervisor']);
        $auth->shouldReceive('getUser')->once()->with($user->uid)->andReturn($user);

        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturnFalse();

        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $document->shouldReceive('set')->once()->with(Mockery::on(
            fn (array $profile): bool => $profile['uid'] === $user->uid
                && $profile['role'] === 'supervisor'
                && $profile['status'] === 'pending_review'
                && $profile['authorized'] === false
                && $profile['verified'] === false
        ));

        $collection = Mockery::mock();
        $collection->shouldReceive('document')->twice()->with($user->uid)->andReturn($document);

        $db = Mockery::mock();
        $db->shouldReceive('collection')->twice()->with('supervisors')->andReturn($collection);

        Http::fake([
            'https://identitytoolkit.googleapis.com/*' => Http::response([
                'idToken' => 'test-id-token',
                'refreshToken' => 'test-refresh-token',
                'expiresIn' => '3600',
                'localId' => $user->uid,
            ], 200),
        ]);

        $dispatcher = Mockery::mock(FcmNotificationDispatcher::class);
        $dispatcher->shouldReceive('sendSupervisorValidationRequired')
            ->once()
            ->with($user->uid, Mockery::on(fn (mixed $createdAt): bool => is_string($createdAt)));

        $this->app->instance(FirebaseService::class, $this->firebaseStub($auth, $db));
        $this->app->instance(FcmNotificationDispatcher::class, $dispatcher);

        $this->postJson('/api/auth/register', [
            'email' => $user->email,
            'password' => 'SecurePassword123!',
            'full_name' => $user->displayName,
            'role' => 'supervisor',
            'supervisor_type' => 'support_sponsor',
            'privacy_notice_accepted' => true,
            'privacy_notice_version' => '2026-10-18',
        ])->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('profile.status', 'pending_review')
            ->assertJsonPath('profile.authorized', false)
            ->assertJsonPath('profile.verified', false);
    }

    private function firebaseStub(mixed $auth, mixed $db): FirebaseService
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $firebase->shouldReceive('db')->once()->andReturn($db);

        return $firebase;
    }

    private function firebaseUser(string $role = 'patient'): object
    {
        return (object) [
            'uid' => "{$role}-legal-test",
            'email' => "{$role}@example.test",
            'emailVerified' => false,
            'displayName' => $role === 'supervisor' ? 'Supervisor Legal' : 'Paciente Legal',
            'phoneNumber' => null,
            'photoUrl' => null,
            'disabled' => false,
            'customClaims' => ['role' => $role],
            'providerData' => [],
        ];
    }
}
