<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FirestoreCrudController;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class SoftDeleteContractTest extends TestCase
{
    public function test_patient_note_delete_is_logical_and_keeps_document(): void
    {
        [$controller, $document, $access] = $this->environment([
            'note_id' => 'note-1',
            'patient_uid' => 'patient-1',
            'mood' => 'stable',
        ], 'patient_notes', 'note-1');
        $access->shouldReceive('assertCanDelete')->once();
        $access->shouldReceive('uid')->once()->andReturn('patient-1');
        $document->shouldReceive('set')->once()->with(Mockery::on(
            fn (array $data): bool => $data['note_id'] === 'note-1'
                && $data['deleted_by'] === 'patient-1'
                && ! empty($data['deleted_at'])
        ));
        $document->shouldNotReceive('delete');

        $response = $controller->destroy(
            Request::create('/api/patient-notes/note-1', 'DELETE'),
            'note-1',
            'patient-notes'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getData(true)['deleted_at']);
    }

    public function test_legacy_supervision_request_delete_becomes_deprecated_cancellation(): void
    {
        [$controller, $document, $access] = $this->environment([
            'request_id' => 'request-1',
            'patient_uid' => 'patient-1',
            'supervisor_uid' => 'supervisor-1',
            'status' => 'pending',
            'type' => 'link',
        ], 'supervision_requests', 'request-1');
        $access->shouldReceive('assertCanDelete')->once();
        $access->shouldReceive('uid')->once()->andReturn('patient-1');
        $document->shouldReceive('set')->once()->with(Mockery::on(
            fn (array $data): bool => $data['status'] === 'cancelled'
                && ! empty($data['cancelled_at'])
                && ! empty($data['deleted_at'])
        ));
        $document->shouldNotReceive('delete');

        $response = $controller->destroy(
            Request::create('/api/supervision-requests/request-1', 'DELETE'),
            'request-1',
            'supervision-requests'
        );
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['deprecated']);
        $this->assertSame('cancelled', $payload['request']['status']);
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }

    private function environment(array $current, string $collectionName, string $id): array
    {
        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->andReturnTrue();
        $snapshot->shouldReceive('data')->andReturn($current);

        $document = Mockery::mock();
        $document->shouldReceive('snapshot')->once()->andReturn($snapshot);

        $collection = Mockery::mock();
        $collection->shouldReceive('document')->with($id)->once()->andReturn($document);

        $database = Mockery::mock();
        $database->shouldReceive('collection')->with($collectionName)->once()->andReturn($collection);

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($database);
        $access = Mockery::mock(FirestoreAccessService::class);

        return [new FirestoreCrudController($firebase, $access), $document, $access];
    }
}
