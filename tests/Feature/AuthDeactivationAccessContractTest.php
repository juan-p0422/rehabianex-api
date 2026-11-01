<?php

namespace Tests\Feature;

use App\Services\FirebaseService;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthDeactivationAccessContractTest extends TestCase
{
    #[DataProvider('blockedDeletionStatuses')]
    public function test_password_login_does_not_return_tokens_after_deactivation_starts(
        string $deletionStatus,
    ): void {
        config(['services.firebase.web_api_key' => 'safe-test-key']);

        Http::fake([
            'https://identitytoolkit.googleapis.com/*' => Http::response([
                'idToken' => 'synthetic-id-token-that-must-not-be-returned',
                'refreshToken' => 'synthetic-refresh-token-that-must-not-be-returned',
                'expiresIn' => '3600',
                'localId' => 'synthetic-deactivated-user',
            ], 200),
        ]);

        $user = (object) [
            'uid' => 'synthetic-deactivated-user',
            'customClaims' => ['role' => 'patient'],
        ];
        $auth = Mockery::mock();
        $auth->shouldReceive('getUser')
            ->once()
            ->with('synthetic-deactivated-user')
            ->andReturn($user);

        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->once()->andReturnTrue();
        $snapshot->shouldReceive('data')->once()->andReturn([
            'uid' => 'synthetic-deactivated-user',
            'data_deletion_status' => $deletionStatus,
        ]);
        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $collection = Mockery::mock();
        $collection->shouldReceive('document')
            ->once()
            ->with('synthetic-deactivated-user')
            ->andReturn($document);
        $database = Mockery::mock();
        $database->shouldReceive('collection')
            ->once()
            ->with('patients')
            ->andReturn($collection);

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $firebase->shouldReceive('db')->once()->andReturn($database);
        $this->app->instance(FirebaseService::class, $firebase);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'deactivated@example.test',
            'password' => 'SyntheticPassword123!',
        ]);

        $response->assertStatus(423)
            ->assertJsonPath('ok', false)
            ->assertJsonMissingPath('tokens')
            ->assertJsonMissingPath('auth.id_token')
            ->assertJsonMissingPath('auth.refresh_token');
    }

    public static function blockedDeletionStatuses(): array
    {
        return [
            'pending manual review' => ['pending_manual_review'],
            'processing' => ['processing'],
            'completed' => ['completed'],
        ];
    }
}
