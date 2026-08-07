<?php

namespace App\Models\Firestore;

class SupportContact extends FirestoreResource
{
    public const COLLECTION = 'support_contacts';

    public const ID_FIELD = 'contact_id';

    public const FILTERABLE = ['patient_uid', 'relationship', 'can_receive_alerts'];
}
