<?php

namespace App\Services;

use App\Support\PatientDisplayName;
use Carbon\Carbon;
use Google\Cloud\Core\Timestamp;
use Illuminate\Http\Request;

class FirestoreAccessService
{
    private const CONSENT_SCOPE_ALIASES = [
        'ai_summary' => 'ai_chat_summary',
        'chat_ai' => 'ai_chat_summary',
        'summary_ai' => 'ai_chat_summary',
    ];

    private const CONSENT_SCOPES = [
        'patient_notes',
        'agenda_events',
        'support_contacts',
        'ai_chat_summary',
        'patient_achievements',
        'patient_phone',
    ];

    private $db;

    public function __construct(FirebaseService $firebase)
    {
        $this->db = $firebase->db();
    }

    public function uid(Request $request): string
    {
        return (string) $request->attributes->get('firebase_uid');
    }

    public function role(Request $request): string
    {
        return (string) $request->attributes->get('firebase_role');
    }

    public function scopeIndex($query, string $resource, Request $request)
    {
        $this->assertAiHistoryEnabled($resource);

        $uid = $this->uid($request);
        $role = $this->role($request);

        if (in_array($resource, ['achievements', 'local-resources'], true)) {
            return $query;
        }

        if ($resource === 'supervisors') {
            return $role === 'patient'
                ? $query->where('status', '=', 'active')
                : $query->where('uid', '=', $uid);
        }

        if ($resource === 'patients') {
            return $role === 'patient'
                ? $query->where('uid', '=', $uid)
                : $query->where('supervisor_uid', '=', $uid);
        }

        if ($resource === 'ai-chat-messages') {
            $sessionId = (string) $request->query('session_id', '');

            if ($sessionId === '') {
                abort(422, 'Debes enviar el filtro session_id.');
            }

            $session = $this->documentData('ai_chat_sessions', $sessionId);
            $this->assertCanRead('ai-chat-sessions', $session, $request);

            return $query->where('session_id', '=', $sessionId);
        }

        if ($role === 'patient') {
            if (in_array($resource, [
                'patient-notes',
                'support-contacts',
                'agenda-events',
                'patient-achievements',
                'consents',
                'notification-settings',
                'interventions',
                'supervision-requests',
                'ai-chat-sessions',
            ], true)) {
                return $query->where('patient_uid', '=', $uid);
            }

            abort(403, 'No tienes permiso para listar este recurso.');
        }

        $this->assertAuthorizedSupervisor($uid);

        if (in_array($resource, [
            'agenda-events',
            'consents',
            'interventions',
            'supervision-requests',
            'ai-chat-sessions',
        ], true)) {
            return $query->where('supervisor_uid', '=', $uid);
        }

        if (in_array($resource, [
            'patient-notes',
            'support-contacts',
            'patient-achievements',
        ], true)) {
            $patientUid = (string) $request->query('patient_uid', '');

            if ($patientUid === '') {
                abort(422, 'Debes enviar el filtro patient_uid.');
            }

            $scope = match ($resource) {
                'patient-notes' => ['patient_notes'],
                'support-contacts' => ['support_contacts'],
                'patient-achievements' => ['patient_achievements'],
                default => [],
            };

            $this->assertSupervisorPatientAccess($uid, $patientUid, $scope);

            return $query->where('patient_uid', '=', $patientUid);
        }

        if ($resource === 'notification-settings') {
            abort(403, 'La configuracion de notificaciones es privada del paciente.');
        }

        abort(403, 'No tienes permiso para listar este recurso.');
    }

