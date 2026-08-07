<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AdminSupervisorController;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminSupervisorContractTest extends TestCase
{
    public function test_legacy_pending_status_is_exposed_as_pending_review(): void
    {
        $supervisor = $this->invoke('normalizeSupervisor', [[
            'uid' => 'supervisor-1',
            'status' => 'pending',
        ]]);

        $this->assertSame('pending_review', $supervisor['status']);
        $this->assertFalse($supervisor['authorized']);
        $this->assertFalse($supervisor['verified']);
    }

    public function test_list_projection_does_not_expose_patient_or_emotional_data(): void
    {
        $fields = $this->constant('LIST_FIELDS');
        $result = $this->invoke('only', [[
            'uid' => 'supervisor-1',
            'full_name' => 'Supervisor',
            'patients' => ['patient-1'],
            'patient_notes' => ['private'],
            'consents' => ['private'],
        ], $fields]);

        $this->assertSame('supervisor-1', $result['uid']);
        $this->assertSame('Supervisor', $result['full_name']);
        $this->assertArrayNotHasKey('patients', $result);
        $this->assertArrayNotHasKey('patient_notes', $result);
        $this->assertArrayNotHasKey('consents', $result);
    }

    public function test_detail_projection_uses_explicit_safe_allowlist(): void
    {
        $fields = $this->constant('DETAIL_FIELDS');

        $this->assertNotContains('patients', $fields);
        $this->assertNotContains('patient_notes', $fields);
        $this->assertNotContains('consents', $fields);
        $this->assertContains('authorized_by', $fields);
        $this->assertContains('rejection_reason', $fields);
    }

    public function test_incompatible_transition_returns_409(): void
    {
        try {
            $this->invoke('assertStatus', [['status' => 'suspended'], ['active']]);
            $this->fail('Una transición incompatible debe rechazarse.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass(AdminSupervisorController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod($method)->invokeArgs($controller, $arguments);
    }

    private function constant(string $name): array
    {
        return (new ReflectionClass(AdminSupervisorController::class))->getConstant($name);
    }
}
