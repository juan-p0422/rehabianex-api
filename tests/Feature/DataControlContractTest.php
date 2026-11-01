<?php

namespace Tests\Feature;

use App\Contracts\FcmTokenRepository;
use App\Http\Controllers\Api\DataControlController;
use App\Http\Middleware\AuthenticateFirebase;
use App\Services\DataControlService;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DataControlContractTest extends TestCase
{
    public function test_summary_describes_inventory_rectification_and_non_automatic_purge(): void
    {
        $service = $this->service(new DataControlFakeDatabase);
        $request = $this->authenticatedRequest('patient-1', 'patient', [
            'data_deletion_status' => 'none',
        ]);

        $summary = $service->summary($request);

        $this->assertSame('patient', $summary['account_role']);
        $this->assertFalse($summary['rectification']['profile']['offline_queue_allowed']);
        $this->assertFalse($summary['rectification']['email']['offline_queue_allowed']);
        $this->assertFalse($summary['self_service_export_available']);
        $this->assertArrayNotHasKey('export', $summary['actions']);
        $this->assertSame(410, $summary['compatibility']['legacy_export_route']['status']);
        $this->assertSame(
            '/api/data-control/account-deactivation-requests',
            $summary['actions']['deactivate_and_request_deletion']['route']
        );
        $this->assertSame(
            'DESACTIVAR MI CUENTA',
            $summary['actions']['deactivate_and_request_deletion']['confirmation_phrase']
        );
        $this->assertSame(
            'rehabianex@gmail.com',
            $summary['actions']['request_permanent_deletion']['address']
        );
        $this->assertFalse(
            $summary['actions']['request_permanent_deletion']['automatic_submission']
        );
        $this->assertSame('phone', $summary['actions']['manual_attention']['contact_channel']);
        $this->assertSame('3325356657', $summary['actions']['manual_attention']['contact_value']);
        $this->assertTrue($summary['account_deletion']['requires_recent_auth']);
        $this->assertFalse($summary['account_deletion']['physical_purge_automatic']);
        $this->assertContains('workmanager_outbox', $summary['inventory'][0]['data']);
    }

    public function test_export_contains_only_allowlisted_records_owned_by_holder(): void
    {
        $database = new DataControlFakeDatabase([
            'patient_notes' => [
                'owned-note' => [
                    'patient_uid' => 'patient-1',
                    'note_id' => 'note-1',
                    'note_text' => 'Texto sintético con acentos',
                    'server_secret' => 'must-not-leak',
                    'created_at' => '2026-10-12T08:00:00-06:00',
                ],
                'other-note' => [
                    'patient_uid' => 'patient-2',
                    'note_id' => 'note-2',
                    'note_text' => 'foreign',
                ],
            ],
        ]);
        $service = $this->service($database);
        $request = $this->authenticatedRequest('patient-1', 'patient', [
            'uid' => 'patient-1',
            'full_name' => 'Paciente Sintético',
            'password_hash' => 'must-not-leak',
        ]);
        $request->attributes->set('firebase_user', (object) [
            'email' => 'synthetic@example.test',
            'emailVerified' => true,
            'phoneNumber' => '+525500000000',
            'displayName' => 'Paciente Sintético',
            'disabled' => false,
        ]);

        $export = $service->export($request);
        $encoded = json_encode($export, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertCount(1, $export['records']['patient_notes']);
        $this->assertSame('note-1', $export['records']['patient_notes'][0]['note_id']);
        $this->assertStringNotContainsString('patient-2', $encoded);
        $this->assertStringNotContainsString('must-not-leak', $encoded);
        $this->assertStringNotContainsString('patient_uid', $encoded);
    }

    public function test_export_controller_marks_attachment_private_and_non_cacheable(): void
    {
        config(['data_control.self_service_export_enabled' => true]);
        $service = Mockery::mock(DataControlService::class);
        $service->shouldReceive('export')->once()->andReturn([
            'schema_version' => '1.0',
            'sensitivity_notice' => 'Archivo sensible sintético.',
        ]);

        $response = (new DataControlController($service))->export(Request::create(
            '/api/data-control/export',
            'GET'
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('attachment; filename="rehabianex-data-export-', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_closed_beta_export_route_returns_authenticated_compatibility_gone_without_calling_service(): void
    {
        config(['data_control.self_service_export_enabled' => false]);
        $service = Mockery::mock(DataControlService::class);
        $service->shouldNotReceive('export');

        $response = (new DataControlController($service))->export(Request::create(
            '/api/data-control/export',
            'GET'
        ));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame('self_service_export_retired', $response->getData(true)['code']);
        $this->assertFalse($response->getData(true)['ok']);
    }

    public function test_deletion_requires_exact_confirmation_and_recent_authentication(): void
    {
        $service = Mockery::mock(DataControlService::class);
        $service->shouldNotReceive('requestAccountDeletion');
        $controller = new DataControlController($service);

        $invalid = Request::create('/api/data-control/account-deletion-requests', 'POST', [
            'scope' => 'account',
            'confirmation' => 'eliminar',
            'acknowledge_retention_limits' => true,
        ]);
        $invalidResponse = $controller->requestAccountDeletion($invalid);
        $this->assertSame(422, $invalidResponse->getStatusCode());

        $stale = Request::create('/api/data-control/account-deletion-requests', 'POST', [
            'scope' => 'account',
            'confirmation' => config('data_control.deactivation_confirmation'),
            'acknowledge_retention_limits' => true,
        ]);
        $stale->attributes->set('firebase_authenticated_at', now()->subMinutes(6)->timestamp);
        $staleResponse = $controller->requestAccountDeletion($stale);
        $this->assertSame(401, $staleResponse->getStatusCode());
    }

    public function test_new_deactivation_route_rejects_legacy_phrase_and_returns_202_only_after_recent_auth(): void
    {
        $service = Mockery::mock(DataControlService::class);
        $service->shouldReceive('requestAccountDeactivation')->once()->andReturn([
            'request' => [
                'request_id' => 'deactivation_request_synthetic',
                'status' => 'pending_manual_review',
                'requested_at' => '2026-10-28T12:00:00-06:00',
                'deactivated_at' => '2026-10-28T12:00:00-06:00',
            ],
            'sync_blocked' => true,
            'local_cleanup_required' => true,
            'cleanup_status' => [
                'remote_access' => 'blocked',
                'identity' => 'completed',
                'fcm' => 'completed',
                'relationships' => 'completed',
            ],
            'manual_attention_required' => false,
            'retention_notice' => 'Aviso sintético.',
        ]);
        $controller = new DataControlController($service);

        $legacy = Request::create('/api/data-control/account-deactivation-requests', 'POST', [
            'scope' => 'account',
            'confirmation' => config('data_control.legacy_deletion_confirmation'),
            'acknowledge_retention_limits' => true,
        ]);
        $legacy->attributes->set('firebase_authenticated_at', now()->timestamp);
        $this->assertSame(422, $controller->requestAccountDeactivation($legacy)->getStatusCode());

        $valid = Request::create('/api/data-control/account-deactivation-requests', 'POST', [
            'scope' => 'account',
            'confirmation' => config('data_control.deactivation_confirmation'),
            'acknowledge_retention_limits' => true,
        ]);
        $valid->attributes->set('firebase_authenticated_at', now()->timestamp);
        $response = $controller->requestAccountDeactivation($valid);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['sync_blocked']);
        $this->assertSame('completed', $response->getData(true)['cleanup_status']['identity']);
    }

    public function test_deletion_request_atomically_blocks_sync_before_revoking_sessions(): void
    {
        $database = new DataControlFakeDatabase([
            'patients' => [
                'patient-1' => ['uid' => 'patient-1', 'data_deletion_status' => 'none'],
            ],
        ]);
        $fcm = Mockery::mock(FcmTokenRepository::class);
        $fcm->shouldReceive('revokeToken')->once()->with('patient-1')->andReturn(1);
        $auth = Mockery::mock();
        $auth->shouldReceive('revokeRefreshTokens')->once()->with('patient-1');
        $auth->shouldReceive('updateUser')->once()->with('patient-1', ['disabled' => true]);
        $service = $this->service($database, $fcm, $auth);
        $request = $this->authenticatedRequest('patient-1', 'patient', [
            'uid' => 'patient-1',
        ]);

        $result = $service->requestAccountDeletion($request);

        $this->assertTrue($result['sync_blocked']);
        $this->assertTrue($result['local_cleanup_required']);
        $this->assertSame('pending_manual_review', $result['request']['status']);
        $this->assertSame('pending_manual_review', $database->documents['patients']['patient-1']['data_deletion_status']);
        $this->assertSame('disabled', $database->documents['patients']['patient-1']['status']);
        $this->assertSame('inactive', $database->documents['patients']['patient-1']['supervision_status']);
        $this->assertCount(1, $database->documents['account_deletion_requests']);
        $this->assertSame('completed', $result['cleanup_status']['identity']);
        $this->assertSame('completed', $result['cleanup_status']['fcm']);
        $this->assertSame('completed', $result['cleanup_status']['relationships']);
    }

    public function test_provider_lock_failure_keeps_sync_blocked_and_requests_manual_attention(): void
    {
        $database = new DataControlFakeDatabase([
            'patients' => ['patient-1' => ['uid' => 'patient-1']],
        ]);
        $fcm = Mockery::mock(FcmTokenRepository::class);
        $fcm->shouldReceive('revokeToken')->once()->andReturn(1);
        $auth = Mockery::mock();
        $auth->shouldReceive('revokeRefreshTokens')->once()->andThrow(new \RuntimeException('synthetic failure'));
        $service = $this->service($database, $fcm, $auth);

        $result = $service->requestAccountDeletion(
            $this->authenticatedRequest('patient-1', 'patient', ['uid' => 'patient-1'])
        );

        $this->assertTrue($result['sync_blocked']);
        $this->assertTrue($result['manual_attention_required']);
        $requestDocument = array_values($database->documents['account_deletion_requests'])[0];
        $this->assertSame('manual_required', $requestDocument['identity_lock_status']);
        $this->assertSame('manual_required', $result['cleanup_status']['identity']);
    }

    public function test_deactivation_revokes_relationships_and_cancels_pending_requests(): void
    {
        $database = new DataControlFakeDatabase([
            'patients' => [
                'patient-1' => [
                    'uid' => 'patient-1',
                    'status' => 'active',
                    'supervisor_uid' => 'supervisor-1',
                    'wants_supervision' => true,
                    'supervision_status' => 'accepted',
                ],
            ],
            'consents' => [
                'consent-1' => [
                    'patient_uid' => 'patient-1',
                    'supervisor_uid' => 'supervisor-1',
                    'status' => 'active',
                    'explicit_consent' => true,
                ],
            ],
            'supervision_requests' => [
                'request-1' => [
                    'patient_uid' => 'patient-1',
                    'supervisor_uid' => 'supervisor-1',
                    'status' => 'pending',
                ],
            ],
        ]);
        $fcm = Mockery::mock(FcmTokenRepository::class);
        $fcm->shouldReceive('revokeToken')->once()->with('patient-1')->andReturn(1);
        $auth = Mockery::mock();
        $auth->shouldReceive('revokeRefreshTokens')->once()->with('patient-1');
        $auth->shouldReceive('updateUser')->once()->with('patient-1', ['disabled' => true]);

        $result = $this->service($database, $fcm, $auth)->requestAccountDeactivation(
            $this->authenticatedRequest('patient-1', 'patient', ['uid' => 'patient-1'])
        );

        $this->assertSame('revoked', $database->documents['consents']['consent-1']['status']);
        $this->assertFalse($database->documents['consents']['consent-1']['explicit_consent']);
        $this->assertSame('cancelled', $database->documents['supervision_requests']['request-1']['status']);
        $this->assertNull($database->documents['patients']['patient-1']['supervisor_uid']);
        $this->assertSame('completed', $result['cleanup_status']['relationships']);
    }

    public function test_supervisor_deactivation_ends_linked_patient_access(): void
    {
        $database = new DataControlFakeDatabase([
            'supervisors' => [
                'supervisor-1' => ['uid' => 'supervisor-1', 'status' => 'active'],
            ],
            'patients' => [
                'patient-1' => [
                    'uid' => 'patient-1',
                    'supervisor_uid' => 'supervisor-1',
                    'wants_supervision' => true,
                    'supervision_status' => 'accepted',
                ],
            ],
        ]);
        $fcm = Mockery::mock(FcmTokenRepository::class);
        $fcm->shouldReceive('revokeToken')->once()->with('supervisor-1')->andReturn(1);
        $auth = Mockery::mock();
        $auth->shouldReceive('revokeRefreshTokens')->once()->with('supervisor-1');
        $auth->shouldReceive('updateUser')->once()->with('supervisor-1', ['disabled' => true]);

        $this->service($database, $fcm, $auth)->requestAccountDeactivation(
            $this->authenticatedRequest('supervisor-1', 'supervisor', ['uid' => 'supervisor-1'])
        );

        $this->assertSame('disabled', $database->documents['supervisors']['supervisor-1']['status']);
        $this->assertNull($database->documents['patients']['patient-1']['supervisor_uid']);
        $this->assertFalse($database->documents['patients']['patient-1']['wants_supervision']);
        $this->assertSame('inactive', $database->documents['patients']['patient-1']['supervision_status']);
    }

    public function test_admin_account_deletion_is_explicitly_manual(): void
    {
        $service = $this->service(new DataControlFakeDatabase);

        try {
            $service->requestAccountDeletion(
                $this->authenticatedRequest('admin-1', 'admin', ['uid' => 'admin-1'])
            );
            $this->fail('Se esperaba rechazo para eliminación automatizada de administrador.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_authentication_middleware_blocks_all_requests_after_deletion_starts(): void
    {
        $claims = Mockery::mock();
        $claims->shouldReceive('get')->with('sub')->andReturn('patient-1');
        $claims->shouldReceive('get')->with('auth_time')->andReturn(now()->timestamp);

        $verifiedToken = Mockery::mock();
        $verifiedToken->shouldReceive('claims')->twice()->andReturn($claims);

        $user = (object) [
            'customClaims' => ['role' => 'patient'],
            'tokensValidAfterTime' => null,
            'disabled' => false,
        ];
        $auth = Mockery::mock();
        $auth->shouldReceive('verifyIdToken')
            ->once()
            ->with('synthetic-id-token', false, 60)
            ->andReturn($verifiedToken);
        $auth->shouldReceive('getUser')->once()->with('patient-1')->andReturn($user);

        $database = new DataControlFakeDatabase([
            'patients' => [
                'patient-1' => ['data_deletion_status' => 'pending_manual_review'],
            ],
        ]);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->twice()->andReturn($auth);
        $firebase->shouldReceive('db')->twice()->andReturn($database);

        $request = Request::create('/api/ping', 'GET');
        $request->headers->set('Authorization', 'Bearer synthetic-id-token');
        $response = (new AuthenticateFirebase($firebase))->handle(
            $request,
            static fn () => response()->json(['ok' => true])
        );

        $this->assertSame(423, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['ok']);
    }

    private function authenticatedRequest(string $uid, string $role, array $profile): Request
    {
        $request = Request::create('/api/data-control', 'GET');
        $request->attributes->set('firebase_uid', $uid);
        $request->attributes->set('firebase_role', $role);
        $request->attributes->set('firebase_profile', $profile);

        return $request;
    }

    private function service(
        DataControlFakeDatabase $database,
        ?FcmTokenRepository $fcm = null,
        mixed $auth = null,
    ): DataControlService {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($database);
        $firebase->shouldReceive('auth')->once()->andReturn($auth ?? Mockery::mock());

        return new DataControlService(
            $firebase,
            $fcm ?? Mockery::mock(FcmTokenRepository::class),
        );
    }
}

class DataControlFakeDatabase
{
    public function __construct(public array $documents = []) {}

    public function collection(string $name): DataControlFakeCollection
    {
        return new DataControlFakeCollection($this, $name);
    }

    public function runTransaction(callable $callback): mixed
    {
        return $callback(new DataControlFakeTransaction);
    }
}

class DataControlFakeCollection
{
    private ?array $filter = null;

    public function __construct(
        private DataControlFakeDatabase $database,
        private string $name,
    ) {}

    public function document(string $id): DataControlFakeDocumentReference
    {
        return new DataControlFakeDocumentReference($this->database, $this->name, $id);
    }

    public function where(string $field, string $operator, mixed $value): self
    {
        $this->filter = [$field, $operator, $value];

        return $this;
    }

    /** @return list<DataControlFakeSnapshot> */
    public function documents(): array
    {
        $documents = [];
        foreach ($this->database->documents[$this->name] ?? [] as $id => $data) {
            if ($this->filter !== null) {
                [$field, $operator, $value] = $this->filter;
                if ($operator !== '=' || ($data[$field] ?? null) !== $value) {
                    continue;
                }
            }
            $documents[] = new DataControlFakeSnapshot($data, (string) $id);
        }

        return $documents;
    }
}

class DataControlFakeDocumentReference
{
    public function __construct(
        private DataControlFakeDatabase $database,
        private string $collection,
        private string $id,
    ) {}

    public function snapshot(): DataControlFakeSnapshot
    {
        return new DataControlFakeSnapshot(
            $this->database->documents[$this->collection][$this->id] ?? null,
            $this->id,
        );
    }

    public function set(array $data, array $options = []): void
    {
        $current = $this->database->documents[$this->collection][$this->id] ?? [];
        $this->database->documents[$this->collection][$this->id] = ($options['merge'] ?? false)
            ? array_replace($current, $data)
            : $data;
    }
}

class DataControlFakeTransaction
{
    public function snapshot(DataControlFakeDocumentReference $reference): DataControlFakeSnapshot
    {
        return $reference->snapshot();
    }

    public function set(
        DataControlFakeDocumentReference $reference,
        array $data,
        array $options = [],
    ): void {
        $reference->set($data, $options);
    }
}

class DataControlFakeSnapshot
{
    public function __construct(
        private ?array $data,
        private string $documentId,
    ) {}

    public function exists(): bool
    {
        return $this->data !== null;
    }

    public function data(): array
    {
        return $this->data ?? [];
    }

    public function id(): string
    {
        return $this->documentId;
    }
}
