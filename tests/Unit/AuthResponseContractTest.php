<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AuthController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AuthResponseContractTest extends TestCase
{
    public function test_patient_profile_has_stable_android_fields(): void
    {
        $profile = $this->invoke('stableProfile', [[
            'uid' => 'patient-1',
            'full_name' => 'Ana Lopez',
            'role' => 'patient',
            'created_at' => '2026-08-01T10:00:00-06:00',
        ], 'patient']);

        $this->assertSame('patient-1', $profile['uid']);
        $this->assertSame('Ana Lopez', $profile['full_name']);
        $this->assertSame('Ana Lopez', $profile['display_name']);
        $this->assertSame('Ana Lopez', $profile['safe_display_name']);
        $this->assertNull($profile['nickname']);
        $this->assertNull($profile['phone']);
        $this->assertSame('patient', $profile['role']);
        $this->assertSame('active', $profile['status']);
        $this->assertTrue($profile['authorized']);
        $this->assertTrue($profile['verified']);
        $this->assertFalse($profile['is_anonymous']);
        $this->assertNull($profile['supervisor_uid']);
        $this->assertSame('not_requested', $profile['supervision_status']);
        $this->assertNull($profile['sobriety_start_date']);
        $this->assertSame([], $profile['primary_risks']);
        $this->assertFalse($profile['privacy_mode']);
        $this->assertNull($profile['photo_url']);
        $this->assertSame('patients', $profile['collection']);
        $this->assertSame('patient-1', $profile['document_id']);
        $this->assertSame('2026-08-01T10:00:00-06:00', $profile['updated_at']);
    }

    public function test_supervisor_profile_has_stable_authorization_fields(): void
    {
        $profile = $this->invoke('stableProfile', [[
            'uid' => 'supervisor-1',
            'full_name' => 'Dra. Laura Martinez',
            'role' => 'supervisor',
            'authorized' => true,
            'verified' => true,
            'status' => 'active',
            'supervisor_type' => 'clinical_psychologist',
            'supervisor_code' => 'RA-12345678',
        ], 'supervisor']);

        $this->assertSame('supervisor', $profile['role']);
        $this->assertTrue($profile['authorized']);
        $this->assertTrue($profile['verified']);
        $this->assertSame('clinical_psychologist', $profile['supervisor_type']);
        $this->assertSame('RA-12345678', $profile['supervisor_code']);
        $this->assertSame('active', $profile['status']);
    }

    public function test_login_and_register_auth_include_name_alias_and_flattened_tokens(): void
    {
        $auth = $this->invoke('stableAuth', [[
            'uid' => 'patient-1',
            'email' => 'ana@example.com',
            'role' => 'patient',
            'display_name' => 'Ana',
        ], [
            'full_name' => 'Ana Lopez',
        ], [
            'id_token' => 'id-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]]);

        $this->assertSame('Ana Lopez', $auth['display_name']);
        $this->assertSame('Ana Lopez', $auth['safe_display_name']);
        $this->assertSame('Ana Lopez', $auth['full_name']);
        $this->assertSame('id-token', $auth['id_token']);
        $this->assertSame('refresh-token', $auth['refresh_token']);
        $this->assertSame(3600, $auth['expires_in']);
        $this->assertSame('Bearer', $auth['token_type']);
    }

    public function test_anonymous_patient_keeps_real_name_but_exposes_safe_display_name(): void
    {
        $profile = $this->invoke('stableProfile', [[
            'uid' => 'patient-private',
            'full_name' => 'Nombre Registrado',
            'nickname' => 'Alias',
            'role' => 'patient',
            'is_anonymous' => true,
            'privacy_mode' => false,
        ], 'patient']);

        $auth = $this->invoke('stableAuth', [[
            'uid' => 'patient-private',
            'role' => 'patient',
            'display_name' => 'Nombre Registrado',
        ], $profile]);

        $this->assertSame('Nombre Registrado', $profile['full_name']);
        $this->assertSame('Paciente anónimo', $profile['display_name']);
        $this->assertSame('Paciente anónimo', $profile['safe_display_name']);
        $this->assertSame('Nombre Registrado', $auth['full_name']);
        $this->assertSame('Paciente anónimo', $auth['display_name']);
        $this->assertSame('Paciente anónimo', $auth['safe_display_name']);
    }

    public function test_privacy_mode_alone_activates_anonymous_projection(): void
    {
        $profile = $this->invoke('stableProfile', [[
            'uid' => 'patient-private',
            'full_name' => 'Nombre Registrado',
            'role' => 'patient',
            'is_anonymous' => false,
            'privacy_mode' => true,
        ], 'patient']);

        $this->assertSame('Paciente anónimo', $profile['display_name']);
        $this->assertSame('Paciente anónimo', $profile['safe_display_name']);
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass(AuthController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $callable = $reflection->getMethod($method);

        return $callable->invokeArgs($controller, $arguments);
    }
}
