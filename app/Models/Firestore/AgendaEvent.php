<?php

namespace App\Models\Firestore;

class AgendaEvent extends FirestoreResource
{
    public const COLLECTION = 'agenda_events';

    public const ID_FIELD = 'event_id';

    public const FILTERABLE = ['patient_uid', 'supervisor_uid', 'type', 'status'];
}
