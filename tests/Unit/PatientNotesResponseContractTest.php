<?php

namespace Tests\Unit;

use App\Http\Controllers\PatientController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PatientNotesResponseContractTest extends TestCase
{
    public function test_notes_response_keeps_notes_and_data_aliases_identical(): void
    {
        $notes = [
            [
                'note_id' => 'note-newest',
                'created_at' => '2026-07-24T20:30:00-06:00',
            ],
            [
                'note_id' => 'note-oldest',
                'created_at' => '2026-07-23T20:30:00-06:00',
            ],
        ];

        $reflection = new ReflectionClass(PatientController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('notesResponse');
        $response = $method->invoke($controller, 'patient-1', $notes);

        $this->assertTrue($response['ok']);
        $this->assertSame('patient-1', $response['patient_uid']);
        $this->assertSame(2, $response['count']);
        $this->assertSame($notes, $response['notes']);
        $this->assertSame($response['notes'], $response['data']);
    }

    public function test_empty_notes_response_uses_stable_empty_arrays(): void
    {
        $reflection = new ReflectionClass(PatientController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('notesResponse');
        $response = $method->invoke($controller, 'patient-1', []);

        $this->assertSame([
            'ok' => true,
            'patient_uid' => 'patient-1',
            'count' => 0,
            'notes' => [],
            'data' => [],
        ], $response);
    }
}
