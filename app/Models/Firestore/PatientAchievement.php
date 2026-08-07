<?php

namespace App\Models\Firestore;

class PatientAchievement extends FirestoreResource
{
    public const COLLECTION = 'patient_achievements';

    public const ID_FIELD = 'patient_achievement_id';

    public const FILTERABLE = ['patient_uid', 'achievement_id'];
}
