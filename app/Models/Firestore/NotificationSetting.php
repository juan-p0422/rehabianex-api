<?php

namespace App\Models\Firestore;

class NotificationSetting extends FirestoreResource
{
    public const COLLECTION = 'notification_settings';

    public const ID_FIELD = 'settings_id';

    public const FILTERABLE = ['patient_uid', 'daily_check_in_enabled', 'craving_alerts_enabled', 'supervisor_alerts_enabled'];
}
