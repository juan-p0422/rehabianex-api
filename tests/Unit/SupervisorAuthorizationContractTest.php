<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AuthController;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SupervisorAuthorizationContractTest extends TestCase
{
    public function test_new_supervisor_profile_is_pending_review_and_not_authorized(): void
    {
        $profile = $this->invoke(
            AuthController::class,
            'stableProfile',
            [[
                'uid' => 'supervisor-pending',
                'role' => 'supervisor',
            ], 'supervisor']
        );

        $this->assertSame('pending_review', $profile['status']);
        $this->assertFalse($profile['authorized']);
        $this->assertFalse($profile['verified']);
        $this->assertNull($profile['supervisor_code']);
    }

    public function test_pending_supervisor_does_not_pass_sensitive_access_guard(): void
    {
        $authorized = $this->invoke(
            FirestoreAccessService::class,
            'isAuthorizedSupervisorProfile',
            [[
                'uid' => 'supervisor-pending',
                'status' => 'pending_review',
                'authorized' => false,
                'verified' => false,
                'supervisor_code' => null,
            ]]
        );

        $this->assertFalse($authorized);
    }

    public function test_administratively_authorized_supervisor_passes_sensitive_access_guard(): void
    {
        $authorized = $this->invoke(
            FirestoreAccessService::class,
            'isAuthorizedSupervisorProfile',
            [[
                'uid' => 'supervisor-authorized',
                'status' => 'active',
                'authorized' => true,
                'verified' => true,
                'supervisor_code' => 'RA-12345678',
            ]]
        );

        $this->assertTrue($authorized);
    }

    public function test_sensitive_guard_rejects_unauthorized_supervisor_with_403(): void
    {
        $service = $this->accessServiceWithProfile([
            'uid' => 'supervisor-pending',
            'status' => 'pending_review',
            'authorized' => false,
            'verified' => false,
            'supervisor_code' => null,
        ]);

        try {
            $service->assertAuthorizedSupervisor('supervisor-pending');
            $this->fail('El supervisor pendiente no debe superar la guarda.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(
                'Tu cuenta de supervisor aún no ha sido autorizada por administración.',
                $exception->getMessage()
            );
        }
    }

    public function test_sensitive_guard_accepts_administratively_authorized_supervisor(): void
    {
        $service = $this->accessServiceWithProfile([
            'uid' => 'supervisor-authorized',
            'status' => 'active',
            'authorized' => true,
            'verified' => true,
            'supervisor_code' => 'RA-12345678',
        ]);

        $service->assertAuthorizedSupervisor('supervisor-authorized');

        $this->addToAssertionCount(1);
    }

    public function test_active_authorized_verified_supervisor_does_not_depend_on_short_code(): void
    {
        $authorized = $this->invoke(
            FirestoreAccessService::class,
            'isAuthorizedSupervisorProfile',
            [[
                'uid' => 'supervisor-authorized',
                'status' => 'active',
                'authorized' => true,
                'verified' => true,
                'supervisor_code' => null,
            ]]
        );

        $this->assertTrue($authorized);
    }

    #[DataProvider('unauthorizedSupervisorProfiles')]
    public function test_inactive_or_unapproved_supervisor_fails_sensitive_guard(array $profile): void
    {
        $authorized = $this->invoke(
            FirestoreAccessService::class,
            'isAuthorizedSupervisorProfile',
            [$profile]
        );

        $this->assertFalse($authorized);
    }

    public static function unauthorizedSupervisorProfiles(): array
    {
        return [
            'pending review' => [[
                'status' => 'pending_review',
                'authorized' => false,
                'verified' => false,
            ]],
            'rejected' => [[
                'status' => 'rejected',
                'authorized' => false,
                'verified' => false,
            ]],
            'suspended despite previous verification' => [[
                'status' => 'suspended',
                'authorized' => false,
                'verified' => true,
            ]],
            'active but not verified' => [[
                'status' => 'active',
                'authorized' => true,
                'verified' => false,
            ]],
        ];
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invoke(string $class, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod($method)->invokeArgs($instance, $arguments);
    }

    private function accessServiceWithProfile(array $profile): FirestoreAccessService
    {
        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->andReturn(true);
        $snapshot->shouldReceive('data')->andReturn($profile);

        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->andReturn($snapshot);

        $collection = Mockery::mock();
        $collection->shouldReceive('document')->andReturn($document);

        $database = Mockery::mock();
        $database->shouldReceive('collection')
            ->with('supervisors')
            ->andReturn($collection);

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->andReturn($database);

        return new FirestoreAccessService($firebase);
    }
}
