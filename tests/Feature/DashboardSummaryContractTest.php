<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DashboardSummaryController;
use App\Http\Middleware\RequireAdmin;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardSummaryContractTest extends TestCase
{
    public function test_dashboard_summary_routes_have_required_guards(): void
    {
        $supervisor = $this->route('api/supervisors/{uid}/dashboard-summary');
        $admin = $this->route('api/admin/dashboard-summary');

        $this->assertContains('firebase.auth', $supervisor->gatherMiddleware());
        $this->assertContains('throttle:30,1', $supervisor->gatherMiddleware());
        $this->assertContains('firebase.auth', $admin->gatherMiddleware());
        $this->assertContains('admin', $admin->gatherMiddleware());
        $this->assertContains('throttle:30,1', $admin->gatherMiddleware());
    }

    public function test_authorized_supervisor_receives_only_profile_and_safe_counts(): void
    {
        $store = $this->store();
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('supervisor');
        $access->shouldReceive('uid')->once()->andReturn('supervisor-1');
        $access->shouldReceive('assertAuthorizedSupervisor')->once()->with('supervisor-1');

        $request = $this->request('supervisor', 'supervisor-1', [
            'full_name' => 'Dra. Laura Martínez',
            'email' => 'private@example.test',
            'phone' => '+520000000000',
            'supervisor_type' => 'clinical_psychologist',
            'supervisor_code' => 'RA-12345678',
            'authorized' => true,
            'verified' => true,
            'status' => 'active',
            'updated_at' => '2026-08-05T10:00:00-06:00',
        ]);

        $payload = $this->controller($store, $access)
            ->supervisor($request, 'supervisor-1')
            ->getData(true);

        $this->assertSame('supervisor-1', $payload['supervisor_uid']);
        $this->assertSame('Dra. Laura Martínez', $payload['profile']['display_name']);
        $this->assertSame([
            'patients_count' => 1,
            'pending_requests_count' => 1,
            'open_interventions_count' => 2,
        ], $payload['summary']);
        $this->assertSame([
            'display_name',
            'supervisor_type',
            'supervisor_code',
            'authorized',
            'verified',
            'status',
            'updated_at',
        ], array_keys($payload['profile']));

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('private@example.test', $json);
        $this->assertStringNotContainsString('+520000000000', $json);
        $this->assertStringNotContainsString('Paciente Uno', $json);
        $this->assertStringNotContainsString('nota sensible', $json);
    }

    public function test_pending_supervisor_receives_403_before_summary_queries(): void
    {
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('supervisor');
        $access->shouldReceive('uid')->once()->andReturn('supervisor-pending');
        $access->shouldReceive('assertAuthorizedSupervisor')
            ->once()
            ->andThrow(new HttpException(
                403,
                'Tu cuenta de supervisor aún no ha sido autorizada por administración.'
            ));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Tu cuenta de supervisor aún no ha sido autorizada');

        $this->controller(new DashboardSummaryStore([]), $access)->supervisor(
            $this->request('supervisor', 'supervisor-pending', [
                'authorized' => false,
                'verified' => false,
                'status' => 'pending_review',
            ]),
            'supervisor-pending'
        );
    }

    public function test_active_admin_receives_only_supervisor_status_counts(): void
    {
        $store = $this->store();
        $access = Mockery::mock(FirestoreAccessService::class);
        $controller = $this->controller($store, $access);
        $request = $this->request('admin', 'admin-1', ['status' => 'active']);

        $response = (new RequireAdmin())->handle(
            $request,
            fn () => $controller->admin($request)
        );
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('admin-1', $payload['admin_uid']);
        $this->assertSame([
            'pending_supervisors' => 2,
            'active_supervisors' => 1,
            'suspended_supervisors' => 1,
        ], $payload['summary']);
        $this->assertArrayNotHasKey('patients', $payload);
        $this->assertArrayNotHasKey('tokens', $payload);
    }

    public function test_patient_receives_403_from_both_dashboard_summaries(): void
    {
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('patient');
        $access->shouldNotReceive('uid');
        $controller = $this->controller(new DashboardSummaryStore([]), $access);
        $request = $this->request('patient', 'patient-1', ['status' => 'active']);

        try {
            $controller->supervisor($request, 'supervisor-1');
            $this->fail('El paciente no debe consultar el dashboard de supervisor.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $response = (new RequireAdmin())->handle(
            $request,
            fn () => $controller->admin($request)
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Acceso restringido a administradores.', $response->getData(true)['message']);
    }

    private function route(string $uri)
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === $uri && in_array('GET', $route->methods(), true));

        $this->assertNotNull($route, "GET {$uri} no está registrada.");

        return $route;
    }

    private function controller(
        DashboardSummaryStore $store,
        FirestoreAccessService $access
    ): DashboardSummaryController {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($store);

        return new DashboardSummaryController($firebase, $access);
    }

    private function request(string $role, string $uid, array $profile): Request
    {
        $request = Request::create('/api/dashboard-summary', 'GET');
        $request->attributes->set('firebase_role', $role);
        $request->attributes->set('firebase_uid', $uid);
        $request->attributes->set('firebase_profile', $profile);

        return $request;
    }

    private function store(): DashboardSummaryStore
    {
        return new DashboardSummaryStore([
            'supervisors' => [
                'supervisor-pending' => ['status' => 'pending_review', 'email' => 'hidden-1@example.test'],
                'supervisor-legacy' => ['status' => 'pending', 'phone' => '+521111111111'],
                'supervisor-1' => ['status' => 'active'],
                'supervisor-suspended' => ['status' => 'suspended'],
                'supervisor-rejected' => ['status' => 'rejected'],
            ],
            'patients' => [
                'patient-1' => [
                    'uid' => 'patient-1',
                    'full_name' => 'Paciente Uno',
                    'supervisor_uid' => 'supervisor-1',
                    'wants_supervision' => true,
                    'supervision_status' => 'accepted',
                ],
                'patient-2' => [
                    'uid' => 'patient-2',
                    'supervisor_uid' => 'supervisor-1',
                    'wants_supervision' => true,
                    'supervision_status' => 'accepted',
                ],
                'patient-other' => [
                    'uid' => 'patient-other',
                    'supervisor_uid' => 'supervisor-2',
                    'wants_supervision' => true,
                    'supervision_status' => 'accepted',
                ],
            ],
            'consents' => [
                'consent-1' => [
                    'patient_uid' => 'patient-1',
                    'supervisor_uid' => 'supervisor-1',
                    'explicit_consent' => true,
                    'status' => 'active',
                ],
                'consent-2' => [
                    'patient_uid' => 'patient-2',
                    'supervisor_uid' => 'supervisor-1',
                    'explicit_consent' => true,
                    'status' => 'paused',
                ],
            ],
            'supervision_requests' => [
                'request-1' => ['supervisor_uid' => 'supervisor-1', 'status' => 'pending'],
                'request-2' => ['supervisor_uid' => 'supervisor-1', 'status' => 'accepted'],
                'request-deleted' => [
                    'supervisor_uid' => 'supervisor-1',
                    'status' => 'pending',
                    'deleted_at' => '2026-08-05T09:00:00-06:00',
                ],
                'request-other' => ['supervisor_uid' => 'supervisor-2', 'status' => 'pending'],
            ],
            'interventions' => [
                'intervention-1' => [
                    'supervisor_uid' => 'supervisor-1',
                    'patient_uid' => 'patient-1',
                    'status' => 'open',
                    'notes' => 'nota sensible',
                ],
                'intervention-2' => [
                    'supervisor_uid' => 'supervisor-1',
                    'patient_uid' => 'patient-1',
                    'status' => 'in_progress',
                ],
                'intervention-paused' => [
                    'supervisor_uid' => 'supervisor-1',
                    'patient_uid' => 'patient-2',
                    'status' => 'open',
                ],
                'intervention-3' => [
                    'supervisor_uid' => 'supervisor-1',
                    'patient_uid' => 'patient-1',
                    'status' => 'completed',
                ],
                'intervention-deleted' => [
                    'supervisor_uid' => 'supervisor-1',
                    'patient_uid' => 'patient-1',
                    'status' => 'open',
                    'deleted_at' => '2026-08-05T09:00:00-06:00',
                ],
                'intervention-other' => [
                    'supervisor_uid' => 'supervisor-2',
                    'patient_uid' => 'patient-other',
                    'status' => 'open',
                ],
            ],
        ]);
    }
}

class DashboardSummaryStore
{
    public function __construct(public array $data) {}

    public function collection(string $name): DashboardSummaryCollection
    {
        return new DashboardSummaryCollection($this->data[$name] ?? []);
    }
}

class DashboardSummaryCollection
{
    private array $filters = [];

    private ?array $selectedFields = null;

    public function __construct(private array $documents) {}

    public function where(string $field, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->filters[] = [$field, $operator, $value];

        return $clone;
    }

    public function select(array $fields): self
    {
        $clone = clone $this;
        $clone->selectedFields = $fields;

        return $clone;
    }

    public function documents(): array
    {
        $snapshots = [];

        foreach ($this->documents as $id => $data) {
            $matches = true;

            foreach ($this->filters as [$field, $operator, $value]) {
                if ($operator !== '=' || ($data[$field] ?? null) !== $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $projected = $this->selectedFields === null
                    ? $data
                    : array_intersect_key($data, array_flip($this->selectedFields));
                $snapshots[] = new DashboardSummarySnapshot((string) $id, $projected);
            }
        }

        return $snapshots;
    }
}

class DashboardSummarySnapshot
{
    public function __construct(private string $id, private array $data) {}

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
        return true;
    }
}
