<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Services\FcmNotificationDispatcher;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AuthEmailSyncContractTest extends TestCase
{
    public function test_verified_firebase_email_is_the_only_source_written_to_profile(): void
    {
        $uid = 'patient-email-sync';
        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturnTrue();
        $snapshot->shouldReceive('data')->once()->andReturn([
            'uid' => $uid,
            'role' => 'patient',
            'email' => 'old@example.test',
            'full_name' => 'María López',
            'created_at' => '2026-10-01T10:00:00-06:00',
        ]);

        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $document->shouldReceive('set')->once()->with(Mockery::on(
            fn (array $profile): bool => $profile['email'] === 'new@example.test'
                && $profile['full_name'] === 'María López'
                && $profile['uid'] === $uid
        ));

        $collection = Mockery::mock();
        $collection->shouldReceive('document')->twice()->with($uid)->andReturn($document);

        $db = Mockery::mock();
        $db->shouldReceive('collection')->twice()->with('patients')->andReturn($collection);

        $controller = $this->controller($db);
        $request = $this->request($uid, 'new@example.test', true);
        $response = $controller->syncEmail($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('new@example.test', $response->getData(true)['profile']['email']);
    }

    public function test_unverified_email_is_rejected_without_profile_write(): void
    {
        $db = Mockery::mock();
        $db->shouldNotReceive('collection');

        $controller = $this->controller($db);
        $response = $controller->syncEmail(
            $this->request('patient-email-sync', 'pending@example.test', false)
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertArrayHasKey('email', $response->getData(true)['errors']);
    }

    public function test_profile_write_failure_after_firebase_change_is_retryable_and_does_not_leak_details(): void
    {
        $uid = 'patient-email-sync';
        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andThrow(
            new \RuntimeException('synthetic firestore detail')
        );

        $collection = Mockery::mock();
        $collection->shouldReceive('document')->once()->with($uid)->andReturn($document);

        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('patients')->andReturn($collection);

        $response = $this->controller($db)->syncEmail(
            $this->request($uid, 'new@example.test', true)
        );
        $payload = $response->getData(true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($payload['ok']);
        $this->assertStringNotContainsString('synthetic firestore detail', $payload['message']);
    }

    private function controller(mixed $db): AuthController
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn(Mockery::mock());
        $firebase->shouldReceive('db')->once()->andReturn($db);

        return new AuthController(
            $firebase,
            Mockery::mock(FcmNotificationDispatcher::class),
        );
    }

    private function request(string $uid, string $email, bool $verified): Request
    {
        $request = Request::create('/api/auth/email/sync', 'POST');
        $request->attributes->set('firebase_uid', $uid);
        $request->attributes->set('firebase_user', (object) [
            'uid' => $uid,
            'email' => $email,
            'emailVerified' => $verified,
            'customClaims' => ['role' => 'patient'],
        ]);

        return $request;
    }
}
