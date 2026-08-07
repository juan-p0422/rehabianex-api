<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FirestoreCrudController;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PatientProfileUpdateContractTest extends TestCase
{
    public function test_patient_owner_can_update_only_profile_allowlist(): void
    {
        $request = Request::create('/api/patients/patient-1', 'PATCH');
        $request->attributes->set('firebase_uid', 'patient-1');
        $request->attributes->set('firebase_role', 'patient');

        $changes = $this->accessService()->prepareUpdate(
            'patients',
            ['uid' => 'patient-1'],
            [
                'full_name' => 'Ana Lopez',
                'nickname' => 'Ana',
                'phone' => '+524491112233',
                'age' => 29,
                'gender' => 'female',
                'is_anonymous' => false,
                'privacy_mode' => true,
                'sobriety_start_date' => '2026-06-01T08:00:00-06:00',
                'primary_risks' => ['ansiedad nocturna'],
            ],
            $request
        );

        $this->assertSame('Ana Lopez', $changes['full_name']);
        $this->assertSame('Ana', $changes['nickname']);
        $this->assertTrue($changes['privacy_mode']);
        $this->assertSame(['ansiedad nocturna'], $changes['primary_risks']);
    }

    public function test_protected_patient_fields_are_rejected_instead_of_silently_ignored(): void
    {
        $request = Request::create('/api/patients/patient-1', 'PATCH');
        $request->attributes->set('firebase_uid', 'patient-1');
        $request->attributes->set('firebase_role', 'patient');

        try {
            $this->accessService()->prepareUpdate(
                'patients',
                ['uid' => 'patient-1'],
                [
                    'full_name' => 'Ana Lopez',
                    'supervisor_uid' => 'supervisor-2',
                    'role' => 'supervisor',
                ],
                $request
            );

            $this->fail('Los campos protegidos deben producir un error 422.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('supervisor_uid', $exception->getMessage());
            $this->assertStringContainsString('role', $exception->getMessage());
        }
    }

    public function test_patient_cannot_update_another_patient(): void
    {
        $request = Request::create('/api/patients/patient-2', 'PATCH');
        $request->attributes->set('firebase_uid', 'patient-1');
        $request->attributes->set('firebase_role', 'patient');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('No tienes permiso para acceder a este documento.');

        $this->accessService()->prepareUpdate(
            'patients',
            ['uid' => 'patient-2'],
            ['nickname' => 'No permitido'],
            $request
        );
    }

    public function test_patient_update_response_contains_patient_and_legacy_data_alias(): void
    {
        $updated = [
            'uid' => 'patient-1',
            'full_name' => 'Ana Lopez',
            'nickname' => 'Ana',
            'privacy_mode' => true,
            'is_anonymous' => false,
            'sobriety_start_date' => '2026-06-01T08:00:00-06:00',
            'photo_url' => 'https://example.test/photo.jpg',
            'primary_risks' => ['ansiedad nocturna'],
            'updated_at' => '2026-08-04T10:30:00-06:00',
        ];

        $reflection = new ReflectionClass(FirestoreCrudController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $response = $reflection->getMethod('updateResponse')->invoke(
            $controller,
            'patients',
            'patients',
            'patient-1',
            $updated
        );

        $this->assertSame('Paciente actualizado correctamente.', $response['message']);
        $this->assertSame($response['data'], $response['patient']);
        $this->assertSame('patient-1', $response['patient']['uid']);
        $this->assertSame('patient-1', $response['patient']['document_id']);
        $this->assertSame('patients', $response['patient']['collection']);
        $this->assertSame('2026-08-04T10:30:00-06:00', $response['patient']['updated_at']);
        $this->assertSame('Ana', $response['patient']['nickname']);
        $this->assertTrue($response['patient']['privacy_mode']);
        $this->assertSame('Ana Lopez', $response['patient']['full_name']);
        $this->assertSame('Paciente anónimo', $response['patient']['display_name']);
        $this->assertSame('Paciente anónimo', $response['patient']['safe_display_name']);
        $this->assertSame(['ansiedad nocturna'], $response['patient']['primary_risks']);
    }

    public function test_legacy_owner_snapshot_keeps_stable_cache_fields_without_fabricating_timestamp(): void
    {
        $reflection = new ReflectionClass(FirestoreCrudController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $snapshot = $reflection->getMethod('ownerPatientSnapshot')->invoke(
            $controller,
            ['uid' => 'patient-legacy', 'full_name' => 'Paciente Legacy'],
            'patient-legacy',
            'patients'
        );

        $this->assertSame('patient-legacy', $snapshot['uid']);
        $this->assertSame('patient-legacy', $snapshot['document_id']);
        $this->assertSame('patients', $snapshot['collection']);
        $this->assertSame('Paciente Legacy', $snapshot['display_name']);
        $this->assertSame('Paciente Legacy', $snapshot['safe_display_name']);
        $this->assertNull($snapshot['nickname']);
        $this->assertFalse($snapshot['privacy_mode']);
        $this->assertFalse($snapshot['is_anonymous']);
        $this->assertNull($snapshot['sobriety_start_date']);
        $this->assertNull($snapshot['photo_url']);
        $this->assertSame([], $snapshot['primary_risks']);
        $this->assertArrayHasKey('updated_at', $snapshot);
        $this->assertNull($snapshot['updated_at']);
    }

    private function accessService(): FirestoreAccessService
    {
        $reflection = new ReflectionClass(FirestoreAccessService::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
