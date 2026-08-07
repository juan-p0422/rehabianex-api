<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FirestoreCrudController;
use App\Services\FirestoreAccessService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SupervisionCancellationContractTest extends TestCase
{
    public function test_cancellation_changes_are_logical_and_timestamped(): void
    {
        $changes = $this->invokeWithoutConstructor(
            FirestoreAccessService::class,
            'cancellationChanges'
        );

        $this->assertSame('cancelled', $changes['status']);
        $this->assertArrayHasKey('cancelled_at', $changes);
        $this->assertNotSame('', $changes['cancelled_at']);
    }

    public function test_cancelled_request_response_keeps_legacy_crud_fields_and_request_alias(): void
    {
        $updated = [
            'request_id' => 'request-1',
            'patient_uid' => 'patient-1',
            'status' => 'cancelled',
            'cancelled_at' => '2026-07-24T20:30:00-06:00',
            'updated_at' => '2026-07-24T20:30:00-06:00',
        ];

        $response = $this->invokeWithoutConstructor(
            FirestoreCrudController::class,
            'updateResponse',
            ['supervision-requests', 'supervision_requests', 'request-1', $updated]
        );

        $this->assertTrue($response['ok']);
        $this->assertSame('Solicitud cancelada correctamente.', $response['message']);
        $this->assertSame('supervision_requests', $response['collection']);
        $this->assertSame('request-1', $response['id']);
        $this->assertSame($response['data'], $response['request']);
        $this->assertSame('cancelled', $response['request']['status']);
        $this->assertSame('request-1', $response['request']['document_id']);
    }

    private function invokeWithoutConstructor(
        string $class,
        string $method,
        array $arguments = []
    ): mixed {
        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod($method)->invokeArgs($instance, $arguments);
    }
}