    public function assertCanRead(string $resource, array $data, Request $request): void
    {
        $this->assertAiHistoryEnabled($resource);

        $uid = $this->uid($request);
        $role = $this->role($request);

        if (in_array($resource, ['achievements', 'local-resources'], true)) {
            return;
        }

        if ($resource === 'supervisors') {
            if (($data['uid'] ?? null) === $uid && $role === 'supervisor') {
                return;
            }

            if ($role === 'patient' && $this->isAuthorizedSupervisorProfile($data)) {
                return;
            }

            abort(403, 'No tienes permiso para acceder a este supervisor.');
        }

        if ($resource === 'patients') {
            if ($role === 'patient' && ($data['uid'] ?? null) === $uid) {
                return;
            }

            if ($role === 'supervisor') {
                $this->assertAuthorizedSupervisor($uid);
                $this->assertSupervisorPatientAccess($uid, (string) ($data['uid'] ?? ''));

                return;
            }
        }

        if ($resource === 'ai-chat-messages') {
            $session = $this->documentData('ai_chat_sessions', (string) ($data['session_id'] ?? ''));
            $this->assertCanRead('ai-chat-sessions', $session, $request);

            return;
        }

        if ($resource === 'ai-chat-sessions' && ($data['channel'] ?? null) === 'supervisor_chat') {
            if ($role !== 'supervisor' || ($data['supervisor_uid'] ?? null) !== $uid) {
                abort(403, 'La conversacion de IA no pertenece a tu sesion.');
            }

            $this->assertAuthorizedSupervisor($uid);

            if (! empty($data['patient_uid']) || ! empty($data['patient_uids'])) {
                abort(403, 'El historial legado con identificadores de pacientes esta bloqueado por privacidad.');
            }

            return;
        }

        $patientUid = (string) ($data['patient_uid'] ?? '');

        if ($role === 'patient' && $patientUid !== '' && $patientUid === $uid) {
            return;
        }

        if ($role === 'supervisor') {
            $this->assertAuthorizedSupervisor($uid);

            if ($resource === 'supervision-requests' && ($data['supervisor_uid'] ?? null) === $uid) {
                return;
            }

            if ($resource === 'consents' && ($data['supervisor_uid'] ?? null) === $uid) {
                return;
            }

            if ($resource === 'notification-settings') {
                abort(403, 'La configuracion de notificaciones es privada del paciente.');
            }

            if (in_array($resource, ['agenda-events', 'interventions', 'ai-chat-sessions'], true)
                && ($data['supervisor_uid'] ?? null) !== $uid) {
                abort(403, 'El documento no pertenece a tu sesion.');
            }

            $scope = match ($resource) {
                'patient-notes' => ['patient_notes'],
                'support-contacts' => ['support_contacts'],
                'agenda-events' => ['agenda_events'],
                'patient-achievements' => ['patient_achievements'],
                'ai-chat-sessions' => ['ai_chat_summary'],
                default => [],
            };

            if ($patientUid !== '') {
                $this->assertSupervisorPatientAccess($uid, $patientUid, $scope);

                return;
            }
        }

        abort(403, 'No tienes permiso para acceder a este documento.');
    }

    private function assertAiHistoryEnabled(string $resource): void
    {
        if (in_array($resource, ['ai-chat-sessions', 'ai-chat-messages'], true)) {
            abort(403, 'El historial persistente de IA está deshabilitado por privacidad.');
        }
    }

