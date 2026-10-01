<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Models\Firestore\FirestoreResource;
use App\Services\FcmNotificationDispatcher;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use App\Support\PatientDisplayName;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class FirestoreCrudController extends Controller
{
    private $db;

    private FcmNotificationDispatcher $fcmNotifications;

    public function __construct(
        FirebaseService $firebase,
        private FirestoreAccessService $access,
        ?FcmNotificationDispatcher $fcmNotifications = null,
    ) {
        $this->db = $firebase->db();
        $this->fcmNotifications = $fcmNotifications ?? app(FcmNotificationDispatcher::class);
    }

    public function index(Request $request, string $resource)
    {
        try {
            $model = $this->model($resource);
            $includeDeleted = $this->includeDeleted($request);

            if ($request->query->has('updated_since')) {
                abort(422, 'La sincronizacion incremental por updated_since aun no esta habilitada; realiza un refresh completo.');
            }

            $query = $this->access->scopeIndex(
                $this->db->collection($model::collection()),
                $resource,
                $request
            );

            foreach ($model::filterable() as $field) {
                if ($request->query->has($field)) {
                    $query = $query->where($field, '=', $this->normalizeQueryValue($request->query($field)));
                }
            }

            $limit = min(max((int) $request->query('limit', 50), 1), 100);
            $documents = $query->limit($limit)->documents();
            $items = [];

            foreach ($documents as $document) {
                if (! $document->exists()) {
                    continue;
                }

                $data = $document->data();

                if (! empty($data['deleted_at']) && ! $includeDeleted) {
                    continue;
                }

                if ($resource === 'notification-settings') {
                    $data = $this->notificationSettingsOutput($data);
                }

                try {
                    $this->access->assertCanRead($resource, $data, $request);
                } catch (HttpExceptionInterface $e) {
                    if ($e->getStatusCode() === 403) {
                        continue;
                    }

                    throw $e;
                }

                $data = ! empty($data['deleted_at'])
                    ? $this->tombstone($model, $data)
                    : $this->access->sanitize($resource, $data, $request);
                $items[] = $this->withDocumentId($data, $document->id());
            }

            return response()->json([
                'ok' => true,
                'collection' => $model::collection(),
                'count' => count($items),
                'limit' => $limit,
                'data' => $items,
                'sync' => [
                    'mode' => 'bounded_refresh',
                    'cache_action' => 'upsert',
                    'include_deleted' => $includeDeleted,
                    'incremental_supported' => false,
                    'server_time' => Carbon::now()->toIso8601String(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function store(Request $request, string $resource)
    {
        try {
            $model = $this->model($resource);
            $now = Carbon::now()->toIso8601String();
            $payload = $this->normalizeCompatibilityPayload($resource, $this->payload($request), true);
            $data = $this->access->prepareCreate($resource, $payload, $request);
            $this->validatePayload($resource, $data, true);

            $data = $this->computedFields($resource, $data);

            if ($resource === 'notification-settings') {
                $existing = $this->existingNotificationSettings((string) $data['patient_uid']);

                if ($existing !== null) {
                    $documentId = $existing['id'];
                    $data = array_replace($existing['data'], $data);
                    unset($data['deleted_at'], $data['deleted_by']);
                    $data[$model::idField()] = $documentId;
                    $data = $this->computedFields($resource, $data);
                    $data['created_at'] = $existing['data']['created_at'] ?? $now;
                    $data['updated_at'] = $now;

                    $this->db->collection($model::collection())->document($documentId)->set($data);

                    return response()->json([
                        'ok' => true,
                        'message' => 'Configuracion de notificaciones actualizada correctamente.',
                        'collection' => $model::collection(),
                        'id' => $documentId,
                        'data' => $this->withDocumentId($data, $documentId),
                    ]);
                }
            }

            $documentId = $this->newDocumentId($resource, $data);
            $data[$model::idField()] = $documentId;
            $data = $this->computedFields($resource, $data);
            $data['created_at'] = $now;
            $data['updated_at'] = $now;

            $this->db->collection($model::collection())->document($documentId)->set($data);
            $this->fcmNotifications->created($resource, $data);
            $responseData = $resource === 'consents'
                ? $this->access->normalizeConsentForOutput($data)
                : $data;

            return response()->json([
                'ok' => true,
                'message' => 'Documento creado correctamente.',
                'collection' => $model::collection(),
                'id' => $documentId,
                'data' => $this->withDocumentId($responseData, $documentId),
            ], 201);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function show(Request $request, string $id, string $resource)
    {
        try {
            $model = $this->model($resource);
            $snapshot = $this->db->collection($model::collection())->document($id)->snapshot();

            if (! $snapshot->exists()) {
                return $this->notFound();
            }

            $data = $snapshot->data();

            if (! empty($data['deleted_at'])) {
                return $this->notFound();
            }

            if ($resource === 'notification-settings') {
                $data = $this->notificationSettingsOutput($data);
            }

            $this->access->assertCanRead($resource, $data, $request);
            $data = $this->access->sanitize($resource, $data, $request);

            if ($resource === 'patients'
                && $this->access->role($request) === 'patient'
                && $this->access->uid($request) === $id) {
                $data = $this->ownerPatientSnapshot($data, $id, $model::collection());
            }

            return response()->json([
                'ok' => true,
                'collection' => $model::collection(),
                'id' => $id,
                'data' => $this->withDocumentId($data, $id),
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function update(Request $request, string $id, string $resource)
    {
        try {
            $model = $this->model($resource);
            $document = $this->db->collection($model::collection())->document($id);
            $snapshot = $document->snapshot();

            if (! $snapshot->exists()) {
                return $this->notFound();
            }

            $current = $snapshot->data();

            if (! empty($current['deleted_at'])) {
                return $this->notFound();
            }
            $payload = $this->normalizeCompatibilityPayload($resource, $this->payload($request), false);
            $changes = $this->access->prepareUpdate($resource, $current, $payload, $request);

            if ($changes === []) {
                abort(422, 'No se enviaron campos modificables.');
            }

            $this->validatePayload($resource, $changes, false);

            $updated = array_replace($current, $changes);
            $updated = $this->computedFields($resource, $updated);
            $updated[$model::idField()] = $id;
            $updated['created_at'] = $current['created_at'] ?? Carbon::now()->toIso8601String();
            $updated['updated_at'] = Carbon::now()->toIso8601String();

            $document->set($updated);
            $this->fcmNotifications->updated($resource, $updated);

            if ($resource === 'consents'
                && ($current['status'] ?? null) === 'active'
                && in_array(($updated['status'] ?? null), ['paused', 'suspended'], true)) {
                try {
                    $this->fcmNotifications->sendConsentSuspended($updated);
                } catch (Throwable $notificationError) {
                    Log::warning('FCM consent suspension dispatch skipped.', [
                        'exception' => get_class($notificationError),
                    ]);
                }
            }

            return response()->json($this->updateResponse(
                $resource,
                $model::collection(),
                $id,
                $updated
            ));
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function destroy(Request $request, string $id, string $resource)
    {
        try {
            $model = $this->model($resource);
            $document = $this->db->collection($model::collection())->document($id);
            $snapshot = $document->snapshot();

            if (! $snapshot->exists()) {
                return $this->notFound();
            }

            $current = $snapshot->data();
            $this->access->assertCanDelete($resource, $current, $request);

            if (! empty($current['deleted_at'])) {
                return response()->json([
                    'ok' => true,
                    'message' => 'El documento ya estaba eliminado.',
                    'collection' => $model::collection(),
                    'id' => $id,
                    'deleted_at' => $current['deleted_at'],
                ]);
            }

            $now = Carbon::now()->toIso8601String();
            $changes = [
                'deleted_at' => $now,
                'deleted_by' => $this->access->uid($request),
                'updated_at' => $now,
            ];

            if ($resource === 'supervision-requests') {
                $changes = array_replace($changes, [
                    'status' => 'cancelled',
                    'cancelled_at' => $now,
                ]);
            }

            $updated = array_replace($current, $changes);
            $document->set($updated);
            $this->fcmNotifications->updated($resource, $updated);

            $response = [
                'ok' => true,
                'message' => $resource === 'supervision-requests'
                    ? 'Solicitud cancelada correctamente.'
                    : 'Documento eliminado correctamente.',
                'collection' => $model::collection(),
                'id' => $id,
                'deleted_at' => $now,
            ];

            $headers = [];

            if ($resource === 'supervision-requests') {
                $response['deprecated'] = true;
                $response['replacement'] = 'PATCH /supervision-requests/{id}';
                $response['request'] = $this->withDocumentId(array_replace($current, $changes), $id);
                $headers['Deprecation'] = 'true';
                $headers['Warning'] = '299 - "DELETE supervision-requests esta deprecado; usa PATCH status=cancelled"';
            }

            return response()->json($response, 200, $headers);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    private function model(string $resource): string
    {
        $models = config('firestore.resources', []);

        if (! isset($models[$resource]) || ! is_subclass_of($models[$resource], FirestoreResource::class)) {
            abort(404, 'Recurso Firestore no encontrado.');
        }

        return $models[$resource];
    }

    private function payload(Request $request): array
    {
        $data = $request->json()->all();
        $data = empty($data) ? $request->all() : $data;

        unset(
            $data['password'],
            $data['password_confirmation'],
            $data['plain_password'],
            $data['current_password'],
            $data['new_password'],
            $data['created_at'],
            $data['updated_at'],
            $data['document_id']
        );

        return $data;
    }

    private function validatePayload(string $resource, array $data, bool $creating): void
    {
        $rules = match ($resource) {
            'patients' => [
                'full_name' => ['sometimes', 'string', 'max:120'],
                'nickname' => ['sometimes', 'nullable', 'string', 'max:80'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
                'age' => ['sometimes', 'integer', 'min:1', 'max:120'],
                'gender' => ['sometimes', 'nullable', 'string', 'max:40'],
                'is_anonymous' => ['sometimes', 'boolean'],
                'privacy_mode' => ['sometimes', 'boolean'],
                'sobriety_start_date' => ['sometimes', 'nullable', 'date'],
                'primary_risks' => ['sometimes', 'array', 'max:20'],
                'primary_risks.*' => ['string', 'max:120'],
                'photo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            ],
            'supervisors' => [
                'full_name' => ['sometimes', 'string', 'max:120'],
                'specialties' => ['sometimes', 'array', 'max:20'],
            ],
            'patient-notes' => [
                'mood' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
                'mood_score' => ['sometimes', 'integer', 'between:0,10'],
                'anxiety_level' => ['sometimes', 'integer', 'between:0,10'],
                'craving_level' => ['sometimes', 'integer', 'between:0,10'],
                'energy_level' => ['sometimes', 'integer', 'between:0,10'],
                'sleep_quality' => ['sometimes', 'integer', 'between:0,10'],
                'had_relapse' => ['sometimes', 'boolean'],
                'triggers' => ['sometimes', 'array', 'max:20'],
                'coping_actions' => ['sometimes', 'array', 'max:20'],
                'note_text' => ['sometimes', 'string', 'max:5000'],
            ],
            'support-contacts' => [
                'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
                'phone' => [$creating ? 'required' : 'sometimes', 'string', 'max:30'],
                'relationship' => ['sometimes', 'string', 'max:80'],
                'priority' => ['sometimes', 'integer', 'between:1,10'],
                'can_receive_alerts' => ['sometimes', 'boolean'],
                'notes' => ['sometimes', 'string', 'max:1000'],
            ],
            'agenda-events' => [
                'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
                'type' => ['sometimes', 'string', 'max:80'],
                'starts_at' => [$creating ? 'required' : 'sometimes', 'date'],
                'ends_at' => ['sometimes', 'date', 'after:starts_at'],
                'status' => ['sometimes', 'in:scheduled,completed,cancelled'],
            ],
            'consents' => [
                'supervisor_uid' => [$creating ? 'required' : 'sometimes', 'string', 'max:128'],
                'explicit_consent' => [$creating ? 'required' : 'sometimes', 'boolean'],
                'consent_text' => [$creating ? 'required' : 'sometimes', 'string', 'max:3000'],
                'scope' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
                'scope.*' => ['string', 'distinct', 'in:patient_notes,agenda_events,support_contacts,ai_chat_summary,patient_achievements,patient_phone'],
                'status' => ['sometimes', 'in:active,paused,revoked'],
            ],
            'notification-settings' => [
                'daily_check_in_enabled' => ['sometimes', 'boolean'],
                'daily_check_in_time' => ['sometimes', 'date_format:H:i'],
                'daily_note_enabled' => ['sometimes', 'boolean'],
                'daily_note_time' => ['sometimes', 'date_format:H:i'],
                'sober_day_enabled' => ['sometimes', 'boolean'],
                'sober_day_time' => ['sometimes', 'date_format:H:i'],
                'achievement_enabled' => ['sometimes', 'boolean'],
                'achievement_time' => ['sometimes', 'date_format:H:i'],
                'event_reminders_enabled' => ['sometimes', 'boolean'],
                'motivational_enabled' => ['sometimes', 'boolean'],
                'motivational_time' => ['sometimes', 'date_format:H:i'],
                'craving_alerts_enabled' => ['sometimes', 'boolean'],
                'supervisor_alerts_enabled' => ['sometimes', 'boolean'],
                'support_contact_alerts_enabled' => ['sometimes', 'boolean'],
                'timezone' => ['sometimes', 'timezone'],
            ],
            'interventions' => [
                'patient_uid' => [$creating ? 'required' : 'sometimes', 'string', 'max:128'],
                'reason' => [$creating ? 'required' : 'sometimes', 'string', 'max:2000'],
                'actions' => ['sometimes', 'array', 'max:20'],
                'status' => ['sometimes', 'in:open,in_progress,completed,cancelled'],
            ],
            'supervision-requests' => [
                'supervisor_uid' => [$creating ? 'required' : 'sometimes', 'string', 'max:128'],
                'type' => ['sometimes', 'in:link,unlink'],
                'request_type' => ['sometimes', 'in:link,unlink'],
                'message' => ['sometimes', 'string', 'max:2000'],
                'status' => ['sometimes', 'in:pending,accepted,rejected,cancelled'],
            ],
            default => [],
        };

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function computedFields(string $resource, array $data): array
    {
        if ($resource === 'notification-settings') {
            return $this->notificationSettingsOutput($data);
        }

        if ($resource !== 'patient-notes') {
            return $data;
        }

        $anxiety = (int) ($data['anxiety_level'] ?? 0);
        $craving = (int) ($data['craving_level'] ?? 0);
        $mood = (int) ($data['mood_score'] ?? 5);
        $risk = min(($anxiety * 4) + ($craving * 5) + ((10 - $mood) * 3) + (($data['had_relapse'] ?? false) ? 30 : 0), 100);

        $data['ai_risk_score'] = $risk;
        $data['ai_risk_level'] = match (true) {
            $risk >= 85 => 'critical',
            $risk >= 65 => 'high',
            $risk >= 40 => 'medium',
            default => 'low',
        };

        return $data;
    }

    private function newDocumentId(string $resource, array $data = []): string
    {
        if ($resource === 'notification-settings' && ! empty($data['patient_uid'])) {
            return (string) $data['patient_uid'];
        }

        return Str::singular(str_replace('-', '_', $resource)).'_'.Str::uuid()->toString();
    }

    private function normalizeQueryValue(mixed $value): mixed
    {
        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    private function includeDeleted(Request $request): bool
    {
        if (! $request->query->has('include_deleted')) {
            return false;
        }

        $value = $request->query('include_deleted');

        if (! in_array($value, ['true', 'false', '1', '0', 1, 0, true, false], true)) {
            abort(422, 'include_deleted debe ser true o false.');
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function tombstone(string $model, array $data): array
    {
        $allowed = [
            $model::idField(),
            'patient_uid',
            'supervisor_uid',
            'status',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    private function withDocumentId(array $data, string $documentId): array
    {
        $data['document_id'] = $documentId;

        return $data;
    }

    private function normalizeCompatibilityPayload(string $resource, array $data, bool $creating): array
    {
        if ($resource !== 'notification-settings') {
            return $data;
        }

        if (isset($data['user_uid'], $data['patient_uid'])
            && (string) $data['user_uid'] !== (string) $data['patient_uid']) {
            abort(422, 'user_uid y patient_uid deben identificar al mismo paciente.');
        }

        if (! isset($data['patient_uid']) && isset($data['user_uid'])) {
            $data['patient_uid'] = $data['user_uid'];
        }

        if (! array_key_exists('daily_check_in_enabled', $data)
            && array_key_exists('daily_note_enabled', $data)) {
            $data['daily_check_in_enabled'] = $data['daily_note_enabled'];
        }

        if (! array_key_exists('daily_note_enabled', $data)
            && array_key_exists('daily_check_in_enabled', $data)) {
            $data['daily_note_enabled'] = $data['daily_check_in_enabled'];
        }

        if (! array_key_exists('daily_check_in_time', $data)
            && array_key_exists('daily_note_time', $data)) {
            $data['daily_check_in_time'] = $data['daily_note_time'];
        }

        if (! array_key_exists('daily_note_time', $data)
            && array_key_exists('daily_check_in_time', $data)) {
            $data['daily_note_time'] = $data['daily_check_in_time'];
        }

        if ($creating && empty($data['timezone'])) {
            $data['timezone'] = 'America/Mexico_City';
        }

        unset($data['settings_id'], $data['notification_setting_id']);

        return $data;
    }

    private function notificationSettingsOutput(array $data): array
    {
        $settingsId = $data['settings_id'] ?? $data['notification_setting_id'] ?? null;
        $patientUid = $data['patient_uid'] ?? $data['user_uid'] ?? null;
        $dailyEnabled = (bool) ($data['daily_check_in_enabled'] ?? $data['daily_note_enabled'] ?? false);
        $dailyTime = (string) ($data['daily_check_in_time'] ?? $data['daily_note_time'] ?? '21:00');

        return array_replace([
            'settings_id' => $settingsId,
            'notification_setting_id' => $settingsId,
            'user_uid' => $patientUid,
            'patient_uid' => $patientUid,
            'daily_check_in_enabled' => $dailyEnabled,
            'daily_check_in_time' => $dailyTime,
            'daily_note_enabled' => $dailyEnabled,
            'daily_note_time' => $dailyTime,
            'sober_day_enabled' => false,
            'sober_day_time' => '08:00',
            'achievement_enabled' => false,
            'achievement_time' => '09:00',
            'event_reminders_enabled' => false,
            'motivational_enabled' => false,
            'motivational_time' => '20:00',
            'craving_alerts_enabled' => false,
            'supervisor_alerts_enabled' => false,
            'support_contact_alerts_enabled' => false,
            'timezone' => 'America/Mexico_City',
        ], $data, [
            'settings_id' => $settingsId,
            'notification_setting_id' => $settingsId,
            'user_uid' => $patientUid,
            'patient_uid' => $patientUid,
            'daily_check_in_enabled' => $dailyEnabled,
            'daily_check_in_time' => $dailyTime,
            'daily_note_enabled' => $dailyEnabled,
            'daily_note_time' => $dailyTime,
            'timezone' => $data['timezone'] ?? 'America/Mexico_City',
        ]);
    }

    private function existingNotificationSettings(string $patientUid): ?array
    {
        $documents = $this->db->collection('notification_settings')
            ->where('patient_uid', '=', $patientUid)
            ->limit(1)
            ->documents();

        foreach ($documents as $document) {
            if ($document->exists()) {
                return [
                    'id' => $document->id(),
                    'data' => $document->data(),
                ];
            }
        }

        return null;
    }

    private function notFound()
    {
        return ApiErrorResponse::make('Documento no encontrado.', 404);
    }

    private function error(Throwable $e)
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        Log::error('Firestore API request failed.', [
            'exception' => get_class($e),
            'status' => $status,
        ]);

        return ApiErrorResponse::fromException($e);
    }

    private function updateResponse(string $resource, string $collection, string $id, array $updated): array
    {
        if ($resource === 'patients') {
            $updated = $this->ownerPatientSnapshot($updated, $id, $collection);
        }

        if ($resource === 'consents') {
            $updated = $this->access->normalizeConsentForOutput($updated);
        }

        $data = $this->withDocumentId($updated, $id);
        $response = [
            'ok' => true,
            'message' => 'Documento actualizado correctamente.',
            'collection' => $collection,
            'id' => $id,
            'data' => $data,
        ];

        if ($resource === 'supervision-requests'
            && ($updated['status'] ?? null) === 'cancelled') {
            $response['message'] = 'Solicitud cancelada correctamente.';
            $response['request'] = $data;
        }

        if ($resource === 'patients') {
            $response['message'] = 'Paciente actualizado correctamente.';
            $response['patient'] = $data;
        }

        return $response;
    }

    private function ownerPatientSnapshot(array $profile, string $uid, string $collection): array
    {
        $snapshot = array_replace([
            'uid' => $uid,
            'full_name' => null,
            'display_name' => null,
            'safe_display_name' => null,
            'nickname' => null,
            'privacy_mode' => false,
            'is_anonymous' => false,
            'sobriety_start_date' => null,
            'photo_url' => null,
            'primary_risks' => [],
            'created_at' => null,
            'updated_at' => $profile['created_at'] ?? null,
        ], $profile);

        $snapshot['uid'] = $uid;
        $snapshot['collection'] = $collection;
        $snapshot['document_id'] = $uid;
        $snapshot['privacy_mode'] = (bool) ($snapshot['privacy_mode'] ?? false);
        $snapshot['is_anonymous'] = (bool) ($snapshot['is_anonymous'] ?? false);
        $snapshot['display_name'] = PatientDisplayName::forOwner($snapshot);
        $snapshot['safe_display_name'] = $snapshot['display_name'];
        $snapshot['primary_risks'] = is_array($snapshot['primary_risks'] ?? null)
            ? array_values($snapshot['primary_risks'])
            : [];
        $snapshot['updated_at'] = $profile['updated_at'] ?? $profile['created_at'] ?? null;

        return $snapshot;
    }
}
