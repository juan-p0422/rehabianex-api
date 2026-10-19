<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LegalNoticeController;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Mockery;
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

    public function test_confirmation_is_bound_to_authenticated_uid_and_current_version(): void
    {
        $document = Mockery::mock();
        $document->shouldReceive('set')->once()->with(
            Mockery::on(fn (array $data): bool => ($data['privacy_notice_read']['uid'] ?? null) === 'patient-read-test'
                && ($data['privacy_notice_read']['privacy_notice_version'] ?? null) === '2026-10-18'
                && ($data['privacy_notice_read']['status'] ?? null) === 'confirmed'
                && is_string($data['privacy_notice_read']['confirmed_at'] ?? null)
            ),
            ['merge' => true],
        );
        $collection = Mockery::mock();
        $collection->shouldReceive('document')->once()->with('patient-read-test')->andReturn($document);
        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('patients')->andReturn($collection);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($db);

        $request = Request::create('/api/legal/privacy-notice/read', 'POST', [], [], [], [], json_encode([
            'version' => '2026-10-18',
            'confirmed_read' => true,
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('firebase_uid', 'patient-read-test');
        $request->attributes->set('firebase_role', 'patient');

        $response = (new LegalNoticeController($firebase))->confirmRead($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2026-10-18', $response->getData(true)['confirmation']['privacy_notice_version']);
        $this->assertArrayNotHasKey('uid', $response->getData(true)['confirmation']);
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
}
