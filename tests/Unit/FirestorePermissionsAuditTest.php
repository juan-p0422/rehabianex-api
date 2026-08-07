<?php

namespace Tests\Unit;

use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FirestorePermissionsAuditTest extends TestCase
{
    public function test_patient_reads_only_own_data_and_cannot_modify_another_patient(): void
    {
        [$store, $access] = $this->environment();
        $patient = $this->request('patient', 'patient-1');

        $access->assertCanRead('patients', $store->data['patients']['patient-1'], $patient);
        $query = $access->scopeIndex(
            $store->collection('patient_notes'),
            'patient-notes',
            $patient
        );
        $this->assertSame([['patient_uid', 'patient-1']], $query->filters);

        $this->assertForbidden(fn () => $access->assertCanRead(
            'patients',
            $store->data['patients']['patient-2'],
            $patient
        ));
        $this->assertForbidden(fn () => $access->prepareUpdate(
            'patients',
            $store->data['patients']['patient-2'],
            ['nickname' => 'Cambio indebido'],
            $patient
        ));
    }

    public function test_unlinked_or_unconsented_supervisor_is_denied(): void
    {
        [$store, $access] = $this->environment();

        $this->assertFalse($access->supervisorCanAccessPatient('supervisor-1', 'patient-3'));

        unset($store->data['consents']['consent-1']);
        $this->assertFalse($access->supervisorCanAccessPatient('supervisor-1', 'patient-1'));
        $this->assertForbidden(fn () => $access->assertSupervisorPatientAccess(
            'supervisor-1',
            'patient-1',
            ['patient_notes']
        ));
    }

    public function test_partial_consent_requires_every_requested_scope(): void
    {
        [, $access] = $this->environment();

        $this->assertTrue($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['patient_notes']
        ));
        $this->assertFalse($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['support_contacts']
        ));
        $this->assertFalse($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['patient_notes', 'support_contacts']
        ));
    }

    public function test_full_consent_allows_only_linked_supervisor(): void
    {
        [$store, $access] = $this->environment();
        $store->data['consents']['consent-1']['scope'] = [
            'patient_notes',
            'support_contacts',
            'agenda_events',
            'ai_chat_summary',
        ];

        foreach ($store->data['consents']['consent-1']['scope'] as $scope) {
            $this->assertTrue(
                $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', [$scope]),
                $scope
            );
        }

        $this->assertFalse(
            $access->supervisorCanAccessPatient('supervisor-2', 'patient-1')
        );
    }

    public function test_revoking_consent_blocks_access_immediately(): void
    {
        [$store, $access] = $this->environment();

        $this->assertTrue(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );

        $store->data['consents']['consent-1']['explicit_consent'] = false;
        $store->data['consents']['consent-1']['status'] = 'revoked';
        $store->data['consents']['consent-1']['revoked_at'] = now()->toIso8601String();

        $this->assertFalse(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );
    }

    public function test_paused_consent_blocks_access_even_if_legacy_flags_are_inconsistent(): void
    {
        [$store, $access] = $this->environment();
        $store->data['consents']['consent-1']['status'] = 'paused';
        $store->data['consents']['consent-1']['explicit_consent'] = true;
        $store->data['consents']['consent-1']['revoked_at'] = null;

        $this->assertFalse(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );
    }

    public function test_active_legacy_consent_without_status_remains_compatible(): void
    {
        [$store, $access] = $this->environment();
        unset($store->data['consents']['consent-1']['status']);

        $this->assertTrue(
            $access->supervisorCanAccessPatient('supervisor-1', 'patient-1', ['patient_notes'])
        );
    }

    public function test_patient_can_only_read_active_authorized_verified_supervisor(): void
    {
        [$store, $access] = $this->environment();
        $patient = $this->request('patient', 'patient-1');

        $access->assertCanRead('supervisors', $store->data['supervisors']['supervisor-1'], $patient);

        $inconsistent = $store->data['supervisors']['supervisor-1'];
        $inconsistent['authorized'] = false;

        $this->assertForbidden(fn () => $access->assertCanRead(
            'supervisors',
            $inconsistent,
            $patient
        ));
    }

    public function test_interventions_require_authorized_linked_supervisor(): void
    {
        [$store, $access] = $this->environment();
        $authorized = $this->request('supervisor', 'supervisor-1');
        $otherSupervisor = $this->request('supervisor', 'supervisor-2');
        $pending = $this->request('supervisor', 'supervisor-pending');
        $patient = $this->request('patient', 'patient-1');
        $payload = [
            'patient_uid' => 'patient-1',
            'reason' => 'Riesgo elevado.',
            'actions' => ['Contactar al paciente'],
        ];

        $created = $access->prepareCreate('interventions', $payload, $authorized);
        $this->assertSame('supervisor-1', $created['supervisor_uid']);

        $this->assertForbidden(fn () => $access->prepareCreate(
            'interventions',
            $payload,
            $pending
        ));
        $this->assertForbidden(fn () => $access->prepareCreate(
            'interventions',
            $payload,
            $patient
        ));

        $current = array_replace($created, [
            'intervention_id' => 'intervention-1',
            'status' => 'open',
        ]);
        $changes = $access->prepareUpdate(
            'interventions',
            $current,
            ['status' => 'completed'],
            $authorized
        );
        $this->assertSame('completed', $changes['status']);

        $this->assertForbidden(fn () => $access->prepareUpdate(
            'interventions',
            $current,
            ['status' => 'completed'],
            $otherSupervisor
        ));
    }

    public function test_supervisor_patient_projection_removes_identity_and_internal_fields(): void
    {
        [$store, $access] = $this->environment();
        $patient = array_replace($store->data['patients']['patient-1'], [
            'full_name' => 'Nombre Privado',
            'nickname' => 'Apodo',
            'email' => 'patient@example.com',
            'phone' => '+524491234567',
            'photo_url' => 'https://example.test/private-photo.jpg',
            'updated_at' => '2026-08-04T10:30:00-06:00',
            'collection' => 'patients',
            'document_id' => 'patient-1',
            'privacy_mode' => true,
            'role' => 'patient',
            'internal_flag' => 'secret',
        ]);

        $safe = $access->sanitizeForSupervisor('patients', $patient, 'supervisor-1');

        $this->assertSame('Apodo', $safe['display_name']);
        $this->assertSame('Apodo', $safe['safe_display_name']);
        $this->assertNull($safe['full_name']);
        $this->assertArrayNotHasKey('email', $safe);
        $this->assertArrayNotHasKey('phone', $safe);
        $this->assertArrayNotHasKey('photo_url', $safe);
        $this->assertArrayNotHasKey('updated_at', $safe);
        $this->assertArrayNotHasKey('collection', $safe);
        $this->assertArrayNotHasKey('document_id', $safe);
        $this->assertArrayNotHasKey('role', $safe);
        $this->assertArrayNotHasKey('internal_flag', $safe);

        $store->data['consents']['consent-1']['scope'][] = 'patient_phone';
        $withPhone = $access->sanitizeForSupervisor('patients', $patient, 'supervisor-1');
        $this->assertSame('+524491234567', $withPhone['phone']);
    }

    public function test_ai_history_is_disabled_even_for_the_session_owner(): void
    {
        [, $access] = $this->environment();
        $owner = $this->request('supervisor', 'supervisor-1');
        $other = $this->request('supervisor', 'supervisor-2');
        $safe = [
            'session_id' => 'safe-session',
            'supervisor_uid' => 'supervisor-1',
            'channel' => 'supervisor_chat',
            'patient_refs' => ['P-001'],
        ];

        $this->assertForbidden(fn () => $access->assertCanRead('ai-chat-sessions', $safe, $owner));
        $this->assertForbidden(fn () => $access->assertCanRead('ai-chat-sessions', $safe, $other));

        $legacy = array_replace($safe, ['patient_uids' => ['patient-1']]);
        $this->assertForbidden(fn () => $access->assertCanRead('ai-chat-sessions', $legacy, $owner));
    }

    public function test_notification_settings_infer_patient_uid_and_deny_supervisors(): void
    {
        [, $access] = $this->environment();
        $created = $access->prepareCreate(
            'notification-settings',
            ['daily_note_enabled' => true],
            $this->request('patient', 'patient-1')
        );

        $this->assertSame('patient-1', $created['patient_uid']);
        $this->assertSame('patient-1', $created['user_uid']);

        $this->assertForbidden(fn () => $access->prepareCreate(
            'notification-settings',
            ['daily_note_enabled' => true],
            $this->request('supervisor', 'supervisor-1')
        ));
    }

    public function test_each_supervisor_scope_only_unlocks_its_own_resource(): void
    {
        [$store, $access] = $this->environment();

        $this->assertTrue($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['patient_notes']
        ));
        $this->assertFalse($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['support_contacts']
        ));

        $store->data['consents']['consent-1']['scope'] = ['support_contacts'];
        $this->assertTrue($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['support_contacts']
        ));
        $this->assertFalse($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['patient_notes']
        ));

        $store->data['consents']['consent-1']['scope'] = ['agenda_events'];
        $this->assertTrue($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['agenda_events']
        ));
    }

    public function test_authorized_supervisor_is_denied_when_unlinked_or_linked_without_consent(): void
    {
        [$store, $access] = $this->environment();

        $this->assertFalse($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-3',
            ['patient_notes']
        ));

        unset($store->data['consents']['consent-1']);
        $this->assertFalse($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-1',
            ['patient_notes']
        ));
    }

    public function test_supervisor_cannot_read_patient_assigned_to_another_supervisor(): void
    {
        [, $access] = $this->environment();

        $this->assertFalse($access->supervisorCanAccessPatient(
            'supervisor-1',
            'patient-2'
        ));
        $this->assertForbidden(fn () => $access->assertSupervisorPatientAccess(
            'supervisor-1',
            'patient-2'
        ));
    }

    public function test_intervention_is_denied_without_active_consent(): void
    {
        [$store, $access] = $this->environment();
        unset($store->data['consents']['consent-1']);

        $this->assertForbidden(fn () => $access->prepareCreate(
            'interventions',
            ['patient_uid' => 'patient-1', 'reason' => 'Prueba'],
            $this->request('supervisor', 'supervisor-1')
        ));
    }

    private function environment(): array
    {
        $store = new PermissionAuditFirestore([
            'supervisors' => [
                'supervisor-1' => $this->supervisor('supervisor-1', true),
                'supervisor-2' => $this->supervisor('supervisor-2', true),
                'supervisor-pending' => $this->supervisor('supervisor-pending', false),
            ],
            'patients' => [
                'patient-1' => $this->patient('patient-1', 'supervisor-1'),
                'patient-2' => $this->patient('patient-2', 'supervisor-2'),
                'patient-3' => $this->patient('patient-3', null),
            ],
            'consents' => [
                'consent-1' => [
                    'consent_id' => 'consent-1',
                    'patient_uid' => 'patient-1',
                    'supervisor_uid' => 'supervisor-1',
                    'explicit_consent' => true,
                    'status' => 'active',
                    'scope' => ['patient_notes'],
                    'revoked_at' => null,
                ],
            ],
            'patient_notes' => [],
        ]);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->andReturn($store);

        return [$store, new FirestoreAccessService($firebase)];
    }

    private function supervisor(string $uid, bool $authorized): array
    {
        return [
            'uid' => $uid,
            'authorized' => $authorized,
            'verified' => $authorized,
            'status' => $authorized ? 'active' : 'pending_review',
        ];
    }

    private function patient(string $uid, ?string $supervisorUid): array
    {
        return [
            'uid' => $uid,
            'supervisor_uid' => $supervisorUid,
            'wants_supervision' => $supervisorUid !== null,
            'supervision_status' => $supervisorUid !== null ? 'accepted' : 'not_requested',
        ];
    }

    private function request(string $role, string $uid): Request
    {
        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('firebase_role', $role);
        $request->attributes->set('firebase_uid', $uid);

        return $request;
    }

    private function assertForbidden(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La operación debía responder 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}

class PermissionAuditFirestore
{
    public function __construct(public array $data) {}

    public function collection(string $name): PermissionAuditCollection
    {
        $this->data[$name] ??= [];

        return new PermissionAuditCollection($this, $name);
    }
}

class PermissionAuditCollection
{
    public array $filters = [];

    public function __construct(
        private PermissionAuditFirestore $store,
        private string $name
    ) {}

    public function document(string $id): PermissionAuditDocument
    {
        return new PermissionAuditDocument($this->store, $this->name, $id);
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

            $documents[] = new PermissionAuditSnapshot((string) $id, $data, true);
        }

        return $documents;
    }
}

class PermissionAuditDocument
{
    public function __construct(
        private PermissionAuditFirestore $store,
        private string $collection,
        private string $id
    ) {}

    public function snapshot(): PermissionAuditSnapshot
    {
        $exists = array_key_exists($this->id, $this->store->data[$this->collection]);

        return new PermissionAuditSnapshot(
            $this->id,
            $exists ? $this->store->data[$this->collection][$this->id] : [],
            $exists
        );
    }
}

class PermissionAuditSnapshot
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
