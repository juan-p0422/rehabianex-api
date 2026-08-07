<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AuthLogoutContractTest extends TestCase
{
    public function test_logout_requires_a_firebase_token(): void
    {
        $this->app->instance(FirebaseService::class, Mockery::mock(FirebaseService::class));

        $this->postJson('/api/auth/logout')
            ->assertUnauthorized()
            ->assertJsonStructure(['ok', 'message', 'errors'])
            ->assertJson([
                'ok' => false,
                'errors' => [],
            ]);
    }

    public function test_logout_rejects_an_invalid_or_revoked_token(): void
    {
        $auth = Mockery::mock();
        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->andThrow(new \RuntimeException('invalid test token'));

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $this->app->instance(FirebaseService::class, $firebase);

        $this->withToken('invalid.firebase.token')
            ->postJson('/api/auth/logout')
            ->assertUnauthorized()
            ->assertExactJson([
                'ok' => false,
                'message' => 'Token invalido, expirado o revocado.',
                'errors' => [],
            ]);
    }

    public function test_valid_logout_revokes_refresh_tokens_and_stores_only_the_token_hash(): void
    {
        $uid = 'patient-test-uid';
        $tokenHash = hash('sha256', 'plain-test-token');
        $expiresAt = Carbon::parse('2030-01-01T00:00:00Z');

        $auth = Mockery::mock();
        $auth->shouldReceive('revokeRefreshTokens')->once()->with($uid);

        $document = Mockery::mock();
        $document->shouldReceive('set')->once()->with(Mockery::on(
            function (array $payload) use ($uid, $tokenHash, $expiresAt): bool {
                return $payload['token_hash'] === $tokenHash
                    && $payload['uid'] === $uid
                    && $payload['expires_at'] === $expiresAt
                    && ! in_array('plain-test-token', $payload, true);
            }
        ));

        $collection = Mockery::mock();
        $collection->shouldReceive('document')->once()->with($tokenHash)->andReturn($document);

        $db = Mockery::mock();
        $db->shouldReceive('collection')->once()->with('revoked_tokens')->andReturn($collection);

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn($auth);
        $firebase->shouldReceive('db')->once()->andReturn($db);

        $request = Request::create('/api/auth/logout', 'POST');
        $request->attributes->set('firebase_uid', $uid);
        $request->attributes->set('firebase_token_hash', $tokenHash);
        $request->attributes->set('firebase_token_expires_at', $expiresAt);

        $response = (new AuthController($firebase))->logout($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'ok' => true,
            'message' => 'Sesión cerrada correctamente.',
        ], $response->getData(true));
    }
}