    public function prepareCreate(string $resource, array $data, Request $request): array
    {
        $uid = $this->uid($request);
        $role = $this->role($request);

        if (in_array($resource, [
            'patients',
            'supervisors',
            'achievements',
            'patient-achievements',
            'local-resources',
            'ai-chat-sessions',
            'ai-chat-messages',
        ], true)) {
            abort(403, 'Este recurso no admite creacion directa.');
        }

        if ($resource === 'patient-notes') {
            $this->assertRole($role, 'patient');
            $this->assertOwnerValue($data, 'patient_uid', $uid);
            $data['patient_uid'] = $uid;

            return $this->only($data, [
                'patient_uid', 'mood', 'mood_score', 'anxiety_level', 'craving_level',
                'energy_level', 'sleep_quality', 'had_relapse', 'triggers',
                'coping_actions', 'note_text',
            ]);
        }

        if ($resource === 'support-contacts') {
            $this->assertRole($role, 'patient');
            $this->assertOwnerValue($data, 'patient_uid', $uid);
            $data['patient_uid'] = $uid;

            return $this->only($data, [
                'patient_uid', 'name', 'relationship', 'phone', 'priority',
                'can_receive_alerts', 'notes',
            ]);
        }

        if ($resource === 'agenda-events') {
            if ($role === 'patient') {
                $this->assertOwnerValue($data, 'patient_uid', $uid);
                $data['patient_uid'] = $uid;
                $data['supervisor_uid'] = $this->assignedSupervisorUid($uid);
            } else {
                $this->assertAuthorizedSupervisor($uid);
                $patientUid = $this->requiredPatientUid($data);
                $this->assertSupervisorPatientAccess($uid, $patientUid, ['agenda_events']);
                $data['supervisor_uid'] = $uid;
            }

            return $this->only($data, [
                'patient_uid', 'supervisor_uid', 'type', 'title', 'starts_at',
                'ends_at', 'location', 'status', 'notes',
            ]);
        }

        if ($resource === 'consents') {
            $this->assertRole($role, 'patient');
            $this->assertOwnerValue($data, 'patient_uid', $uid);
            $data['supervisor_uid'] = $this->availableSupervisorUid((string) ($data['supervisor_uid'] ?? ''));
            $patient = $this->documentData('patients', $uid);

            if (($patient['supervisor_uid'] ?? null) !== $data['supervisor_uid']
                || ($patient['wants_supervision'] ?? false) !== true
                || ($patient['supervision_status'] ?? null) !== 'accepted') {
                abort(409, 'Debe existir una relación de supervisión aceptada antes de otorgar consentimiento.');
            }

            if (($data['explicit_consent'] ?? null) !== true) {
                abort(422, 'El consentimiento debe aceptarse explicitamente.');
            }

            $data['patient_uid'] = $uid;
            $data['status'] = 'active';
            $data['accepted_at'] = Carbon::now()->toIso8601String();
            $data['revoked_at'] = null;
            $data['paused_at'] = null;

            return $this->only($data, [
                'patient_uid', 'supervisor_uid', 'type', 'explicit_consent',
                'consent_text', 'scope', 'status', 'accepted_at', 'revoked_at', 'paused_at',
            ]);
        }

        if ($resource === 'notification-settings') {
            $this->assertRole($role, 'patient');
            $this->assertOwnerValue($data, 'patient_uid', $uid);
            $data['patient_uid'] = $uid;
            $data['user_uid'] = $uid;

            return $this->only($data, [
                'patient_uid', 'user_uid', 'daily_check_in_enabled', 'daily_check_in_time',
                'daily_note_enabled', 'daily_note_time',
                'sober_day_enabled', 'sober_day_time',
                'achievement_enabled', 'achievement_time',
                'event_reminders_enabled', 'motivational_enabled', 'motivational_time',
                'craving_alerts_enabled', 'supervisor_alerts_enabled',
                'support_contact_alerts_enabled', 'timezone',
            ]);
        }

        if ($resource === 'interventions') {
            $this->assertRole($role, 'supervisor');
            $this->assertAuthorizedSupervisor($uid);
            $patientUid = $this->requiredPatientUid($data);
            $this->assertSupervisorPatientAccess($uid, $patientUid);
            $data['supervisor_uid'] = $uid;

            return $this->only($data, [
                'patient_uid', 'supervisor_uid', 'trigger_note_id', 'reason',
                'actions', 'status', 'notes',
            ]);
        }

        if ($resource === 'supervision-requests') {
            $this->assertRole($role, 'patient');
            $this->assertOwnerValue($data, 'patient_uid', $uid);
            $type = (string) ($data['type'] ?? $data['request_type'] ?? 'link');

            if (! in_array($type, ['link', 'unlink'], true)) {
                abort(422, 'type debe ser link o unlink.');
            }

            if ($type === 'unlink') {
                $patient = $this->documentData('patients', $uid);
                $assignedSupervisorUid = (string) ($patient['supervisor_uid'] ?? '');

                if ($assignedSupervisorUid === ''
                    || ($patient['supervision_status'] ?? null) !== 'accepted') {
                    abort(409, 'El paciente no tiene una relación de supervisión activa.');
                }

                if (isset($data['supervisor_uid'])
                    && (string) $data['supervisor_uid'] !== $assignedSupervisorUid) {
                    abort(422, 'supervisor_uid no coincide con el supervisor vinculado.');
                }

                $data['supervisor_uid'] = $assignedSupervisorUid;
            } else {
                $patient = $this->documentData('patients', $uid);

                if (! empty($patient['supervisor_uid'])
                    && ($patient['supervision_status'] ?? null) === 'accepted') {
                    abort(409, 'El paciente ya tiene una relación de supervisión activa.');
                }

                $data['supervisor_uid'] = $this->availableSupervisorUid(
                    (string) ($data['supervisor_uid'] ?? '')
                );
            }

            $data['patient_uid'] = $uid;
            $data['patient_name'] = $this->patientDisplayName($uid);
            $data['patient_email'] = $this->patientEmail($uid);
            $data['type'] = $type;
            $data['status'] = 'pending';
            $data['requested_at'] = Carbon::now()->toIso8601String();
            $data['responded_at'] = null;

            return $this->only($data, [
                'patient_uid', 'patient_name', 'patient_email', 'supervisor_uid', 'type', 'status', 'message',
                'requested_at', 'responded_at',
            ]);
        }

        abort(403, 'No tienes permiso para crear este recurso.');
    }

