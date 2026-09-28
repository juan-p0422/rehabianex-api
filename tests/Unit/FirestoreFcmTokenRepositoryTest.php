<?php

namespace Tests\Unit;

use App\Services\FirebaseService;
use App\Services\FirestoreFcmTokenRepository;
use Mockery;
use Tests\TestCase;

class FirestoreFcmTokenRepositoryTest extends TestCase
{
    public function test_registration_uses_hashed_document_id_and_stores_only_safe_token_metadata(): void
    {
        $rawToken = str_repeat('firestore-token-', 6);
        $expectedHash = hash('sha256', $rawToken);
        $stored = null;

        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturn(false);

        $reference = Mockery::mock();
        $reference->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $reference->shouldReceive('set')
            ->once()
            ->withArgs(function (array $document) use (&$stored): bool {
                $stored = $document;

                return true;
            });

        $collection = Mockery::mock();
        $collection->shouldReceive('document')->once()->with($expectedHash)->andReturn($reference);

        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('fcm_tokens')->andReturn($collection);

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($db);

        $repository = new FirestoreFcmTokenRepository($firebase);
        $response = $repository->registerToken('firebase-user-id', $rawToken, [
            'device_id' => 'device-1',
            'locale' => 'es-MX',
            'patient_name' => 'campo prohibido',
            'clinical_note' => 'campo prohibido',
        ]);

        $this->assertSame($rawToken, $stored['token']);
        $this->assertSame($expectedHash, $stored['token_hash']);
        $this->assertSame('firebase-user-id', $stored['firebase_uid']);
        $this->assertSame('android', $stored['platform']);
        $this->assertTrue($stored['is_active']);
        $this->assertArrayNotHasKey('patient_name', $stored);
        $this->assertArrayNotHasKey('clinical_note', $stored);
        $this->assertArrayNotHasKey('token', $response);
        $this->assertArrayNotHasKey('token_hash', $response);
        $this->assertArrayNotHasKey('firebase_uid', $response);
    }
}
