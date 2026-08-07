<?php

namespace App\Models\Firestore;

class LocalResource extends FirestoreResource
{
    public const COLLECTION = 'local_resources';

    public const ID_FIELD = 'resource_id';

    public const FILTERABLE = ['type', 'city'];
}
