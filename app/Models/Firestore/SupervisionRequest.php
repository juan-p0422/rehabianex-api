<?php

namespace App\Models\Firestore;

class SupervisionRequest extends FirestoreResource
{
    public const COLLECTION = 'supervision_requests';

    public const ID_FIELD = 'request_id';

    public const FILTERABLE = ['patient_uid', 'supervisor_uid', 'status'];
}
