<?php

namespace App\Models\Firestore;

class Intervention extends FirestoreResource
{
    public const COLLECTION = 'interventions';

    public const ID_FIELD = 'intervention_id';

    public const FILTERABLE = ['patient_uid', 'supervisor_uid', 'status', 'trigger_note_id'];
}
