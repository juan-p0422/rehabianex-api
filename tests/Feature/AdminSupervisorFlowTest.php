<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AdminSupervisorController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RequireAdmin;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use Mockery;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminSupervisorFlowTest extends TestCase
{
    public function test_patient_and_supervisor_cannot_list_admin_supervisors(): void
    {
        foreach (['patient', 'supervisor'] as $role) {
            $request = $this->request($role, 'user-uid', ['status' => 'active']);
            $response = (new RequireAdmin())->handle(
                $request,
                fn () => response()->json(['ok' => true])
            );

            $this->assertSame(403, $response->getStatusCode(), "El rol {$role} no debe acceder.");
        }
    }

    public function test_complete_supervisor_admin_lifecycle(): void
    {
        $registered = $this->newSupervisorProfile();
        $this->assertFalse($registered['authorized']);
        $this->assertFalse($registered['verified']);
        $this->assertSame('pending_review', $registered['status']);

        $store = new AdminFlowFirestore([
            'supervisors' => [
                'supervisor-1' => array_replace($registered, [
                    'uid' => 'supervisor-1',
                    'full_name' => 'Supervisor de prueba',
                    'email' => 'supervisor@example.com',
                ]),
            ],
        ]);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->andReturn($store);
        $controller = new AdminSupervisorController($firebase);
        $access = new FirestoreAccessService($firebase);
        $adminRequest = $this->request('admin', 'admin-1', ['status' => 'active']);

        $listResponse = (new RequireAdmin())->handle(
            $adminRequest,
            fn () => $controller->index($adminRequest)
        );
        $this->assertSame(200, $listResponse->getStatusCode());
        $this->assertSame(1, $listResponse->getData(true)['count']);

        foreach ([
            'GET /supervisors/{uid}/patients',
            'GET /supervision-requests',
            'POST /ai/supervisor-chat',
        ] as $endpoint) {
            $this->assertSensitiveDenied($access, $endpoint);
        }

        $authorize = $this->request('admin', 'admin-1', ['status' => 'active'], [
            'notes' => 'Validado en prueba.',
        ]);
        $authorizeResponse = $controller->authorizeSupervisor($authorize, 'supervisor-1');
        $authorized = $store->data['supervisors']['supervisor-1'];

        $this->assertSame(200, $authorizeResponse->getStatusCode());
        $this->assertTrue($authorized['authorized']);
        $this->assertTrue($authorized['verified']);
        $this->assertSame('active', $authorized['status']);
        $this->assertSame('admin-1', $authorized['authorized_by']);
        $access->assertAuthorizedSupervisor('supervisor-1');

        $suspend = $this->request('admin', 'admin-1', ['status' => 'active'], [
            'reason' => 'Suspensión de prueba.',
        ]);
        $suspendResponse = $controller->suspend($suspend, 'supervisor-1');
        $suspended = $store->data['supervisors']['supervisor-1'];

        $this->assertSame(200, $suspendResponse->getStatusCode());
        $this->assertFalse($suspended['authorized']);
        $this->assertSame('suspended', $suspended['status']);
        $this->assertSensitiveDenied($access, 'endpoint sensible después de suspensión');

        $reactivate = $this->request('admin', 'admin-1', ['status' => 'active']);
        $reactivateResponse = $controller->reactivate($reactivate, 'supervisor-1');
        $reactivated = $store->data['supervisors']['supervisor-1'];

        $this->assertSame(200, $reactivateResponse->getStatusCode());
        $this->assertTrue($reactivated['authorized']);
        $this->assertTrue($reactivated['verified']);
        $this->assertSame('active', $reactivated['status']);
        $this->assertSame('admin-1', $reactivated['reactivated_by']);
        $access->assertAuthorizedSupervisor('supervisor-1');
    }

    private function newSupervisorProfile(): array
    {
        $reflection = new ReflectionClass(AuthController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod('stableProfile')->invokeArgs($controller, [[
            'uid' => 'supervisor-1',
            'role' => 'supervisor',
        ], 'supervisor']);
    }

    private function assertSensitiveDenied(FirestoreAccessService $access, string $endpoint): void
    {
        try {
            $access->assertAuthorizedSupervisor('supervisor-1');
            $this->fail("{$endpoint} debió responder 403.");
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode(), $endpoint);
            $this->assertSame(
                'Tu cuenta de supervisor aún no ha sido autorizada por administración.',
                $exception->getMessage(),
                $endpoint
            );
        }
    }

    private function request(
        string $role,
        string $uid,
        array $profile,
        array $body = []
    ): Request {
        $request = Request::create('/api/test', 'PATCH', $body);
        $request->attributes->set('firebase_role', $role);
        $request->attributes->set('firebase_uid', $uid);
        $request->attributes->set('firebase_profile', $profile);

        return $request;
    }
}

class AdminFlowFirestore
{
    public function __construct(public array $data) {}

    public function collection(string $name): AdminFlowCollection
    {
        $this->data[$name] ??= [];

        return new AdminFlowCollection($this, $name);
    }
}

class AdminFlowCollection
{
    public function __construct(
        private AdminFlowFirestore $store,
        private string $name
    ) {}

    public function document(string $id): AdminFlowDocument
    {
        return new AdminFlowDocument($this->store, $this->name, $id);
    }

    public function documents(): array
    {
        $documents = [];

        foreach ($this->store->data[$this->name] as $id => $data) {
            $documents[] = new AdminFlowSnapshot((string) $id, $data, true);
        }

        return $documents;
    }
}

class AdminFlowDocument
{
    public function __construct(
        private AdminFlowFirestore $store,
        private string $collection,
        private string $id
    ) {}

    public function snapshot(): AdminFlowSnapshot
    {
        $exists = array_key_exists($this->id, $this->store->data[$this->collection]);

        return new AdminFlowSnapshot(
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

class AdminFlowSnapshot
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
