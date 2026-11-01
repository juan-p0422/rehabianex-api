<?php

namespace App\Services;

use App\Contracts\FcmTokenRepository;
use Carbon\CarbonInterface;
use Google\Cloud\Core\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class DataControlService
{
    private const BLOCKED_DELETION_STATUSES = [
        'pending_manual_review',
        'processing',
        'completed',
    ];

    private const PATIENT_EXPORTS = [
        'patient_notes' => [
            'note_id', 'mood', 'mood_score', 'anxiety_level', 'craving_level',
            'energy_level', 'sleep_quality', 'had_relapse', 'triggers',
            'coping_actions', 'note_text', 'ai_risk_score', 'ai_risk_level',
            'client_mutation_id', 'created_at', 'updated_at', 'deleted_at',
        ],
        'agenda_events' => [
            'event_id', 'type', 'title', 'starts_at', 'ends_at', 'location',
            'status', 'notes', 'attendance_reported', 'attendance_reported_at',
            'created_at', 'updated_at', 'deleted_at',
        ],
        'support_contacts' => [
            'contact_id', 'name', 'relationship', 'relationship_category',
            'relationship_other', 'phone', 'priority', 'can_receive_alerts',
            'notes', 'created_at', 'updated_at', 'deleted_at',
        ],
        'notification_settings' => [
            'settings_id', 'daily_check_in_enabled', 'daily_check_in_time',
            'daily_note_enabled', 'daily_note_time', 'sober_day_enabled',
            'sober_day_time', 'achievement_enabled', 'achievement_time',
            'event_reminders_enabled', 'motivational_enabled', 'motivational_time',
            'craving_alerts_enabled', 'supervisor_alerts_enabled',
            'support_contact_alerts_enabled', 'timezone', 'created_at', 'updated_at',
            'adaptive_suggestions_enabled', 'inactivity_reminder_enabled',
            'inactivity_days', 'inactivity_reminder_time',
            'adaptive_craving_threshold', 'adaptive_anxiety_threshold',
        ],
        'patient_achievements' => [
            'patient_achievement_id', 'achievement_id', 'progress', 'status',
            'unlocked_at', 'created_at', 'updated_at',
        ],
        'consents' => [
            'consent_id', 'type', 'explicit_consent', 'consent_text', 'scope',
            'status', 'accepted_at', 'paused_at', 'revoked_at', 'created_at', 'updated_at',
        ],
        'supervision_requests' => [
            'request_id', 'type', 'request_type', 'status', 'message',
            'requested_at', 'responded_at', 'cancelled_at', 'created_at', 'updated_at',
        ],
        'interventions' => [
            'intervention_id', 'trigger_note_id', 'reason', 'actions', 'status',
            'notes', 'created_at', 'updated_at', 'deleted_at',
        ],
    ];

    private $db;

    private $auth;

    public function __construct(
        FirebaseService $firebase,
        private FcmTokenRepository $fcmTokens,
    ) {
        $this->db = $firebase->db();
        $this->auth = $firebase->auth();
    }

    public function summary(Request $request): array
    {
        $role = (string) $request->attributes->get('firebase_role');
        $profile = (array) $request->attributes->get('firebase_profile', []);
        $rectification = $this->rectification($role);
        $automatedDeletion = in_array($role, ['patient', 'supervisor'], true);
        $selfServiceExport = (bool) config('data_control.self_service_export_enabled', false);
        $manualAttention = (array) config('data_control.manual_attention', []);
        $permanentDeletion = (array) config('data_control.permanent_deletion_request', []);
        $deactivationConfirmation = (string) config('data_control.deactivation_confirmation');

        return [
            'role' => $role,
            'account_role' => $role,
            'deletion_status' => $profile['data_deletion_status'] ?? 'none',
            'self_service_export_available' => $selfServiceExport,
            'categories' => $this->summaryCategories($role),
            'inventory' => $this->inventory($role),
            'editable_fields' => $rectification['profile']['editable_fields'],
            'rectification_route' => $rectification['profile']['route'],
            'rectification' => $rectification,
            'account_deactivation' => [
                'automated' => $automatedDeletion,
                'requires_recent_auth' => true,
                'recent_auth_max_age_seconds' => max(60, (int) config('data_control.recent_auth_seconds', 300)),
                'confirmation_phrase' => $deactivationConfirmation,
                'result' => 'account_disabled_and_soft_deletion_requested',
                'physical_purge_automatic' => false,
            ],
            // Alias de lectura para clientes que todavía consumen el nombre anterior.
            'account_deletion' => [
                'automated' => $automatedDeletion,
                'requires_recent_auth' => true,
                'recent_auth_max_age_seconds' => max(60, (int) config('data_control.recent_auth_seconds', 300)),
                'confirmation_phrase' => $deactivationConfirmation,
                'physical_purge_automatic' => false,
            ],
            'actions' => [
                'delete_records' => $this->recordDeletionActions($role),
                'deactivate_and_request_deletion' => [
                    'available' => $automatedDeletion,
                    'method' => 'POST',
                    'route' => '/api/data-control/account-deactivation-requests',
                    'requires_recent_authentication' => true,
                    'requires_online_confirmation' => true,
                    'confirmation_phrase' => $deactivationConfirmation,
                    'physical_purge_automatic' => false,
                ],
                'request_permanent_deletion' => [
                    'available' => true,
                    'method' => 'external_contact',
                    'channel' => (string) ($permanentDeletion['channel'] ?? 'email'),
                    'address' => (string) ($permanentDeletion['email'] ?? ''),
                    'subject' => (string) ($permanentDeletion['subject'] ?? ''),
                    'automatic_submission' => false,
                    'opening_mail_client_means_sent' => false,
                    'identity_verification_required' => true,
                ],
                'manual_attention' => [
                    'required_for' => $role === 'admin'
                        ? ['account_deletion', 'retention_questions', 'legacy_data']
                        : ['retention_questions', 'legacy_data', 'failed_partial_operations'],
                    'contact_channel' => (string) ($manualAttention['channel'] ?? 'phone'),
                    'contact_value' => (string) ($manualAttention['phone'] ?? ''),
                    'responsible_location' => (string) ($manualAttention['location'] ?? ''),
                ],
            ],
            'compatibility' => [
                'legacy_export_route' => [
                    'method' => 'GET',
                    'route' => '/api/data-control/export',
                    'status' => 410,
                    'code' => 'self_service_export_retired',
                ],
                'legacy_deactivation_route' => [
                    'method' => 'POST',
                    'route' => '/api/data-control/account-deletion-requests',
                ],
            ],
            'retention_notice' => (string) config('data_control.retention_notice'),
            'manual_request_notice' => 'La eliminación definitiva se solicita por el correo indicado. El acceso a datos, la rectificación no disponible, los datos legacy y las dudas de retención conservan el canal telefónico definido.',
        ];
    }

    public function export(Request $request): array
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');
        $profile = (array) $request->attributes->get('firebase_profile', []);
        $user = $request->attributes->get('firebase_user');

        $records = [];

        if ($role === 'patient') {
            foreach (self::PATIENT_EXPORTS as $collection => $fields) {
                $records[$collection] = $this->ownedDocuments(
                    $collection,
                    'patient_uid',
                    $uid,
                    $fields
                );
            }
        }

        return $this->normalize([
            'schema_version' => '1.0',
            'generated_at' => now('America/Mexico_City')->toIso8601String(),
            'format' => 'JSON UTF-8',
            'scope' => 'authenticated_account_holder',
            'sensitivity_notice' => 'Este archivo contiene información personal y puede incluir datos sensibles. Guárdalo y compártelo únicamente por canales de confianza.',
            'identity' => [
                'uid' => $uid,
                'role' => $role,
                'email' => $user->email ?? null,
                'email_verified' => (bool) ($user->emailVerified ?? false),
                'phone_number' => $user->phoneNumber ?? null,
                'display_name' => $user->displayName ?? null,
                'disabled' => (bool) ($user->disabled ?? false),
            ],
            'profile' => $this->exportProfile($profile, $role),
            'records' => $records,
            'excluded' => [
                'authentication_tokens_and_hashes',
                'fcm_device_tokens',
                'server_logs_and_security_ledgers',
                'other_users_profiles_and_identifiers',
                'shared_catalogs',
                'ai_questions_answers_and_provider_payloads_not_persisted',
            ],
            'retention_notice' => (string) config('data_control.retention_notice'),
        ]);
    }

    public function requestAccountDeletion(Request $request): array
    {
        return $this->requestAccountDeactivation($request);
    }

    public function requestAccountDeactivation(Request $request): array
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role');

        if (! in_array($role, ['patient', 'supervisor'], true)) {
            throw new HttpException(403, 'La eliminación automatizada no está disponible para este rol; requiere atención manual.');
        }

        $profileCollections = config('firestore.profile_collections', []);
        $profileCollection = $profileCollections[$role] ?? null;

        if (! is_string($profileCollection) || $profileCollection === '') {
            throw new HttpException(403, 'La cuenta no tiene un perfil compatible con este flujo.');
        }

        $requestId = 'deactivation_request_'.Str::uuid()->toString();
        $now = now('America/Mexico_City')->toIso8601String();
        $profileReference = $this->db->collection($profileCollection)->document($uid);
        $requestReference = $this->db->collection('account_deletion_requests')->document($requestId);
        $retentionNotice = (string) config('data_control.retention_notice');

        $result = $this->db->runTransaction(function ($transaction) use (
            $profileReference,
            $requestReference,
            $requestId,
            $uid,
            $role,
            $now,
            $retentionNotice,
        ): array {
            $profileSnapshot = $transaction->snapshot($profileReference);

            if (! $profileSnapshot->exists()) {
                throw new HttpException(403, 'La cuenta no tiene un perfil activo.');
            }

            $profile = $profileSnapshot->data();

            if (in_array($profile['data_deletion_status'] ?? null, self::BLOCKED_DELETION_STATUSES, true)) {
                return [
                    'created' => false,
                    'request_id' => (string) ($profile['data_deletion_request_id'] ?? ''),
                    'status' => (string) $profile['data_deletion_status'],
                    'requested_at' => $profile['deletion_requested_at'] ?? null,
                    'deactivated_at' => $profile['deactivated_at']
                        ?? $profile['deletion_requested_at']
                        ?? null,
                ];
            }

            $requestDocument = [
                'request_id' => $requestId,
                'firebase_uid' => $uid,
                'role' => $role,
                'scope' => 'account',
                'status' => 'pending_manual_review',
                'request_type' => 'account_deactivation_and_soft_deletion',
                'requested_at' => $now,
                'deactivated_at' => $now,
                'updated_at' => $now,
                'sync_blocked_at' => $now,
                'identity_lock_status' => 'pending',
                'manual_attention_required' => true,
                'retention_notice' => $retentionNotice,
            ];

            $transaction->set($requestReference, $requestDocument);
            $profileChanges = [
                'data_deletion_status' => 'pending_manual_review',
                'data_deletion_request_id' => $requestId,
                'deletion_requested_at' => $now,
                'deactivated_at' => $now,
                'status_before_deactivation' => $profile['status'] ?? null,
                'status' => 'disabled',
                'sync_blocked_at' => $now,
                'updated_at' => $now,
            ];

            if ($role === 'patient') {
                $profileChanges['supervisor_uid'] = null;
                $profileChanges['wants_supervision'] = false;
                $profileChanges['supervision_status'] = 'inactive';
                $profileChanges['supervision_ended_at'] = $now;
            }

            $transaction->set($profileReference, $profileChanges, ['merge' => true]);

            return [
                'created' => true,
                'request_id' => $requestId,
                'status' => 'pending_manual_review',
                'requested_at' => $now,
                'deactivated_at' => $now,
            ];
        });

        if ($result['created'] === false) {
            return $this->deletionResponse($result, true, $retentionNotice);
        }

        $manualAttentionRequired = false;
        $cleanupStatus = [
            'remote_access' => 'blocked',
            'identity' => 'pending',
            'fcm' => 'pending',
            'relationships' => 'pending',
        ];

        try {
            $this->deactivateRelationships($uid, $role, $now);
            $cleanupStatus['relationships'] = 'completed';
        } catch (Throwable) {
            $cleanupStatus['relationships'] = 'manual_required';
            $manualAttentionRequired = true;
        }

        try {
            $this->fcmTokens->revokeToken($uid);
            $cleanupStatus['fcm'] = 'completed';
        } catch (Throwable) {
            $cleanupStatus['fcm'] = 'manual_required';
            $manualAttentionRequired = true;
        }

        try {
            $this->auth->revokeRefreshTokens($uid);
            $this->auth->updateUser($uid, ['disabled' => true]);
            $cleanupStatus['identity'] = 'completed';
        } catch (Throwable) {
            $cleanupStatus['identity'] = 'manual_required';
            $manualAttentionRequired = true;
        }

        try {
            $requestReference->set([
                'identity_lock_status' => $manualAttentionRequired ? 'manual_required' : 'completed',
                'manual_attention_required' => $manualAttentionRequired,
                'cleanup_status' => $cleanupStatus,
                'updated_at' => now('America/Mexico_City')->toIso8601String(),
            ], ['merge' => true]);
        } catch (Throwable) {
            $manualAttentionRequired = true;
        }

        return $this->deletionResponse(
            $result,
            $manualAttentionRequired,
            $retentionNotice,
            $cleanupStatus
        );
    }

    private function inventory(string $role): array
    {
        $firestore = $role === 'patient'
            ? array_keys(self::PATIENT_EXPORTS)
            : [];
        array_unshift($firestore, $role === 'admin' ? 'admins' : $role.'s');

        return [
            [
                'store' => 'android_local',
                'data' => ['encrypted_room', 'secure_session_preferences', 'workmanager_outbox', 'private_cache_files'],
                'authority' => 'android',
                'remote_deletion' => false,
            ],
            [
                'store' => 'firestore',
                'data' => $firestore,
                'authority' => 'backend',
                'remote_deletion' => 'record_soft_delete_or_manual_account_process',
            ],
            [
                'store' => 'firebase_authentication',
                'data' => ['identity', 'providers', 'email', 'phone', 'session_revocation_state'],
                'authority' => 'firebase',
                'remote_deletion' => 'manual_account_process',
            ],
            [
                'store' => 'operational_security',
                'data' => ['fcm_tokens', 'revoked_token_hashes', 'notification_dispatch_ledger', 'sanitized_logs', 'rate_limit_cache'],
                'authority' => 'backend_and_providers',
                'remote_deletion' => 'retention_policy_or_manual_review',
            ],
            [
                'store' => 'ai_provider',
                'data' => ['transient_minimized_request_when_ai_mode_is_used'],
                'authority' => 'external_provider',
                'remote_deletion' => 'provider_policy_not_controlled_by_this_api',
            ],
        ];
    }

    private function summaryCategories(string $role): array
    {
        $categories = [
            [
                'code' => 'identity',
                'label' => 'Identidad de la cuenta',
                'location' => 'firebase_authentication',
                'exported' => false,
                'deletion_mode' => 'manual_account_process',
            ],
            [
                'code' => 'profile',
                'label' => 'Perfil propio',
                'location' => 'firestore',
                'exported' => false,
                'deletion_mode' => 'manual_account_process',
            ],
        ];

        if ($role === 'patient') {
            $categories[] = [
                'code' => 'own_records',
                'label' => 'Registros propios',
                'location' => 'firestore_and_encrypted_room',
                'exported' => false,
                'deletion_mode' => 'record_soft_delete_or_manual_account_process',
            ];
        }

        $categories[] = [
            'code' => 'operational_security',
            'label' => 'Seguridad y operación',
            'location' => 'backend_and_providers',
            'exported' => false,
            'deletion_mode' => 'retention_policy_or_manual_review',
        ];

        return $categories;
    }

    private function rectification(string $role): array
    {
        $fields = match ($role) {
            'patient' => [
                'full_name', 'phone', 'gender', 'age', 'photo_url', 'nickname',
                'sobriety_start_date', 'is_anonymous', 'privacy_mode', 'primary_risks',
            ],
            'supervisor' => [
                'full_name', 'phone', 'photo_url', 'supervisor_type',
                'supervisor_type_other', 'license_number', 'specialties',
            ],
            default => [],
        };

        return [
            'profile' => [
                'method' => 'PATCH',
                'route' => $role === 'patient'
                    ? '/api/patients/{authenticated_uid}'
                    : ($role === 'supervisor' ? '/api/supervisors/{authenticated_uid}' : null),
                'editable_fields' => $fields,
                'conflict_policy' => 'server_authoritative_refresh_after_success',
                'offline_queue_allowed' => false,
            ],
            'email' => [
                'firebase_action' => 'verifyBeforeUpdateEmail',
                'sync_method' => 'POST',
                'sync_route' => '/api/auth/email/sync',
                'offline_queue_allowed' => false,
            ],
        ];
    }

    private function recordDeletionActions(string $role): array
    {
        if ($role === 'patient') {
            return [
                ['resource' => 'patient-notes', 'method' => 'DELETE', 'route' => '/api/patient-notes/{id}', 'mode' => 'soft_delete'],
                ['resource' => 'support-contacts', 'method' => 'DELETE', 'route' => '/api/support-contacts/{id}', 'mode' => 'soft_delete'],
                ['resource' => 'agenda-events', 'method' => 'DELETE', 'route' => '/api/agenda-events/{id}', 'mode' => 'soft_delete'],
                ['resource' => 'notification-settings', 'method' => 'DELETE', 'route' => '/api/notification-settings/{id}', 'mode' => 'soft_delete'],
                ['resource' => 'consents', 'method' => 'PATCH', 'route' => '/api/consents/{id}', 'mode' => 'revoke_not_delete'],
                ['resource' => 'supervision-requests', 'method' => 'PATCH', 'route' => '/api/supervision-requests/{id}', 'mode' => 'cancel_pending_only'],
            ];
        }

        if ($role === 'supervisor') {
            return [
                ['resource' => 'agenda-events', 'method' => 'DELETE', 'route' => '/api/agenda-events/{id}', 'mode' => 'soft_delete_authorized_record'],
                ['resource' => 'interventions', 'method' => 'DELETE', 'route' => '/api/interventions/{id}', 'mode' => 'soft_delete_authorized_record'],
            ];
        }

        return [];
    }

    private function deactivateRelationships(string $uid, string $role, string $now): void
    {
        if ($role === 'supervisor') {
            $patients = $this->db->collection('patients')
                ->where('supervisor_uid', '=', $uid)
                ->documents();

            foreach ($patients as $patient) {
                if (! $patient->exists()) {
                    continue;
                }

                $this->db->collection('patients')->document($patient->id())->set([
                    'supervisor_uid' => null,
                    'wants_supervision' => false,
                    'supervision_status' => 'inactive',
                    'supervision_ended_at' => $now,
                    'updated_at' => $now,
                ], ['merge' => true]);
            }
        }

        $ownerField = $role === 'patient' ? 'patient_uid' : 'supervisor_uid';
        $consents = $this->db->collection('consents')
            ->where($ownerField, '=', $uid)
            ->documents();

        foreach ($consents as $consent) {
            if (! $consent->exists()) {
                continue;
            }

            $this->db->collection('consents')->document($consent->id())->set([
                'explicit_consent' => false,
                'status' => 'revoked',
                'revoked_at' => $now,
                'paused_at' => null,
                'revocation_reason' => 'account_deactivation',
                'updated_at' => $now,
            ], ['merge' => true]);
        }

        $requests = $this->db->collection('supervision_requests')
            ->where($ownerField, '=', $uid)
            ->documents();

        foreach ($requests as $request) {
            if (! $request->exists() || ($request->data()['status'] ?? null) !== 'pending') {
                continue;
            }

            $this->db->collection('supervision_requests')->document($request->id())->set([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancellation_reason' => 'account_deactivation',
                'updated_at' => $now,
            ], ['merge' => true]);
        }
    }

    private function exportProfile(array $profile, string $role): array
    {
        $allowed = match ($role) {
            'patient' => [
                'uid', 'email', 'full_name', 'phone', 'gender', 'age', 'photo_url',
                'nickname', 'sobriety_start_date', 'is_anonymous', 'privacy_mode',
                'primary_risks', 'wants_supervision', 'supervision_status', 'provider',
                'legal_acceptance', 'created_at', 'updated_at',
            ],
            'supervisor' => [
                'uid', 'email', 'full_name', 'phone', 'photo_url', 'supervisor_type',
                'supervisor_type_other', 'license_number', 'specialties', 'authorized',
                'verified', 'status', 'provider', 'legal_acceptance', 'created_at', 'updated_at',
            ],
            'admin' => [
                'uid', 'email', 'full_name', 'status', 'provider', 'created_at', 'updated_at',
            ],
            default => [],
        };

        return array_intersect_key($profile, array_flip($allowed));
    }

    private function ownedDocuments(
        string $collection,
        string $ownerField,
        string $uid,
        array $allowedFields,
    ): array {
        $items = [];
        $documents = $this->db->collection($collection)
            ->where($ownerField, '=', $uid)
            ->documents();

        foreach ($documents as $document) {
            if (! $document->exists()) {
                continue;
            }

            $data = $document->data();

            if (($data[$ownerField] ?? null) !== $uid) {
                continue;
            }

            $item = array_intersect_key($data, array_flip($allowedFields));
            $item['document_id'] = $document->id();
            $items[] = $item;
        }

        usort($items, static fn (array $a, array $b): int => strcmp(
            (string) ($a['created_at'] ?? $a['updated_at'] ?? $a['document_id']),
            (string) ($b['created_at'] ?? $b['updated_at'] ?? $b['document_id'])
        ));

        return $items;
    }

    private function deletionResponse(
        array $result,
        bool $manualAttention,
        string $retentionNotice,
        array $cleanupStatus = [],
    ): array {
        $cleanupStatus += [
            'remote_access' => 'blocked',
            'identity' => $manualAttention ? 'manual_required' : 'completed',
            'fcm' => $manualAttention ? 'manual_required' : 'completed',
            'relationships' => $manualAttention ? 'manual_required' : 'completed',
        ];

        return [
            'request' => [
                'request_id' => $result['request_id'],
                'status' => $result['status'],
                'requested_at' => $result['requested_at'],
                'deactivated_at' => $result['deactivated_at'] ?? $result['requested_at'],
            ],
            'sync_blocked' => true,
            'local_cleanup_required' => true,
            'cleanup_status' => $cleanupStatus,
            'manual_attention_required' => $manualAttention,
            'retention_notice' => $retentionNotice,
        ];
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof Timestamp) {
            return $value->get()->format(DATE_ATOM);
        }

        if ($value instanceof CarbonInterface || $value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }
}