    public function prepareUpdate(string $resource, array $current, array $data, Request $request): array
    {
        $this->assertCanRead($resource, $current, $request);
        $uid = $this->uid($request);
        $role = $this->role($request);

        if ($resource === 'patients' && $role === 'patient' && ($current['uid'] ?? null) === $uid) {
            $allowed = [
                'full_name', 'phone', 'gender', 'age', 'photo_url',
                'nickname', 'sobriety_start_date', 'is_anonymous',
                'privacy_mode', 'primary_risks',
            ];
            $rejected = array_values(array_diff(array_keys($data), $allowed));

            if ($rejected !== []) {
                abort(
                    422,
                    'Campos no permitidos para actualizar el paciente: '.implode(', ', $rejected).'.'
                );
            }

            return $this->only($data, $allowed);
        }

        if ($resource === 'supervisors' && $role === 'supervisor' && ($current['uid'] ?? null) === $uid) {
            return $this->only($data, [
                'full_name', 'phone', 'photo_url', 'supervisor_type',
                'license_number', 'specialties',
            ]);
        }

        if ($resource === 'patient-notes' && $role === 'patient') {
            return $this->only($data, [
                'mood', 'mood_score', 'anxiety_level', 'craving_level',
                'energy_level', 'sleep_quality', 'had_relapse', 'triggers',
                'coping_actions', 'note_text',
            ]);
        }

        if ($resource === 'support-contacts' && $role === 'patient') {
            return $this->only($data, [
                'name', 'relationship', 'phone', 'priority', 'can_receive_alerts', 'notes',
            ]);
        }

        if ($resource === 'agenda-events') {
            return $this->only($data, [
                'type', 'title', 'starts_at', 'ends_at', 'location', 'status', 'notes',
            ]);
        }

        if ($resource === 'consents' && $role === 'patient') {
            $updated = $this->only($data, ['explicit_consent', 'consent_text', 'scope', 'status']);
            $now = Carbon::now()->toIso8601String();
            $explicitConsentProvided = array_key_exists('explicit_consent', $updated);

            if (array_key_exists('explicit_consent', $updated)
                && $updated['explicit_consent'] === false
                && isset($updated['status'])
                && $updated['status'] !== 'revoked') {
                abort(422, 'explicit_consent=false solo es compatible con status=revoked.');
            }

            if (array_key_exists('explicit_consent', $updated)
                && $updated['explicit_consent'] === true
                && isset($updated['status'])
                && $updated['status'] !== 'active') {
                abort(422, 'explicit_consent=true solo es compatible con status=active.');
            }

            $activating = ($updated['status'] ?? null) === 'active'
                || ($updated['explicit_consent'] ?? null) === true;

            if ($activating) {
                $this->assertConsentCanBeActivated($current, $updated, $uid);
            }

            if (array_key_exists('status', $updated)) {
                if ($updated['status'] === 'active') {
                    $updated['explicit_consent'] = true;
                    $updated['accepted_at'] = $now;
                    $updated['revoked_at'] = null;
                    $updated['paused_at'] = null;
                } elseif ($updated['status'] === 'paused') {
                    $updated['explicit_consent'] = false;
                    $updated['paused_at'] = $now;
                    $updated['revoked_at'] = null;
                } elseif ($updated['status'] === 'revoked') {
                    $updated['explicit_consent'] = false;
                    $updated['revoked_at'] = $now;
                    $updated['paused_at'] = null;
                }
            }

            if ($explicitConsentProvided) {
                if ($updated['explicit_consent'] === true) {
                    $updated['status'] = 'active';
                    $updated['accepted_at'] = $now;
                    $updated['revoked_at'] = null;
                    $updated['paused_at'] = null;
                } else {
                    $updated['status'] = 'revoked';
                    $updated['revoked_at'] = $now;
                    $updated['paused_at'] = null;
                }
            }

            return $updated;
        }

        if ($resource === 'notification-settings' && $role === 'patient') {
            return $this->only($data, [
                'daily_check_in_enabled', 'daily_check_in_time', 'craving_alerts_enabled',
                'daily_note_enabled', 'daily_note_time',
                'sober_day_enabled', 'sober_day_time',
                'achievement_enabled', 'achievement_time',
                'event_reminders_enabled', 'motivational_enabled', 'motivational_time',
                'supervisor_alerts_enabled', 'support_contact_alerts_enabled', 'timezone',
            ]);
        }

        if ($resource === 'interventions' && $role === 'supervisor') {
            return $this->only($data, ['reason', 'actions', 'status', 'notes']);
        }

        if ($resource === 'supervision-requests' && $role === 'patient') {
            if (($current['status'] ?? null) !== 'pending') {
                abort(409, 'Solo puedes cancelar una solicitud pendiente.');
            }

            if (($data['status'] ?? null) !== 'cancelled') {
                abort(422, 'El paciente solo puede cambiar el estado a cancelled.');
            }

            return $this->cancellationChanges();
        }

        abort(403, 'No tienes permiso para modificar este recurso.');
    }

