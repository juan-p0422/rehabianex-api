<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Services\FcmNotificationDispatcher;
use App\Services\FirebaseService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AuthRefreshContractTest extends TestCase
{
    public function test_refresh_rejects_oversized_token_without_contacting_provider(): void
    {
        Http::fake();

        $response = $this->controller()->refresh(Request::create(
            '/api/auth/refresh',
            'POST',
            ['refresh_token' => str_repeat('a', 16385)],
        ));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('refresh_token', $response->getData(true)['errors']);
        Http::assertNothingSent();
    }

    public function test_invalid_or_expired_provider_token_returns_401(): void
    {
        config(['services.firebase.web_api_key' => 'safe-test-key']);
        Http::fake([
            'https://securetoken.googleapis.com/*' => Http::response([
                'error' => ['message' => 'INVALID_GRANT_TYPE'],
            ], 400),
        ]);

        $response = $this->controller()->refresh(Request::create(
            '/api/auth/refresh',
            'POST',
            ['refresh_token' => 'synthetic-expired-token'],
        ));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['ok']);
    }

    public function test_successful_refresh_keeps_android_token_contract(): void
    {
        config(['services.firebase.web_api_key' => 'safe-test-key']);
        Http::fake([
            'https://securetoken.googleapis.com/*' => Http::response([
                'id_token' => 'synthetic-id-token-2',
                'refresh_token' => 'synthetic-refresh-token-2',
                'expires_in' => '3600',
                'token_type' => 'Bearer',
                'user_id' => 'synthetic-user',
                'project_id' => 'synthetic-project',
            ]),
        ]);

        $response = $this->controller()->refresh(Request::create(
            '/api/auth/refresh',
            'POST',
            ['refresh_token' => 'synthetic-refresh-token-1'],
        ));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('synthetic-id-token-2', $payload['tokens']['id_token']);
        $this->assertSame('synthetic-refresh-token-2', $payload['tokens']['refresh_token']);
        $this->assertSame(3600, $payload['tokens']['expires_in']);
        $this->assertSame('Bearer', $payload['tokens']['token_type']);
    }

    public function test_refresh_is_blocked_when_account_deletion_is_pending(): void
    {
        config(['services.firebase.web_api_key' => 'safe-test-key']);
        Http::fake([
            'https://securetoken.googleapis.com/*' => Http::response([
                'id_token' => 'synthetic-id-token-2',
                'refresh_token' => 'synthetic-refresh-token-2',
                'expires_in' => '3600',
                'token_type' => 'Bearer',
                'user_id' => 'synthetic-user',
            ]),
        ]);

        $response = $this->controller('pending_manual_review')->refresh(Request::create(
            '/api/auth/refresh',
            'POST',
            ['refresh_token' => 'synthetic-refresh-token-1'],
        ));

        $this->assertSame(423, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['ok']);
    }

    public function test_temporary_provider_failure_returns_502(): void
    {
        config(['services.firebase.web_api_key' => 'safe-test-key']);
        Http::fake([
            'https://securetoken.googleapis.com/*' => Http::response([], 503),
        ]);

        $response = $this->controller()->refresh(Request::create(
            '/api/auth/refresh',
            'POST',
            ['refresh_token' => 'synthetic-refresh-token'],
        ));

        $this->assertSame(502, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['ok']);
    }

    public function test_provider_network_error_returns_502_without_exposing_details(): void
    {
        config(['services.firebase.web_api_key' => 'safe-test-key']);
        Http::fake(static fn () => throw new ConnectionException('synthetic network detail'));

        $response = $this->controller()->refresh(Request::create(
            '/api/auth/refresh',
            'POST',
            ['refresh_token' => 'synthetic-refresh-token'],
        ));
        $payload = $response->getData(true);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertFalse($payload['ok']);
        $this->assertStringNotContainsString('synthetic network detail', $payload['message']);
    }

    private function controller(?string $deletionStatus = null): AuthController
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn(Mockery::mock());

        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->andReturn($deletionStatus !== null);
        if ($deletionStatus !== null) {
            $snapshot->shouldReceive('data')->andReturn([
                'data_deletion_status' => $deletionStatus,
            ]);
        }

        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->andReturn($snapshot);

        $collection = Mockery::mock();
        $collection->shouldReceive('document')->with('synthetic-user')->andReturn($document);

        $database = Mockery::mock();
        $database->shouldReceive('collection')->andReturn($collection);

        $firebase->shouldReceive('db')->once()->andReturn($database);

        return new AuthController(
            $firebase,
            Mockery::mock(FcmNotificationDispatcher::class),
        );
    }
}
