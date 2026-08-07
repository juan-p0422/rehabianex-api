<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FirestoreCrudController;
use App\Models\Firestore\PatientNote;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

class OfflineFirstContractTest extends TestCase
{
    public function test_include_deleted_is_strict_and_defaults_to_false(): void
    {
        $controller = (new ReflectionClass(FirestoreCrudController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'includeDeleted');

        $this->assertFalse($method->invoke($controller, Request::create('/api/patient-notes')));
        $this->assertTrue($method->invoke(
            $controller,
            Request::create('/api/patient-notes', 'GET', ['include_deleted' => 'true'])
        ));
        $this->assertFalse($method->invoke(
            $controller,
            Request::create('/api/patient-notes', 'GET', ['include_deleted' => 'false'])
        ));
    }

    public function test_deleted_records_are_reduced_to_safe_tombstones(): void
    {
        $controller = (new ReflectionClass(FirestoreCrudController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'tombstone');
        $result = $method->invoke($controller, PatientNote::class, [
            'note_id' => 'note-1',
            'patient_uid' => 'patient-1',
            'note_text' => 'Texto sensible que no debe persistir en la respuesta.',
            'mood' => 'triste',
            'status' => 'deleted',
            'created_at' => '2026-08-01T10:00:00-06:00',
            'updated_at' => '2026-08-01T11:00:00-06:00',
            'deleted_at' => '2026-08-01T11:00:00-06:00',
            'deleted_by' => 'private-uid',
        ]);

        $this->assertSame('note-1', $result['note_id']);
        $this->assertSame('patient-1', $result['patient_uid']);
        $this->assertArrayNotHasKey('note_text', $result);
        $this->assertArrayNotHasKey('mood', $result);
        $this->assertArrayNotHasKey('deleted_by', $result);
    }
}
