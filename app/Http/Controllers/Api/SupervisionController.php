<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Services\FcmNotificationDispatcher;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SupervisionController extends Controller
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

    public function resolveSupervisor(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'code' => ['required', 'string', 'regex:/^RA-[A-Fa-f0-9]{8}$/'],
        ], [
            'code.required' => 'Debes indicar el codigo del supervisor.',
            'code.regex' => 'El codigo debe usar el formato RA-XXXXXXXX.',
        ]);

        if ($validator->fails()) {
            return ApiErrorResponse::make(
                'Los datos enviados no son validos.',
                422,
                $validator->errors()->toArray()
            );
        }

        $code = strtoupper((string) $validator->validated()['code']);
        $uid = $this->access->resolveSupervisorUid($code);

        if ($uid === null) {
            abort(404, 'Supervisor no disponible.');
        }

        $snapshot = $this->db->collection('supervisors')->document($uid)->snapshot();

        if (! $snapshot->exists()) {
            abort(404, 'Supervisor no disponible.');
        }

        try {
            $this->access->assertAuthorizedSupervisor($uid);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 403) {
                abort(404, 'Supervisor no disponible.');
            }

            throw $exception;
        }

        if (! $this->isSupervisorAvailable($snapshot->data())) {
            abort(404, 'Supervisor no disponible.');
        }

        return response()->json($this->resolvedSupervisorResponse($uid, $snapshot->data()));
    }

    public function respond(Request $request, string $id)
    {
        try {
            if ($this->access->role($request) !== 'supervisor') {
                abort(403, 'Solo un supervisor puede responder solicitudes.');
            }

            $supervisorUid = $this->access->uid($request);
            $this->access->assertAuthorizedSupervisor($supervisorUid);

            $validator = Validator::make($request->all(), [
                'status' => ['required', 'in:accepted,rejected'],
            ]);

            if ($validator->fails()) {
                abort(422, $validator->errors()->first());
            }

            $requestRef = $this->db->collection('supervision_requests')->document($id);
            $requestSnapshot = $requestRef->snapshot();

            if (! $requestSnapshot->exists()) {
                abort(404, 'Solicitud de supervision no encontrada.');
            }

            $supervisionRequest = $requestSnapshot->data();
            $type = (string) ($supervisionRequest['type']
                ?? $supervisionRequest['request_type']
                ?? 'link');

            if (! in_array($type, ['link', 'unlink'], true)) {
                abort(409, 'La solicitud tiene un tipo incompatible.');
            }
            $supervisionRequest['type'] = $type;

            if (($supervisionRequest['supervisor_uid'] ?? null) !== $supervisorUid) {
                abort(403, 'La solicitud no pertenece al supervisor autenticado.');
            }

            if (($supervisionRequest['status'] ?? null) !== 'pending') {
                abort(409, 'La solicitud ya fue respondida.');
            }

            $patientUid = (string) ($supervisionRequest['patient_uid'] ?? '');
            $patientRef = $this->db->collection('patients')->document($patientUid);
            $patientSnapshot = $patientRef->snapshot();

            if (! $patientSnapshot->exists()) {
                abort(404, 'Paciente no encontrado.');
            }

            $status = (string) $request->input('status');
            $now = Carbon::now()->toIso8601String();
            $supervisionRequest['status'] = $status;
            $supervisionRequest['responded_at'] = $now;
            $supervisionRequest['updated_at'] = $now;

            $patient = $patientSnapshot->data();

            $revokedConsents = [];

            if ($status === 'accepted' && $type === 'link') {
                if (! empty($patient['supervisor_uid'])
                    && ($patient['supervisor_uid'] ?? null) !== $supervisorUid
                    && ($patient['supervision_status'] ?? null) === 'accepted') {
                    abort(409, 'El paciente ya está vinculado con otro supervisor.');
                }

                $patient['wants_supervision'] = true;
                $patient['supervisor_uid'] = $supervisorUid;
                $patient['supervision_status'] = 'accepted';
                $patient['supervision_accepted_at'] = $now;
                $patient['supervision_ended_at'] = null;
                $patient['updated_at'] = $now;
            } elseif ($status === 'accepted' && $type === 'unlink') {
                $this->assertActiveRelationship($patient, $supervisorUid);
                $patient = $this->unlinkedPatient($patient, $now);
                $revokedConsents = $this->consentRevocations(
                    $patientUid,
                    $supervisorUid,
                    $now
                );
            }

            $this->db->runTransaction(function ($transaction) use (
                $requestRef,
                $patientRef,
                $supervisionRequest,
                $patient,
                $status,
                $type,
                $revokedConsents
            ): void {
                $transaction->set($requestRef, $supervisionRequest);

                if ($status === 'accepted') {
                    $transaction->set($patientRef, $patient);

                    if ($type === 'unlink') {
                        foreach ($revokedConsents as $consent) {
                            $transaction->set($consent['reference'], $consent['data']);
                        }
                    }
                }
            });

            $this->fcmNotifications->supervisionResponded($supervisionRequest);

            return response()->json([
                'ok' => true,
                'message' => match (true) {
                    $status === 'rejected' => 'Solicitud rechazada.',
                    $type === 'unlink' => 'Solicitud aceptada y relación de supervisión finalizada.',
                    default => 'Solicitud aceptada. El paciente debe otorgar consentimiento explícito antes de compartir información.',
                },
                'data' => $supervisionRequest,
                'relationship' => $status === 'accepted' && $type === 'unlink'
                    ? $this->unlinkSummary($supervisorUid, $patientUid, $now, count($revokedConsents))
                    : null,
            ]);
        } catch (Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            Log::error('Unable to respond supervision request.', [
                'exception' => get_class($e),
                'status' => $status,
            ]);

            return ApiErrorResponse::fromException($e);
        }
    }

    public function unlink(Request $request, string $supervisorUid, string $patientUid)
    {
        try {
            $actorRole = $this->access->role($request);
            $actorUid = $this->access->uid($request);

            if ($actorRole === 'supervisor') {
                if ($actorUid !== $supervisorUid) {
                    abort(403, 'Solo el supervisor vinculado puede finalizar esta relación.');
                }

                $this->access->assertAuthorizedSupervisor($supervisorUid);
            } elseif ($actorRole === 'patient') {
                if ($actorUid !== $patientUid) {
                    abort(403, 'Solo el paciente vinculado puede finalizar esta relación.');
                }
            } else {
                abort(403, 'Tu rol no puede finalizar relaciones de supervisión.');
            }

            $patientRef = $this->db->collection('patients')->document($patientUid);
            $patientSnapshot = $patientRef->snapshot();

            if (! $patientSnapshot->exists()) {
                abort(404, 'Paciente no encontrado.');
            }

            $patient = $patientSnapshot->data();
            $this->assertActiveRelationship($patient, $supervisorUid);
            $now = Carbon::now()->toIso8601String();
            $patient = $this->unlinkedPatient($patient, $now);
            $revokedConsents = $this->consentRevocations($patientUid, $supervisorUid, $now);

            $this->db->runTransaction(function ($transaction) use (
                $patientRef,
                $patient,
                $revokedConsents
            ): void {
                $transaction->set($patientRef, $patient);

                foreach ($revokedConsents as $consent) {
                    $transaction->set($consent['reference'], $consent['data']);
                }
            });

            return response()->json([
                'ok' => true,
                'message' => 'Relación de supervisión finalizada correctamente.',
                'relationship' => $this->unlinkSummary(
                    $supervisorUid,
                    $patientUid,
                    $now,
                    count($revokedConsents)
                ),
                'patient' => [
                    'uid' => $patientUid,
                    'supervisor_uid' => null,
                    'wants_supervision' => false,
                    'supervision_status' => 'not_requested',
                    'supervision_ended_at' => $now,
                ],
            ]);
        } catch (Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            Log::error('Unable to unlink supervision relationship.', [
                'exception' => get_class($e),
                'status' => $status,
            ]);

            return ApiErrorResponse::fromException($e);
        }
    }

    private function assertActiveRelationship(array $patient, string $supervisorUid): void
    {
        if (($patient['supervisor_uid'] ?? null) !== $supervisorUid
            || ($patient['wants_supervision'] ?? false) !== true
            || ($patient['supervision_status'] ?? null) !== 'accepted') {
            abort(409, 'No existe una relación activa entre el paciente y el supervisor.');
        }
    }

    private function unlinkedPatient(array $patient, string $now): array
    {
        $patient['supervisor_uid'] = null;
        $patient['wants_supervision'] = false;
        $patient['supervision_status'] = 'not_requested';
        $patient['supervision_ended_at'] = $now;
        $patient['updated_at'] = $now;

        return $patient;
    }

    private function consentRevocations(
        string $patientUid,
        string $supervisorUid,
        string $now
    ): array {
        $documents = $this->db->collection('consents')
            ->where('patient_uid', '=', $patientUid)
            ->documents();
        $revocations = [];

        foreach ($documents as $document) {
            if (! $document->exists()) {
                continue;
            }

            $consent = $document->data();

            if (($consent['supervisor_uid'] ?? null) !== $supervisorUid) {
                continue;
            }

            $consent['explicit_consent'] = false;
            $consent['status'] = 'revoked';
            $consent['revoked_at'] = $now;
            $consent['paused_at'] = null;
            $consent['revocation_reason'] = 'supervision_unlinked';
            $consent['updated_at'] = $now;
            $revocations[] = [
                'reference' => $this->db
                    ->collection('consents')
                    ->document($document->id()),
                'data' => $consent,
            ];
        }

        return $revocations;
    }

    private function unlinkSummary(
        string $supervisorUid,
        string $patientUid,
        string $endedAt,
        int $revokedConsents
    ): array {
        return [
            'supervisor_uid' => $supervisorUid,
            'patient_uid' => $patientUid,
            'status' => 'ended',
            'ended_at' => $endedAt,
            'revoked_consents' => $revokedConsents,
        ];
    }

    private function resolvedSupervisorResponse(string $uid, array $profile): array
    {
        return [
            'ok' => true,
            'uid' => $uid,
            'full_name' => (string) (($profile['full_name'] ?? null) ?: 'Supervisor RehabiAnex'),
            'supervisor_type' => $profile['supervisor_type'] ?? null,
            'available' => true,
        ];
    }

    private function isSupervisorAvailable(array $profile): bool
    {
        if (array_key_exists('available', $profile) && $profile['available'] !== true) {
            return false;
        }

        if (array_key_exists('accepting_patients', $profile)
            && $profile['accepting_patients'] !== true) {
            return false;
        }

        return true;
    }
}