    private function assertConsentCanBeActivated(array $current, array $changes, string $patientUid): void
    {
        $scope = $changes['scope'] ?? $current['scope'] ?? [];

        if (! is_array($scope) || $scope === []) {
            abort(422, 'Un consentimiento activo requiere al menos un scope valido.');
        }

        $patient = $this->documentData('patients', $patientUid);

        if (($current['patient_uid'] ?? null) !== $patientUid
            || ($patient['supervisor_uid'] ?? null) !== ($current['supervisor_uid'] ?? null)
            || ($patient['wants_supervision'] ?? false) !== true
            || ($patient['supervision_status'] ?? null) !== 'accepted') {
            abort(409, 'No existe una relacion de supervision activa para reactivar el consentimiento.');
        }
    }

    public function assertCanDelete(string $resource, array $data, Request $request): void
    {
        $this->assertCanRead($resource, $data, $request);
        $role = $this->role($request);

        if ($role === 'patient' && in_array($resource, [
            'patient-notes',
            'support-contacts',
            'agenda-events',
            'notification-settings',
        ], true)) {
            return;
        }

        if ($role === 'patient' && $resource === 'supervision-requests' && ($data['status'] ?? null) === 'pending') {
            return;
        }

        if ($role === 'supervisor' && in_array($resource, ['agenda-events', 'interventions'], true)) {
            return;
        }

        abort(403, 'No tienes permiso para eliminar este recurso.');
    }

    public function sanitize(string $resource, array $data, Request $request): array
    {
        if ($this->role($request) === 'supervisor') {
            $data = $this->sanitizeForSupervisor($resource, $data, $this->uid($request));
        }

        if ($resource === 'supervision-requests') {
            $data['type'] = $data['type'] ?? $data['request_type'] ?? 'link';
        }

        if ($resource === 'consents') {
            $data = $this->normalizeConsentForOutput($data);
        }

        if ($resource === 'supervision-requests' && $this->role($request) === 'supervisor') {
            $patientUid = (string) ($data['patient_uid'] ?? '');

            if ($patientUid !== '') {
                $data['patient_name'] = $data['patient_name'] ?? $this->patientDisplayName($patientUid);
            }

            unset($data['patient_email']);
        }

        if ($resource === 'supervisors'
            && $this->role($request) === 'patient'
            && ($data['uid'] ?? null) !== $this->uid($request)) {
            return $this->only($data, [
                'uid', 'full_name', 'photo_url', 'supervisor_type',
                'specialties', 'authorized', 'verified', 'status', 'supervisor_code',
            ]);
        }

        return $data;
    }

