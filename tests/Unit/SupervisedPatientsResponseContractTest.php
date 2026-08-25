<?php

namespace Tests\Unit;

use App\Http\Controllers\PatientController;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class SupervisedPatientsResponseContractTest extends TestCase
{
    public function test_supervised_patients_keeps_patients_and_data_aliases_identical(): void
    {
        $patient = [
            'uid' => 'patient-1',
            'supervisor_uid' => 'supervisor-1',
            'wants_supervision' => true,
        ];
        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->andReturnTrue();
        $snapshot->shouldReceive('data')->andReturn($patient);
        $snapshot->shouldReceive('id')->andReturn('patient-1');

        $query = Mockery::mock();
        $query->shouldReceive('where')->twice()->andReturnSelf();
        $query->shouldReceive('documents')->once()->andReturn([$snapshot]);

        $database = Mockery::mock();
        $database->shouldReceive('collection')->with('patients')->once()->andReturn($query);

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($database);

        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('supervisor');
        $access->shouldReceive('uid')->once()->andReturn('supervisor-1');
        $access->shouldReceive('assertAuthorizedSupervisor')->once()->with('supervisor-1');
        $access->shouldReceive('supervisorCanAccessPatient')
            ->once()
            ->with('supervisor-1', 'patient-1')
            ->andReturnTrue();
        $access->shouldReceive('sanitizeForSupervisor')
            ->once()
            ->with('patients', $patient, 'supervisor-1')
            ->andReturn([
                'uid' => 'patient-1',
                'patient_uid' => 'patient-1',
                'display_name' => 'Paciente ABC123',
                'full_name' => null,
                'permissions' => [
                    'ai_chat_summary' => true,
                ],
            ]);

        $response = (new PatientController($firebase, $access))->supervisedPatients(
            Request::create('/api/supervisors/supervisor-1/patients'),
            'supervisor-1'
        );
        $payload = $response->getData(true);

        $this->assertSame($payload['patients'], $payload['data']);
        $this->assertSame(1, $payload['count']);
        $this->assertTrue($payload['patients'][0]['permissions']['ai_chat_summary']);
    }
}
