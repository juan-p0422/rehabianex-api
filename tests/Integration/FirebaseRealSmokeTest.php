<?php

namespace Tests\Integration;

use App\Services\FirebaseService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FirebaseRealSmokeTest extends TestCase
{
    private FirebaseService $firebase;

    private array $userUids = [];

    private array $documents = [];

    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('RUN_FIREBASE_SMOKE', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Define RUN_FIREBASE_SMOKE=true para ejecutar contra Firebase real.');
        }

        $expectedProject = (string) env('FIREBASE_SMOKE_PROJECT_CONFIRM', '');
        $configuredProject = (string) config('services.firebase.project_id');

        if ($expectedProject === '' || ! hash_equals($configuredProject, $expectedProject)) {
            $this->markTestSkipped('FIREBASE_SMOKE_PROJECT_CONFIRM debe coincidir con FIREBASE_PROJECT_ID.');
        }

        $this->firebase = app(FirebaseService::class);
        $this->cleanupStaleSmokeArtifacts();
    }

    protected function tearDown(): void
    {
        if (isset($this->firebase)) {
            foreach (array_reverse($this->documents) as [$collection, $id]) {
                try {
                    $this->firebase->db()->collection($collection)->document($id)->delete();
                } catch (\Throwable) {
                    // La limpieza queda limitada a documentos registrados por este smoke test.
                }
            }

            foreach (array_reverse(array_unique($this->userUids)) as $uid) {
                try {
                    $this->firebase->auth()->deleteUser($uid);
                } catch (\Throwable) {
                    // La limpieza queda limitada a usuarios creados por este smoke test.
                }
            }
        }

        parent::tearDown();
    }

    public function test_complete_real_firebase_contract_flow(): void
    {
        $suffix = strtolower(Str::random(10));
        $password = 'Smoke!'.Str::random(16).'7';
        $patientEmail = "smoke.patient.{$suffix}@example.com";
        $supervisorEmail = "smoke.supervisor.{$suffix}@example.com";
        $adminEmail = "smoke.admin.{$suffix}@example.com";

        [$adminUid, $adminToken] = $this->createAdmin($adminEmail, $password);

        $patientRegistration = $this->apiCall('POST', '/api/auth/register', [
            'email' => $patientEmail,
            'password' => $password,
            'full_name' => 'Smoke Patient',
            'role' => 'patient',
            'privacy_notice_accepted' => true,
            'privacy_notice_version' => config('legal.privacy_notice_version'),
        ])->assertCreated();
        $this->record('POST /auth/register patient', $patientRegistration);
        $patientUid = $patientRegistration->json('auth.uid');
        $patientToken = $patientRegistration->json('auth.id_token');
        $patientRefresh = $patientRegistration->json('auth.refresh_token');
        $this->trackUser($patientUid, 'patients');

        $supervisorRegistration = $this->apiCall('POST', '/api/auth/register', [
            'email' => $supervisorEmail,
            'password' => $password,
            'full_name' => 'Smoke Supervisor',
            'role' => 'supervisor',
            'privacy_notice_accepted' => true,
            'privacy_notice_version' => config('legal.privacy_notice_version'),
            'supervisor_type' => 'padrino',
        ])->assertCreated();
        $this->record('POST /auth/register supervisor', $supervisorRegistration);
        $supervisorUid = $supervisorRegistration->json('auth.uid');
        $pendingSupervisorToken = $supervisorRegistration->json('auth.id_token');
        $this->trackUser($supervisorUid, 'supervisors');

        $pendingDenied = $this->apiCall(
            'GET',
            "/api/supervisors/{$supervisorUid}/patients",
            [],
            $pendingSupervisorToken
        )->assertForbidden();
        $this->record('GET sensitive endpoint pending supervisor', $pendingDenied);

        $authorized = $this->apiCall(
            'PATCH',
            "/api/admin/supervisors/{$supervisorUid}/authorize",
            ['notes' => 'Firebase real smoke test'],
            $adminToken
        )->assertOk();
        $this->record('PATCH /admin/supervisors/{uid}/authorize', $authorized);

        $patientLogin = $this->apiCall('POST', '/api/auth/login', [
            'email' => $patientEmail,
            'password' => $password,
        ])->assertOk();
        $this->record('POST /auth/login patient', $patientLogin);
        $patientToken = $patientLogin->json('auth.id_token');

        $supervisorLogin = $this->apiCall('POST', '/api/auth/login', [
            'email' => $supervisorEmail,
            'password' => $password,
        ])->assertOk();
        $this->record('POST /auth/login supervisor', $supervisorLogin);
        $supervisorToken = $supervisorLogin->json('auth.id_token');

        $this->record('GET /auth/me patient', $this->apiCall('GET', '/api/auth/me', [], $patientToken)->assertOk());
        $this->record('GET /auth/me supervisor', $this->apiCall('GET', '/api/auth/me', [], $supervisorToken)->assertOk());

        $refresh = $this->apiCall('POST', '/api/auth/refresh', ['refresh_token' => $patientRefresh])->assertOk();
        $this->record('POST /auth/refresh', $refresh);

        $profile = $this->apiCall('PATCH', "/api/patients/{$patientUid}", [
            'nickname' => 'Smoke Alias',
            'privacy_mode' => true,
        ], $patientToken)->assertOk();
        $this->record('PATCH /patients/{uid}', $profile);

        $noteMutationId = Str::uuid()->toString();
        $notePayload = [
            'client_mutation_id' => $noteMutationId,
            'mood' => 'estable',
            'mood_score' => 7,
            'anxiety_level' => 3,
            'craving_level' => 2,
            'had_relapse' => false,
            'note_text' => 'Texto privado que no debe llegar al proveedor IA.',
        ];
        $note = $this->apiCall('POST', "/api/patients/{$patientUid}/notes", $notePayload, $patientToken)->assertCreated();
        $this->track('patient_notes', $note->json('note.note_id'));
        $this->record('POST /patients/{uid}/notes', $note);
        $noteReplay = $this->apiCall('POST', "/api/patients/{$patientUid}/notes", $notePayload, $patientToken)->assertOk();
        $this->assertTrue($noteReplay->json('idempotent_replay'));
        $this->assertSame($note->json('note.note_id'), $noteReplay->json('note.note_id'));
        $this->record('POST /patients/{uid}/notes idempotent replay', $noteReplay);

        $contact = $this->apiCall('POST', '/api/support-contacts', [
            'name' => 'Contacto Smoke',
            'phone' => '+524490000001',
            'relationship' => 'familiar',
        ], $patientToken)->assertCreated();
        $this->track('support_contacts', $contact->json('id'));
        $this->record('POST /support-contacts', $contact);

        $event = $this->apiCall('POST', '/api/agenda-events', [
            'title' => 'Evento Smoke',
            'starts_at' => now()->addDay()->toIso8601String(),
            'status' => 'scheduled',
        ], $patientToken)->assertCreated();
        $this->track('agenda_events', $event->json('id'));
        $this->record('POST /agenda-events', $event);

        $settings = $this->apiCall('POST', '/api/notification-settings', [
            'daily_note_enabled' => true,
            'daily_note_time' => '21:15',
        ], $patientToken)->assertCreated();
        $this->track('notification_settings', $patientUid);
        $this->assertSame($patientUid, $settings->json('data.patient_uid'));
        $settingsAgain = $this->apiCall('POST', '/api/notification-settings', [
            'daily_check_in_enabled' => false,
        ], $patientToken)->assertOk();
        $this->record('POST /notification-settings idempotent', $settingsAgain);
        $this->record(
            'GET /notification-settings supervisor denied',
            $this->apiCall('GET', '/api/notification-settings', [], $supervisorToken)->assertForbidden()
        );

        $link = $this->createRequest($patientToken, $supervisorUid, 'link');
        $this->record('POST /supervision-requests type=link', $link);
        $this->record(
            'PATCH /supervision-requests/{id}/respond link',
            $this->respond($link->json('id'), 'accepted', $supervisorToken)->assertOk()
        );

        $consent = $this->apiCall('POST', '/api/consents', [
            'supervisor_uid' => $supervisorUid,
            'type' => 'supervision_and_alerts',
            'explicit_consent' => true,
            'consent_text' => 'Consentimiento smoke.',
            'scope' => [
                'patient_notes', 'agenda_events', 'support_contacts',
                'ai_chat_summary', 'patient_achievements',
            ],
        ], $patientToken)->assertCreated();
        $consentId = $consent->json('id');
        $this->track('consents', $consentId);
        $this->record('POST /consents', $consent);

        $pause = $this->apiCall('PATCH', "/api/consents/{$consentId}", ['status' => 'paused'], $patientToken)->assertOk();
        $this->record('PATCH /consents/{id} paused', $pause);
        $this->record(
            'GET notes after pause denied',
            $this->apiCall('GET', "/api/patients/{$patientUid}/notes", [], $supervisorToken)->assertForbidden()
        );

        $active = $this->apiCall('PATCH', "/api/consents/{$consentId}", ['status' => 'active'], $patientToken)->assertOk();
        $this->record('PATCH /consents/{id} active', $active);

        $safePatients = $this->apiCall(
            'GET',
            "/api/supervisors/{$supervisorUid}/patients",
            [],
            $supervisorToken
        )->assertOk();
        $this->assertNull($safePatients->json('patients.0.full_name'));
        $this->assertNull($safePatients->json('patients.0.email'));
        $this->record('GET /supervisors/{uid}/patients projected', $safePatients);
        $this->record('GET /patients/{uid} projected', $this->apiCall('GET', "/api/patients/{$patientUid}", [], $supervisorToken)->assertOk());
        $this->record('GET notes with consent', $this->apiCall('GET', "/api/patients/{$patientUid}/notes", [], $supervisorToken)->assertOk());
        $this->record('GET contacts with consent', $this->apiCall('GET', "/api/support-contacts?patient_uid={$patientUid}", [], $supervisorToken)->assertOk());
        $this->record('GET agenda with consent', $this->apiCall('GET', "/api/agenda-events?patient_uid={$patientUid}", [], $supervisorToken)->assertOk());
        $this->record('GET achievements with consent', $this->apiCall('GET', "/api/patient-achievements?patient_uid={$patientUid}", [], $supervisorToken)->assertOk());

        $localChat = $this->chat($supervisorUid, 'Resume P-001.', 'local', $supervisorToken)->assertOk();
        $this->assertFalse($localChat->json('stored'));
        $this->assertNull($localChat->json('session_id'));
        $this->record('POST /ai/supervisor-chat mode=local', $localChat);

        $providerChat = $this->chat($supervisorUid, 'Resume riesgos recientes.', 'ai', $supervisorToken)->assertOk();
        $this->assertContains($providerChat->json('mode'), ['ai', 'fallback_local']);
        $this->assertFalse($providerChat->json('stored'));
        $this->assertNull($providerChat->json('session_id'));
        $this->record('POST /ai/supervisor-chat mode=ai|fallback', $providerChat);

        $history = $this->apiCall('GET', '/api/ai-chat-sessions', [], $supervisorToken)->assertForbidden();
        $this->record('GET /ai-chat-sessions disabled', $history);

        $revoked = $this->apiCall('PATCH', "/api/consents/{$consentId}", ['status' => 'revoked'], $patientToken)->assertOk();
        $this->record('PATCH /consents/{id} revoked', $revoked);
        $afterRevokeChat = $this->chat($supervisorUid, 'Resumen posterior.', 'local', $supervisorToken)->assertOk();
        $this->assertSame(0, $afterRevokeChat->json('context.authorized_patients_count'));
        $this->assertFalse($afterRevokeChat->json('stored'));
        $this->record('POST chat after revoke excludes patient', $afterRevokeChat);
        $this->record('GET history remains disabled', $this->apiCall('GET', '/api/ai-chat-sessions', [], $supervisorToken)->assertForbidden());

        $unlink = $this->createRequest($patientToken, $supervisorUid, 'unlink');
        $this->record('POST /supervision-requests type=unlink', $unlink);
        $this->record(
            'PATCH respond unlink',
            $this->respond($unlink->json('id'), 'accepted', $supervisorToken)->assertOk()
        );

        $secondLink = $this->createRequest($patientToken, $supervisorUid, 'link');
        $this->respond($secondLink->json('id'), 'accepted', $supervisorToken)->assertOk();
        $secondConsent = $this->apiCall('POST', '/api/consents', [
            'supervisor_uid' => $supervisorUid,
            'explicit_consent' => true,
            'consent_text' => 'Segundo consentimiento smoke.',
            'scope' => ['patient_notes'],
        ], $patientToken)->assertCreated();
        $this->track('consents', $secondConsent->json('id'));
        $directUnlink = $this->apiCall(
            'POST',
            "/api/supervisors/{$supervisorUid}/patients/{$patientUid}/unlink",
            [],
            $patientToken
        )->assertOk();
        $this->record('POST direct unlink', $directUnlink);

        $this->record('401 without token', $this->apiCall('GET', '/api/auth/me')->assertUnauthorized());
        $this->record(
            '422 invalid note',
            $this->apiCall('POST', "/api/patients/{$patientUid}/notes", ['mood' => 'x'], $patientToken)->assertStatus(422)
        );

        $rateLimited = null;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $candidate = $this->apiCall('POST', '/api/auth/login', [
                'email' => "missing.{$suffix}@example.com",
                'password' => 'invalid-password',
            ]);
            if ($candidate->status() === 429) {
                $rateLimited = $candidate;
                break;
            }
        }
        $this->assertNotNull($rateLimited, 'El throttle de login no produjo 429.');
        $this->record('429 login throttle', $rateLimited);

        fwrite(STDERR, "\nFirebase real smoke results:\n");
        foreach ($this->results as [$endpoint, $status]) {
            fwrite(STDERR, sprintf("- %s: %d\n", $endpoint, $status));
        }

        $this->assertNotEmpty($adminUid);
    }

    private function createAdmin(string $email, string $password): array
    {
        $user = $this->firebase->auth()->createUser([
            'email' => $email,
            'password' => $password,
            'displayName' => 'Smoke Admin',
            'disabled' => false,
        ]);
        $this->userUids[] = $user->uid;
        $this->firebase->auth()->setCustomUserClaims($user->uid, ['role' => 'admin']);
        $this->firebase->db()->collection('admins')->document($user->uid)->set([
            'uid' => $user->uid,
            'role' => 'admin',
            'full_name' => 'Smoke Admin',
            'email' => $email,
            'status' => 'active',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);
        $this->track('admins', $user->uid);

        return [$user->uid, $this->signIn($email, $password)['idToken']];
    }

    private function cleanupStaleSmokeArtifacts(): void
    {
        foreach ($this->firebase->auth()->listUsers() as $user) {
            $email = (string) ($user->email ?? '');

            if (preg_match('/^smoke\.(patient|supervisor|admin)\.[a-z0-9]+@example\.com$/', $email) !== 1) {
                continue;
            }

            $uid = $user->uid;
            $sessionIds = $this->deleteWhere('ai_chat_sessions', 'supervisor_uid', $uid, true);

            foreach ($sessionIds as $sessionId) {
                $this->deleteWhere('ai_chat_messages', 'session_id', $sessionId);
            }

            foreach ([
                ['patient_notes', 'patient_uid'],
                ['support_contacts', 'patient_uid'],
                ['agenda_events', 'patient_uid'],
                ['agenda_events', 'supervisor_uid'],
                ['consents', 'patient_uid'],
                ['consents', 'supervisor_uid'],
                ['interventions', 'patient_uid'],
                ['interventions', 'supervisor_uid'],
                ['supervision_requests', 'patient_uid'],
                ['supervision_requests', 'supervisor_uid'],
                ['patient_achievements', 'patient_uid'],
            ] as [$collection, $field]) {
                $this->deleteWhere($collection, $field, $uid);
            }

            foreach (['patients', 'supervisors', 'admins', 'notification_settings'] as $collection) {
                $this->firebase->db()->collection($collection)->document($uid)->delete();
            }

            $this->firebase->auth()->deleteUser($uid);
        }
    }

    private function deleteWhere(
        string $collection,
        string $field,
        string $value,
        bool $returnIds = false
    ): array {
        $ids = [];

        foreach ($this->firebase->db()->collection($collection)->where($field, '=', $value)->documents() as $document) {
            if (! $document->exists()) {
                continue;
            }

            $ids[] = $document->id();
            $this->firebase->db()->collection($collection)->document($document->id())->delete();
        }

        return $returnIds ? $ids : [];
    }

    private function signIn(string $email, string $password): array
    {
        $response = Http::asJson()->post(
            'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key='.
                config('services.firebase.web_api_key'),
            ['email' => $email, 'password' => $password, 'returnSecureToken' => true]
        );
        $response->throw();

        return $response->json();
    }

    private function createRequest(string $token, string $supervisorUid, string $type): TestResponse
    {
        $response = $this->apiCall('POST', '/api/supervision-requests', [
            'supervisor_uid' => $supervisorUid,
            'type' => $type,
            'message' => "Smoke {$type}",
        ], $token)->assertCreated();
        $this->track('supervision_requests', $response->json('id'));

        return $response;
    }

    private function respond(string $requestId, string $status, string $token): TestResponse
    {
        return $this->apiCall(
            'PATCH',
            "/api/supervision-requests/{$requestId}/respond",
            ['status' => $status],
            $token
        );
    }

    private function chat(string $uid, string $question, string $mode, string $token): TestResponse
    {
        return $this->apiCall('POST', '/api/ai/supervisor-chat', [
            'supervisor_uid' => $uid,
            'question' => $question,
            'mode' => $mode,
        ], $token);
    }

    private function apiCall(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->json($method, $uri, $data, $headers);
    }

    private function record(string $endpoint, TestResponse $response): void
    {
        $this->results[] = [$endpoint, $response->status()];
    }

    private function trackUser(string $uid, string $profileCollection): void
    {
        $this->userUids[] = $uid;
        $this->track($profileCollection, $uid);
    }

    private function track(string $collection, ?string $id): void
    {
        if (is_string($id) && $id !== '') {
            $this->documents[] = [$collection, $id];
        }
    }
}
