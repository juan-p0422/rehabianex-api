<?php

namespace App\Services;

use App\Models\UserFcmToken;
use Throwable;

class FcmRecipientAuthorizer
{
    private $db;

    public function __construct(
        FirebaseService $firebase,
        private FirestoreAccessService $access,
    ) {
        $this->db = $firebase->db();
    }

    public function canNotifyPatient(string $patientUid): bool
    {
        if ($patientUid === '' || ! $this->hasActiveToken($patientUid)) {
            return false;
        }

        $snapshot = $this->db->collection('patients')->document($patientUid)->snapshot();

        if (! $snapshot->exists()) {
            return false;
        }

        $profile = $snapshot->data();

        return ($profile['role'] ?? 'patient') === 'patient'
            && ! in_array($profile['status'] ?? 'active', ['disabled', 'suspended'], true)
            && empty($profile['deleted_at']);
    }

    public function canNotifySupervisor(string $supervisorUid): bool
    {
        if ($supervisorUid === '' || ! $this->hasActiveToken($supervisorUid)) {
            return false;
        }

        $snapshot = $this->db->collection('supervisors')->document($supervisorUid)->snapshot();

        if (! $snapshot->exists()) {
            return false;
        }

        $profile = $snapshot->data();

        return ($profile['role'] ?? 'supervisor') === 'supervisor'
            && ($profile['status'] ?? null) === 'active'
            && ($profile['authorized'] ?? false) === true
            && ($profile['verified'] ?? false) === true
            && empty($profile['deleted_at']);
    }

    public function authorizedSupervisorUidForPatient(string $patientUid): ?string
    {
        if ($patientUid === '') {
            return null;
        }

        $patient = $this->db->collection('patients')->document($patientUid)->snapshot();

        if (! $patient->exists()) {
            return null;
        }

        $supervisorUid = (string) ($patient->data()['supervisor_uid'] ?? '');

        if (! $this->canNotifySupervisor($supervisorUid)) {
            return null;
        }

        try {
            return $this->access->supervisorCanAccessPatient(
                $supervisorUid,
                $patientUid,
                ['patient_notes'],
            ) ? $supervisorUid : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function activeAdminUids(): array
    {
        $uids = [];

        foreach ($this->db->collection('admins')->documents() as $document) {
            if (! $document->exists()) {
                continue;
            }

            $profile = $document->data();
            $uid = (string) (($profile['uid'] ?? null) ?: $document->id());
            $validated = ($profile['verified'] ?? true) === true
                && ($profile['authorized'] ?? true) === true;

            if (($profile['role'] ?? null) === 'admin'
                && ($profile['status'] ?? null) === 'active'
                && $validated
                && empty($profile['deleted_at'])
                && $this->hasActiveToken($uid)) {
                $uids[] = $uid;
            }
        }

        return array_values(array_unique($uids));
    }

    private function hasActiveToken(string $userId): bool
    {
        return UserFcmToken::query()
            ->where('user_id', $userId)
            ->where('platform', 'android')
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->exists();
    }
}