    public function sanitizeForSupervisor(string $resource, array $data, string $supervisorUid): array
    {
        if ($resource === 'patients') {
            $patientUid = (string) ($data['uid'] ?? '');
            $privateIdentity = PatientDisplayName::isPrivate($data);
            $reference = $this->patientReference($patientUid);
            $safe = $this->only($data, [
                'uid', 'nickname', 'age', 'gender', 'is_anonymous', 'privacy_mode',
                'sobriety_start_date', 'primary_risks', 'supervision_status',
            ]);
            $safe['patient_uid'] = $patientUid;
            $safe['display_name'] = PatientDisplayName::forSupervisor($data, $reference);
            $safe['safe_display_name'] = $safe['display_name'];
            $safe['full_name'] = $privateIdentity ? null : ($data['full_name'] ?? null);
            $grantedScopes = $this->grantedScopes($supervisorUid, $patientUid);
            $safe['permissions'] = [];

            foreach (self::CONSENT_SCOPES as $scope) {
                $safe['permissions'][$scope] = in_array($scope, $grantedScopes, true);
            }

            if ($safe['permissions']['patient_phone']) {
                $safe['phone'] = $data['phone'] ?? null;
            }

            return $safe;
        }

        $allowed = match ($resource) {
            'patient-notes' => [
                'note_id', 'patient_uid', 'mood', 'mood_score', 'anxiety_level',
                'craving_level', 'energy_level', 'sleep_quality', 'had_relapse',
                'triggers', 'coping_actions', 'note_text', 'ai_risk_score',
                'ai_risk_level', 'created_at', 'updated_at',
            ],
            'support-contacts' => [
                'contact_id', 'patient_uid', 'name', 'relationship', 'phone',
                'priority', 'can_receive_alerts', 'notes', 'created_at', 'updated_at',
            ],
            'agenda-events' => [
                'event_id', 'patient_uid', 'type', 'title', 'starts_at', 'ends_at',
                'location', 'status', 'notes', 'created_at', 'updated_at',
            ],
            'patient-achievements' => [
                'patient_achievement_id', 'patient_uid', 'achievement_id',
                'progress', 'status', 'earned_at', 'created_at', 'updated_at',
            ],
            'ai-chat-sessions' => [
                'session_id', 'channel', 'mode', 'purpose', 'patient_refs',
                'authorized_patients_count', 'clinical_diagnosis_allowed',
                'prescription_allowed', 'therapy_allowed', 'status',
                'created_at', 'updated_at',
            ],
            'ai-chat-messages' => [
                'message_id', 'session_id', 'sender_type',
                'created_at', 'updated_at',
            ],
            default => null,
        };

        return $allowed === null ? $data : $this->only($data, $allowed);
    }

    public function assertAuthorizedSupervisor(?string $uid = null): void
    {
        $uid ??= '';
        $snapshot = $this->db->collection('supervisors')->document($uid)->snapshot();
        $data = $snapshot->exists() ? $snapshot->data() : [];

        if (! $snapshot->exists() || ! $this->isAuthorizedSupervisorProfile($data)) {
            abort(403, 'Tu cuenta de supervisor aún no ha sido autorizada por administración.');
        }
    }

    public function assertSupervisorPatientAccess(string $supervisorUid, string $patientUid, array $scopes = []): void
    {
        if (! $this->supervisorCanAccessPatient($supervisorUid, $patientUid, $scopes)) {
            abort(403, 'El paciente no pertenece al supervisor o no existe consentimiento vigente.');
        }
    }

    public function supervisorCanAccessPatient(string $supervisorUid, string $patientUid, array $scopes = []): bool
    {
        if ($patientUid === '') {
            return false;
        }

        $patient = $this->db->collection('patients')->document($patientUid)->snapshot();

        if (! $patient->exists()) {
            return false;
        }

        $patientData = $patient->data();

        if (($patientData['supervisor_uid'] ?? null) !== $supervisorUid
            || ($patientData['wants_supervision'] ?? false) !== true
            || ($patientData['supervision_status'] ?? null) !== 'accepted') {
            return false;
        }

        $consent = $this->canonicalConsent($supervisorUid, $patientUid);

        if ($consent === null || ! $this->isActiveConsent($consent)) {
            return false;
        }

        $requestedScopes = $this->normalizeScopeNames($scopes);
        $grantedScopes = $this->consentScopes($consent);

        return $requestedScopes === [] || array_diff($requestedScopes, $grantedScopes) === [];
    }

