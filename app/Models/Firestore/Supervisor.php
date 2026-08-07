<?php

namespace App\Models\Firestore;

class Supervisor extends FirestoreResource
{
    public const COLLECTION = 'supervisors';

    public const ID_FIELD = 'uid';

    public const FILTERABLE = ['status', 'supervisor_type', 'authorized', 'email'];
}
