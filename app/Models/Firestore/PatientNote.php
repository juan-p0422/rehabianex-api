<?php

namespace App\Models\Firestore;

class PatientNote extends FirestoreResource
{
    public const COLLECTION = 'patient_notes';

    public const ID_FIELD = 'note_id';

    public const FILTERABLE = ['patient_uid', 'ai_risk_level', 'had_relapse', 'mood'];
}
