<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\SupervisionController;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

class SupervisorCodeResolutionContractTest extends TestCase
{
    public function test_response_exposes_only_safe_supervisor_fields(): void
    {
        $reflection = new ReflectionClass(SupervisionController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $response = $reflection->getMethod('resolvedSupervisorResponse')->invoke(
            $controller,
            'supervisor-1',
            [
                'full_name' => 'Dra. Laura Martinez',
                'supervisor_type' => 'clinical_psychologist',
                'email' => 'private@example.com',
                'phone' => '+524491234567',
                'patients' => ['patient-1'],
                'authorized' => true,
                'verified' => true,
            ]
        );

        $this->assertTrue($response['ok']);
        $this->assertSame([
            'ok' => true,
            'uid' => 'supervisor-1',
            'full_name' => 'Dra. Laura Martinez',
            'supervisor_type' => 'clinical_psychologist',
            'available' => true,
        ], $response);
        $this->assertArrayNotHasKey('email', $response);
        $this->assertArrayNotHasKey('phone', $response);
        $this->assertArrayNotHasKey('patients', $response);
    }

    public function test_explicitly_unavailable_supervisor_is_not_available(): void
    {
        $reflection = new ReflectionClass(SupervisionController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isSupervisorAvailable');

        $this->assertFalse($method->invoke($controller, ['available' => false]));
        $this->assertFalse($method->invoke($controller, ['accepting_patients' => false]));
        $this->assertTrue($method->invoke($controller, []));
    }

    public function test_invalid_code_format_returns_422_with_code_error(): void
    {
        $reflection = new ReflectionClass(SupervisionController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $request = Request::create(
            '/api/supervisors/resolve?code=INVALID',
            'GET',
            ['code' => 'INVALID']
        );

        $response = $controller->resolveSupervisor($request);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($payload['ok']);
        $this->assertArrayHasKey('code', $payload['errors']);
        $this->assertSame(
            'El codigo debe usar el formato RA-XXXXXXXX.',
            $payload['errors']['code'][0]
        );
    }
}
