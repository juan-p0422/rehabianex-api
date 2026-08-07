<?php

namespace App\Models\Firestore;

class Patient extends FirestoreResource
{
    public const COLLECTION = 'patients';

    public const ID_FIELD = 'uid';

    public const FILTERABLE = ['supervisor_uid', 'status', 'wants_supervision', 'is_anonymous', 'email'];
}
