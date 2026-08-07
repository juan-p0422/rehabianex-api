<?php

namespace App\Models\Firestore;

class Consent extends FirestoreResource
{
    public const COLLECTION = 'consents';

    public const ID_FIELD = 'consent_id';

    public const FILTERABLE = ['patient_uid', 'supervisor_uid', 'type', 'explicit_consent'];
}