    private function grantedScopes(string $supervisorUid, string $patientUid): array
    {
        $consent = $this->canonicalConsent($supervisorUid, $patientUid);

        return $consent !== null && $this->isActiveConsent($consent)
            ? $this->consentScopes($consent)
            : [];
    }

    public function consentPermissions(string $supervisorUid, string $patientUid): array
    {
        $granted = $this->grantedScopes($supervisorUid, $patientUid);
        $permissions = [];

        foreach (self::CONSENT_SCOPES as $scope) {
            $permissions[$scope] = in_array($scope, $granted, true);
        }

        return $permissions;
    }

    public function normalizeConsentForOutput(array $consent): array
    {
        $active = $this->isActiveConsent($consent);
        $scopes = $this->consentScopes($consent);
        $permissions = [];

        foreach (self::CONSENT_SCOPES as $scope) {
            $permissions[$scope] = $active && in_array($scope, $scopes, true);
        }

        $consent['status'] = $this->consentStatus($consent);
        $consent['scope'] = $scopes;
        $consent['permissions'] = $permissions;

        return $consent;
    }

    private function canonicalConsent(string $supervisorUid, string $patientUid): ?array
    {
        $canonical = null;
        $canonicalTimestamp = PHP_INT_MIN;
        $documents = $this->db->collection('consents')
            ->where('patient_uid', '=', $patientUid)
            ->documents();

        foreach ($documents as $document) {
            if (! $document->exists()) {
                continue;
            }

            $consent = $document->data();

            if (($consent['supervisor_uid'] ?? null) !== $supervisorUid
                || ! empty($consent['deleted_at'])) {
                continue;
            }

            $timestamp = $this->consentTimestamp($consent);

            if ($canonical === null || $timestamp > $canonicalTimestamp) {
                $canonical = $consent;
                $canonicalTimestamp = $timestamp;
            }
        }

        return $canonical;
    }

    private function isActiveConsent(array $consent): bool
    {
        return ($consent['explicit_consent'] ?? false) === true
            && $this->consentStatus($consent) === 'active'
            && empty($consent['revoked_at'])
            && empty($consent['paused_at']);
    }

    private function consentStatus(array $consent): string
    {
        if (! empty($consent['revoked_at'])
            || ($consent['status'] ?? null) === 'revoked') {
            return 'revoked';
        }

        if (! empty($consent['paused_at']) || ($consent['status'] ?? null) === 'paused') {
            return 'paused';
        }

        if (($consent['explicit_consent'] ?? null) === false) {
            return 'revoked';
        }

        return ($consent['explicit_consent'] ?? false) === true
            && in_array($consent['status'] ?? 'active', ['active', null], true)
                ? 'active'
                : (string) ($consent['status'] ?? 'revoked');
    }

    private function consentScopes(array $consent): array
    {
        $scopes = $consent['scope'] ?? $consent['scopes'] ?? [];

        if (! is_array($scopes)) {
            $scopes = [];
        }

        if (is_array($consent['permissions'] ?? null)) {
            foreach ($consent['permissions'] as $scope => $granted) {
                if ($granted === true && is_string($scope)) {
                    $scopes[] = $scope;
                }
            }
        }

        return array_values(array_unique($this->normalizeScopeNames($scopes)));
    }

    private function normalizeScopeNames(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            if (! is_string($scope) || trim($scope) === '') {
                continue;
            }

            $scope = trim($scope);
            $normalized[] = self::CONSENT_SCOPE_ALIASES[$scope] ?? $scope;
        }

