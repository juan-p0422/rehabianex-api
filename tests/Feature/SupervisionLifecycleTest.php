<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SupervisionController;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class SupervisionLifecycleTest extends TestCase
{
    public function test_direct_unlink_route_is_registered_and_protected(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->uri()
                === 'api/supervisors/{supervisor_uid}/patients/{patient_uid}/unlink'
                && in_array('POST', $route->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertContains('firebase.auth', $route->gatherMiddleware());
    }

    public function test_link_consent_pause_reactivate_and_direct_unlink_cycle(): void
    {
        [$store, $access, $controller] = $this->environment();
        $patientRequest = $this->request('patient', 'patient-1');
        $supervisorRequest = $this->request('supervisor', 'supervisor-1');

        $linkRequest = $access->prepareCreate('supervision-requests', [
            'supervisor_uid' => 'supervisor-1',
            'message' => 'Deseo vincularme.',
        ], $patientRequest);

        $this->assertSame('link', $linkRequest['type']);
        $this->assertSame('pending', $linkRequest['status']);

        // Documento histórico: deliberadamente se omite type.
        unset($linkRequest['type']);
        $store->data['supervision_requests']['request-old'] = array_replace(
            $linkRequest,
            ['request_id' => 'request-old']
        );

        $response = $controller->respond(
            $this->request('supervisor', 'supervisor-1', ['status' => 'accepted']),
            'request-old'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('link', $store->data['supervision_requests']['request-old']['type']);
        $this->assertSame('supervisor-1', $store->data['patients']['patient-1']['supervisor_uid']);
        $this->assertSame('accepted', $store->data['patients']['patient-1']['supervision_status']);

        $consent = $access->prepareCreate('consents', [
            'supervisor_uid' => 'supervisor-1',
            'explicit_consent' => true,
            'consent_text' => 'Autorizo compartir información.',
            'scope' => ['patient_notes'],
        ], $patientRequest);
        $store->data['consents']['consent-1'] = array_replace($consent, [
            'consent_id' => 'consent-1',
        ]);

        $this->assertTrue(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );

        $pause = $access->prepareUpdate(
            'consents',
            $store->data['consents']['consent-1'],
            ['status' => 'paused'],
            $patientRequest
        );
        $store->data['consents']['consent-1'] = array_replace(
            $store->data['consents']['consent-1'],
            $pause
        );
        $this->assertSame('paused', $pause['status']);
        $this->assertFalse($pause['explicit_consent']);
        $this->assertFalse(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );

        $resume = $access->prepareUpdate(
            'consents',
            $store->data['consents']['consent-1'],
            ['status' => 'active'],
            $patientRequest
        );
        $store->data['consents']['consent-1'] = array_replace(
            $store->data['consents']['consent-1'],
            $resume
        );
        $this->assertTrue(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );

        $unlink = $controller->unlink($supervisorRequest, 'supervisor-1', 'patient-1');
        $patient = $store->data['patients']['patient-1'];
        $revoked = $store->data['consents']['consent-1'];

        $this->assertSame(200, $unlink->getStatusCode());
        $this->assertNull($patient['supervisor_uid']);
        $this->assertFalse($patient['wants_supervision']);
        $this->assertSame('not_requested', $patient['supervision_status']);
        $this->assertNotEmpty($patient['supervision_ended_at']);
        $this->assertFalse($revoked['explicit_consent']);
        $this->assertSame('revoked', $revoked['status']);
        $this->assertSame('supervision_unlinked', $revoked['revocation_reason']);
        $this->assertFalse(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );
    }

    public function test_unlink_request_acceptance_ends_relationship_and_revokes_consent(): void
    {
        [$store, $access, $controller] = $this->environment(true);
        $patientRequest = $this->request('patient', 'patient-1');
        $unlinkRequest = $access->prepareCreate('supervision-requests', [
            'type' => 'unlink',
            'message' => 'Deseo terminar la supervisión.',
        ], $patientRequest);
        $store->data['supervision_requests']['unlink-1'] = array_replace(
            $unlinkRequest,
            ['request_id' => 'unlink-1']
        );

        $response = $controller->respond(
            $this->request('supervisor', 'supervisor-1', ['status' => 'accepted']),
            'unlink-1'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('unlink', $response->getData(true)['data']['type']);
        $this->assertSame('accepted', $store->data['supervision_requests']['unlink-1']['status']);
        $this->assertNull($store->data['patients']['patient-1']['supervisor_uid']);
        $this->assertSame('revoked', $store->data['consents']['consent-1']['status']);
        $this->assertFalse(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1')
        );
    }

    public function test_rejecting_unlink_request_keeps_relationship_and_consent_active(): void
    {
        [$store, $access, $controller] = $this->environment(true);
        $unlinkRequest = $access->prepareCreate('supervision-requests', [
            'type' => 'unlink',
            'message' => 'Deseo terminar la supervision.',
        ], $this->request('patient', 'patient-1'));
        $store->data['supervision_requests']['unlink-rejected'] = array_replace(
            $unlinkRequest,
            ['request_id' => 'unlink-rejected']
        );

        $response = $controller->respond(
            $this->request('supervisor', 'supervisor-1', ['status' => 'rejected']),
            'unlink-rejected'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('rejected', $store->data['supervision_requests']['unlink-rejected']['status']);
        $this->assertSame('supervisor-1', $store->data['patients']['patient-1']['supervisor_uid']);
        $this->assertSame('accepted', $store->data['patients']['patient-1']['supervision_status']);
        $this->assertSame('active', $store->data['consents']['consent-1']['status']);
    }

    public function test_explicit_false_revokes_and_supervisor_cannot_change_consent(): void
    {
        [$store, $access] = $this->environment(true);
        $current = $store->data['consents']['consent-1'];
        $changes = $access->prepareUpdate(
            'consents',
            $current,
            ['explicit_consent' => false],
            $this->request('patient', 'patient-1')
        );

        $this->assertSame('revoked', $changes['status']);
        $this->assertFalse($changes['explicit_consent']);
        $this->assertNotEmpty($changes['revoked_at']);

        try {
            $access->prepareUpdate(
                'consents',
                $current,
                ['status' => 'active'],
                $this->request('supervisor', 'supervisor-1')
            );
            $this->fail('El supervisor no debe modificar consentimientos.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function environment(bool $linked = false): array
    {
        $patient = [
            'uid' => 'patient-1',
            'full_name' => 'Paciente',
            'email' => 'patient@example.com',
            'supervisor_uid' => $linked ? 'supervisor-1' : null,
            'wants_supervision' => $linked,
            'supervision_status' => $linked ? 'accepted' : 'not_requested',
        ];
        $consents = $linked ? [
            'consent-1' => [
                'consent_id' => 'consent-1',
                'patient_uid' => 'patient-1',
                'supervisor_uid' => 'supervisor-1',
                'explicit_consent' => true,
                'status' => 'active',
                'scope' => ['patient_notes'],
                'revoked_at' => null,
            ],
        ] : [];
        $store = new SupervisionFlowFirestore([
            'supervisors' => [
                'supervisor-1' => [
                    'uid' => 'supervisor-1',
                    'authorized' => true,
                    'verified' => true,
                    'status' => 'active',
                    'supervisor_code' => 'RA-12345678',
                ],
            ],
            'patients' => ['patient-1' => $patient],
            'consents' => $consents,
            'supervision_requests' => [],
        ]);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->andReturn($store);
        $access = new FirestoreAccessService($firebase);

        return [$store, $access, new SupervisionController($firebase, $access)];
    }

    private function request(string $role, string $uid, array $body = []): Request
    {
        $request = Request::create('/api/test', 'POST', $body);
        $request->attributes->set('firebase_role', $role);
        $request->attributes->set('firebase_uid', $uid);

        return $request;
    }
}

class SupervisionFlowFirestore
{
    public function __construct(public array $data) {}

    public function collection(string $name): SupervisionFlowCollection
    {
        $this->data[$name] ??= [];

        return new SupervisionFlowCollection($this, $name);
    }

    public function runTransaction(callable $callback): void
    {
        $callback(new SupervisionFlowTransaction());
    }
}

class SupervisionFlowCollection
{
    private array $filters = [];

    public function __construct(
        private SupervisionFlowFirestore $store,
        private string $name
    ) {}

    public function document(string $id): SupervisionFlowDocument
    {
        return new SupervisionFlowDocument($this->store, $this->name, $id);
    }

    public function where(string $field, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->filters[] = [$field, $value];

        return $clone;
    }

    public function documents(): array
    {
        $documents = [];

        foreach ($this->store->data[$this->name] as $id => $data) {
            foreach ($this->filters as [$field, $value]) {
                if (($data[$field] ?? null) !== $value) {
                    continue 2;
                }
            }

            $documents[] = new SupervisionFlowSnapshot((string) $id, $data, true);
        }

        return $documents;
    }
}

class SupervisionFlowDocument
{
    public function __construct(
        private SupervisionFlowFirestore $store,
        private string $collection,
        private string $id
    ) {}

    public function snapshot(): SupervisionFlowSnapshot
    {
        $exists = array_key_exists($this->id, $this->store->data[$this->collection]);

        return new SupervisionFlowSnapshot(
            $this->id,
            $exists ? $this->store->data[$this->collection][$this->id] : [],
            $exists
        );
    }

    public function set(array $data): void
    {
        $this->store->data[$this->collection][$this->id] = $data;
    }
}

class SupervisionFlowSnapshot
{
    public function __construct(
        private string $id,
        private array $data,
        private bool $exists
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function exists(): bool
    {
        return $this->exists;
    }
}

class SupervisionFlowTransaction
{
    public function set(SupervisionFlowDocument $document, array $data): void
    {
        $document->set($data);
    }
}
