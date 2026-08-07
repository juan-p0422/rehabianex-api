<?php

namespace Tests\Feature;

use App\Http\Controllers\PatientController;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class PatientNoteIdempotencyTest extends TestCase
{
    public function test_first_post_creates_and_second_post_returns_the_same_note(): void
    {
        [$controller, $store] = $this->environment();
        $mutationId = '550e8400-e29b-41d4-a716-446655440000';
        $payload = $this->validPayload($mutationId);

        $first = $controller->storeNote($this->request('patient-a', $payload), 'patient-a');
        $second = $controller->storeNote($this->request('patient-a', $payload), 'patient-a');

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertFalse($first->getData(true)['idempotent_replay']);
        $this->assertTrue($second->getData(true)['idempotent_replay']);
        $this->assertSame($first->getData(true)['note'], $second->getData(true)['note']);
        $this->assertSame($second->getData(true)['note'], $second->getData(true)['data']);
        $this->assertCount(1, $store->notes);
    }

    public function test_same_mutation_id_for_another_patient_does_not_collide(): void
    {
        [$controller, $store] = $this->environment();
        $mutationId = '550e8400-e29b-41d4-a716-446655440001';

        $patientA = $controller->storeNote(
            $this->request('patient-a', $this->validPayload($mutationId)),
            'patient-a'
        );
        $patientB = $controller->storeNote(
            $this->request('patient-b', $this->validPayload($mutationId)),
            'patient-b'
        );

        $this->assertSame(201, $patientA->getStatusCode());
        $this->assertSame(201, $patientB->getStatusCode());
        $this->assertNotSame(
            $patientA->getData(true)['note']['note_id'],
            $patientB->getData(true)['note']['note_id']
        );
        $this->assertSame('patient-a', $patientA->getData(true)['note']['patient_uid']);
        $this->assertSame('patient-b', $patientB->getData(true)['note']['patient_uid']);
        $this->assertCount(2, $store->notes);
    }

    public function test_invalid_payload_does_not_reserve_idempotency_key_or_create_a_duplicate(): void
    {
        [$controller, $store] = $this->environment();
        $mutationId = '550e8400-e29b-41d4-a716-446655440002';
        $invalid = $this->validPayload($mutationId);
        unset($invalid['mood_score']);

        $failed = $controller->storeNote($this->request('patient-a', $invalid), 'patient-a');

        $this->assertSame(422, $failed->getStatusCode());
        $this->assertCount(0, $store->notes);

        $created = $controller->storeNote(
            $this->request('patient-a', $this->validPayload($mutationId)),
            'patient-a'
        );

        $this->assertSame(201, $created->getStatusCode());
        $this->assertCount(1, $store->notes);
    }

    public function test_idempotency_key_header_is_supported_and_conflict_with_body_returns_422(): void
    {
        [$controller, $store] = $this->environment();
        $headerId = '550e8400-e29b-41d4-a716-446655440003';
        $payload = $this->validPayload();

        $created = $controller->storeNote(
            $this->request('patient-a', $payload, $headerId),
            'patient-a'
        );

        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame($headerId, $created->getData(true)['note']['client_mutation_id']);

        $conflicting = $controller->storeNote(
            $this->request(
                'patient-a',
                $this->validPayload('550e8400-e29b-41d4-a716-446655440004'),
                $headerId
            ),
            'patient-a'
        );

        $this->assertSame(422, $conflicting->getStatusCode());
        $this->assertCount(1, $store->notes);
    }

    private function environment(): array
    {
        $store = new InMemoryPatientNotesStore();
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($store);
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->andReturnUsing(
            static fn (Request $request): string => (string) $request->attributes->get('firebase_role')
        );
        $access->shouldReceive('uid')->andReturnUsing(
            static fn (Request $request): string => (string) $request->attributes->get('firebase_uid')
        );

        return [new PatientController($firebase, $access), $store];
    }

    private function request(string $patientUid, array $payload, ?string $headerId = null): Request
    {
        $server = $headerId === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $headerId];
        $request = Request::create('/api/patients/'.$patientUid.'/notes', 'POST', $payload, [], [], $server);
        $request->attributes->set('firebase_uid', $patientUid);
        $request->attributes->set('firebase_role', 'patient');

        return $request;
    }

    private function validPayload(?string $mutationId = null): array
    {
        return array_filter([
            'client_mutation_id' => $mutationId,
            'mood' => 'ansioso',
            'mood_score' => 4,
            'anxiety_level' => 8,
            'craving_level' => 6,
            'energy_level' => 3,
            'sleep_quality' => 5,
            'had_relapse' => false,
            'triggers' => ['estrés'],
            'coping_actions' => ['respiración'],
            'note_text' => 'Registro offline de prueba.',
        ], static fn (mixed $value): bool => $value !== null);
    }
}

class InMemoryPatientNotesStore
{
    public array $notes = [];

    public function collection(string $name): InMemoryPatientNotesCollection
    {
        if ($name !== 'patient_notes') {
            throw new \RuntimeException('Unexpected collection in test.');
        }

        return new InMemoryPatientNotesCollection($this);
    }
}

class InMemoryPatientNotesCollection
{
    public function __construct(private InMemoryPatientNotesStore $store) {}

    public function document(string $id): InMemoryPatientNoteDocument
    {
        return new InMemoryPatientNoteDocument($this->store, $id);
    }
}

class InMemoryPatientNoteDocument
{
    public function __construct(
        private InMemoryPatientNotesStore $store,
        private string $id
    ) {}

    public function snapshot(): InMemoryPatientNoteSnapshot
    {
        return new InMemoryPatientNoteSnapshot($this->store->notes[$this->id] ?? null);
    }

    public function set(array $data): void
    {
        $this->store->notes[$this->id] = $data;
    }
}

class InMemoryPatientNoteSnapshot
{
    public function __construct(private ?array $data) {}

    public function exists(): bool
    {
        return $this->data !== null;
    }

    public function data(): array
    {
        return $this->data ?? [];
    }
}