        return array_values(array_unique($normalized));
    }

    private function consentTimestamp(array $consent): int
    {
        foreach (['updated_at', 'accepted_at', 'created_at'] as $field) {
            $value = $consent[$field] ?? null;

            if ($value instanceof Timestamp) {
                return $value->get()->getTimestamp();
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->getTimestamp();
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            if (is_string($value) && ($timestamp = strtotime($value)) !== false) {
                return $timestamp;
            }
        }

        return 0;
    }

    private function assignedSupervisorUid(string $patientUid): ?string
    {
        $snapshot = $this->db->collection('patients')->document($patientUid)->snapshot();
        $data = $snapshot->exists() ? $snapshot->data() : [];

        return ($data['supervision_status'] ?? null) === 'accepted'
            ? ($data['supervisor_uid'] ?? null)
            : null;
    }

    public function resolveSupervisorUid(string $uidOrCode): ?string
    {
        $value = trim($uidOrCode);

        if ($value === '') {
            return null;
        }

        $snapshot = $this->db->collection('supervisors')->document($value)->snapshot();

        if ($snapshot->exists()) {
            return $value;
        }

        $normalizedCode = strtoupper($value);
        $documents = $this->db->collection('supervisors')
            ->where('supervisor_code', '=', $normalizedCode)
            ->limit(1)
            ->documents();

        foreach ($documents as $document) {
            if ($document->exists()) {
                return (string) (($document->data()['uid'] ?? null) ?: $document->id());
            }
        }

        $allSupervisors = $this->db->collection('supervisors')->documents();

        foreach ($allSupervisors as $document) {
            if (! $document->exists()) {
                continue;
            }

            $uid = (string) (($document->data()['uid'] ?? null) ?: $document->id());

            if ($this->supervisorCode($uid) === $normalizedCode) {
                return $uid;
            }
        }

        return null;
    }

    private function availableSupervisorUid(string $uidOrCode): string
    {
        $uid = $this->resolveSupervisorUid($uidOrCode);

        if ($uid === null) {
            abort(422, 'Debes indicar un codigo de supervisor valido.');
        }

        $snapshot = $this->db->collection('supervisors')->document($uid)->snapshot();
        $data = $snapshot->exists() ? $snapshot->data() : [];

        if (! $snapshot->exists() || ! $this->isAuthorizedSupervisorProfile($data)) {
            abort(422, 'El supervisor seleccionado no esta autorizado o no esta disponible.');
        }

        return $uid;
    }

    private function patientDisplayName(string $patientUid): string
    {
        $snapshot = $this->db->collection('patients')->document($patientUid)->snapshot();
        $data = $snapshot->exists() ? $snapshot->data() : [];

        return PatientDisplayName::forSupervisor($data, $this->patientReference($patientUid));
    }

    private function patientEmail(string $patientUid): ?string
    {
        $snapshot = $this->db->collection('patients')->document($patientUid)->snapshot();
        $data = $snapshot->exists() ? $snapshot->data() : [];

        return $data['email'] ?? null;
    }

    private function supervisorCode(string $uid): string
    {
        return 'RA-'.strtoupper(substr(sha1($uid), 0, 8));
    }

    private function patientReference(string $uid): string
    {
        return 'Paciente '.strtoupper(substr(sha1($uid), 0, 6));
    }

    private function isAuthorizedSupervisorProfile(array $profile): bool
    {
        return ($profile['status'] ?? null) === 'active'
            && ($profile['authorized'] ?? false) === true
            && ($profile['verified'] ?? false) === true;
    }

    private function documentData(string $collection, string $id): array
    {
        if ($id === '') {
            abort(422, 'Falta el identificador relacionado.');
        }

        $snapshot = $this->db->collection($collection)->document($id)->snapshot();

        if (! $snapshot->exists()) {
            abort(404, 'Documento relacionado no encontrado.');
        }

        return $snapshot->data();
    }

    private function requiredPatientUid(array $data): string
    {
        $patientUid = (string) ($data['patient_uid'] ?? '');

        if ($patientUid === '') {
            abort(422, 'Debes indicar patient_uid.');
        }

        return $patientUid;
    }

    private function assertRole(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            abort(403, 'Esta accion no esta permitida para tu rol.');
        }
    }

    private function assertOwnerValue(array $data, string $field, string $uid): void
    {
        if (isset($data[$field]) && $data[$field] !== $uid) {
            abort(403, "No puedes asignar {$field} a otra cuenta.");
        }
    }

    private function only(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }

    private function cancellationChanges(): array
    {
        return [
            'status' => 'cancelled',
            'cancelled_at' => Carbon::now()->toIso8601String(),
        ];
    }
}
