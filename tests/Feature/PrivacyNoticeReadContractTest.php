<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LegalNoticeController;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrivacyNoticeReadContractTest extends TestCase
{
    public function test_notice_is_public_and_exposes_no_invented_legal_identity(): void
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $this->app->instance(FirebaseService::class, $firebase);

        $this->getJson('/api/legal/privacy-notice')
            ->assertOk()
            ->assertJsonPath('notice.version', '2026-10-18')
            ->assertJsonPath('notice.responsible_status', 'pending_confirmation')
            ->assertJsonPath('notice.contact_status', 'pending_confirmation')
            ->assertJsonPath('notice.sensitive_data_consent_separate', true)
            ->assertJsonMissingPath('notice.controller_name')
            ->assertJsonFragment(['title' => 'Aviso de Privacidad de RehabiAnex']);
    }

    public function test_read_confirmation_rejects_an_old_version(): void
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $controller = new LegalNoticeController($firebase);
        $request = Request::create('/api/legal/privacy-notice/read', 'POST', [], [], [], [], json_encode([
            'version' => '2026-08-01',
            'confirmed_read' => true,
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $response = $controller->confirmRead($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    #[DataProvider('legacyProfileRoles')]
    public function test_confirmation_creates_the_missing_field_for_legacy_profiles(
        string $role,
        string $collectionName,
    ): void {
        $uid = "legacy-{$role}-test";
        $document = Mockery::mock();
        $document->shouldReceive('set')->once()->with(
            Mockery::on(fn (array $data): bool => ($data['privacy_notice_read']['uid'] ?? null) === $uid
                && ($data['privacy_notice_read']['privacy_notice_version'] ?? null) === '2026-10-18'
                && ($data['privacy_notice_read']['status'] ?? null) === 'confirmed'
                && is_string($data['privacy_notice_read']['confirmed_at'] ?? null)
            ),
            ['merge' => true],
        );
        $collection = Mockery::mock();
        $collection->shouldReceive('document')->once()->with($uid)->andReturn($document);
        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with($collectionName)->andReturn($collection);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($db);

        $request = Request::create('/api/legal/privacy-notice/read', 'POST', [], [], [], [], json_encode([
            'version' => '2026-10-18',
            'confirmed_read' => true,
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('firebase_uid', $uid);
        $request->attributes->set('firebase_role', $role);

        $response = (new LegalNoticeController($firebase))->confirmRead($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2026-10-18', $response->getData(true)['confirmation']['privacy_notice_version']);
        $this->assertArrayNotHasKey('uid', $response->getData(true)['confirmation']);
    }

    public static function legacyProfileRoles(): array
    {
        return [
            'patient' => ['patient', 'patients'],
            'supervisor' => ['supervisor', 'supervisors'],
            'admin' => ['admin', 'admins'],
        ];
    }

    public function test_confirmation_failure_does_not_report_success(): void
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andThrow(new \RuntimeException('synthetic outage'));
        $request = Request::create('/api/legal/privacy-notice/read', 'POST', [], [], [], [], json_encode([
            'version' => '2026-10-18',
            'confirmed_read' => true,
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('firebase_uid', 'patient-read-test');
        $request->attributes->set('firebase_role', 'patient');

        $response = (new LegalNoticeController($firebase))->confirmRead($request);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['ok']);
    }

    #[DataProvider('legacyProfileRoles')]
    public function test_login_initializes_pending_read_without_faking_confirmation(
        string $role,
        string $collectionName,
    ): void {
        config(['services.firebase.web_api_key' => 'safe-test-key']);
        $uid = "legacy-login-{$role}";
        $user = (object) [
            'uid' => $uid,
            'email' => "{$role}@example.test",
            'emailVerified' => true,
            'displayName' => 'Cuenta sintética',
            'phoneNumber' => null,
            'photoUrl' => null,
            'disabled' => false,
            'customClaims' => ['role' => $role],
            'providerData' => [],
        ];

        $auth = Mockery::mock();
        $auth->shouldReceive('getUser')->once()->with($uid)->andReturn($user);
        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturnTrue();
        $snapshot->shouldReceive('data')->once()->andReturn([
            'uid' => $uid,
            'role' => $role,
            'status' => 'active',
        ]);
        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $document->shouldReceive('set')->once()->with(
            Mockery::on(fn (array $data): bool => ($data['privacy_notice_read']['status'] ?? null) === 'pending'
                && ($data['privacy_notice_read']['privacy_notice_version'] ?? null) === '2026-10-18'
                && is_string($data['privacy_notice_read']['required_since'] ?? null)
                && ! array_key_exists('confirmed_at', $data['privacy_notice_read'])
            ),
            ['merge' => true],
        );
        $collection = Mockery::mock();
        $collection->shouldReceive('document')->twice()->with($uid)->andReturn($document);
        $db = Mockery::mock();
        $db->shouldReceive('collection')->twice()->with($collectionName)->andReturn($collection);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $firebase->shouldReceive('db')->once()->andReturn($db);
        $this->app->instance(FirebaseService::class, $firebase);

        Http::fake([
            'https://identitytoolkit.googleapis.com/*' => Http::response([
                'idToken' => 'synthetic-id-token',
                'refreshToken' => 'synthetic-refresh-token',
                'expiresIn' => '3600',
                'localId' => $uid,
            ]),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'SyntheticPassword123!',
        ])->assertOk()
            ->assertJsonPath('profile.privacy_notice_read', null);
    }
}
