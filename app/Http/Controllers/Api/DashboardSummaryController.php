<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;

class DashboardSummaryController extends Controller
{
    private $db;

    public function __construct(
        FirebaseService $firebase,
        private FirestoreAccessService $access
    ) {
        $this->db = $firebase->db();
    }

    public function supervisor(Request $request, string $uid)
    {
        if ($this->access->role($request) !== 'supervisor'
            || $this->access->uid($request) !== $uid) {
            abort(403, 'Solo puedes consultar el resumen de tu propia sesión de supervisor.');
        }

        $this->access->assertAuthorizedSupervisor($uid);
        $profile = $request->attributes->get('firebase_profile');
        $profile = is_array($profile) ? $profile : [];
        $authorizedPatientUids = $this->authorizedPatientUids($uid);

        return response()->json([
            'ok' => true,
            'supervisor_uid' => $uid,
            'profile' => $this->supervisorProfile($profile),
            'summary' => [
                'patients_count' => count($authorizedPatientUids),
                'pending_requests_count' => $this->pendingRequestsCount($uid),
                'open_interventions_count' => $this->openInterventionsCount(
                    $uid,
                    $authorizedPatientUids
                ),
            ],
        ]);
    }

    public function admin(Request $request)
    {
        $counts = [
            'pending_supervisors' => 0,
            'active_supervisors' => 0,
            'suspended_supervisors' => 0,
        ];

        foreach ($this->db->collection('supervisors')
            ->select(['status'])
            ->documents() as $document) {
            if (! $document->exists()) {
                continue;
            }

            $status = (string) ($document->data()['status'] ?? 'pending_review');
            $status = $status === 'pending' ? 'pending_review' : $status;

            if ($status === 'pending_review') {
                $counts['pending_supervisors']++;
            } elseif ($status === 'active') {
                $counts['active_supervisors']++;
            } elseif ($status === 'suspended') {
                $counts['suspended_supervisors']++;
            }
        }

        return response()->json([
            'ok' => true,
            'admin_uid' => (string) $request->attributes->get('firebase_uid'),
            'summary' => $counts,
        ]);
    }

    private function supervisorProfile(array $profile): array
    {
        $displayName = trim((string) (
            ($profile['display_name'] ?? null)
            ?: ($profile['full_name'] ?? null)
            ?: ($profile['nickname'] ?? null)
            ?: 'Supervisor RehabiAnex'
        ));

        return [
            'display_name' => $displayName,
            'supervisor_type' => $profile['supervisor_type'] ?? null,
            'supervisor_code' => $profile['supervisor_code'] ?? null,
            'authorized' => (bool) ($profile['authorized'] ?? false),
            'verified' => (bool) ($profile['verified'] ?? false),
            'status' => $profile['status'] ?? null,
            'updated_at' => $profile['updated_at'] ?? $profile['created_at'] ?? null,
        ];
    }

    private function authorizedPatientUids(string $supervisorUid): array
    {
        $consentedPatients = [];

        foreach ($this->db->collection('consents')
            ->where('supervisor_uid', '=', $supervisorUid)
            ->select(['patient_uid', 'explicit_consent', 'status', 'revoked_at'])
            ->documents() as $document) {
            if (! $document->exists()) {
                continue;
            }

            $consent = $document->data();

            if (($consent['explicit_consent'] ?? false) === true
                && ($consent['status'] ?? 'active') === 'active'
                && empty($consent['revoked_at'])) {
                $patientUid = (string) ($consent['patient_uid'] ?? '');

                if ($patientUid !== '') {
                    $consentedPatients[$patientUid] = true;
                }
            }
        }

        if ($consentedPatients === []) {
            return [];
        }

        $authorized = [];

        foreach ($this->db->collection('patients')
            ->where('supervisor_uid', '=', $supervisorUid)
            ->select(['uid', 'wants_supervision', 'supervision_status'])
            ->documents() as $document) {
            if (! $document->exists()) {
                continue;
            }

            $patient = $document->data();
            $patientUid = (string) ($patient['uid'] ?? $document->id());

            if (($patient['wants_supervision'] ?? false) === true
                && ($patient['supervision_status'] ?? null) === 'accepted'
                && isset($consentedPatients[$patientUid])) {
                $authorized[$patientUid] = true;
            }
        }

        return $authorized;
    }

    private function pendingRequestsCount(string $supervisorUid): int
    {
        return $this->countOwnedDocuments(
            'supervision_requests',
            $supervisorUid,
            static fn (array $data): bool => ($data['status'] ?? null) === 'pending'
                && empty($data['deleted_at'])
        );
    }

    private function openInterventionsCount(
        string $supervisorUid,
        array $authorizedPatientUids
    ): int
    {
        if ($authorizedPatientUids === []) {
            return 0;
        }

        return $this->countOwnedDocuments(
            'interventions',
            $supervisorUid,
            static fn (array $data): bool => isset(
                $authorizedPatientUids[(string) ($data['patient_uid'] ?? '')]
            ) && in_array($data['status'] ?? null, ['open', 'in_progress'], true)
                && empty($data['deleted_at'])
        );
    }

    private function countOwnedDocuments(
        string $collection,
        string $supervisorUid,
        callable $matches
    ): int {
        $count = 0;

        $fields = $collection === 'interventions'
            ? ['patient_uid', 'status', 'deleted_at']
            : ['status', 'deleted_at'];

        foreach ($this->db->collection($collection)
            ->where('supervisor_uid', '=', $supervisorUid)
            ->select($fields)
            ->documents() as $document) {
            if ($document->exists() && $matches($document->data())) {
                $count++;
            }
        }

        return $count;
    }
}
