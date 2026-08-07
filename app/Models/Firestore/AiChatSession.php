<?php

namespace App\Models\Firestore;

class AiChatSession extends FirestoreResource
{
    public const COLLECTION = 'ai_chat_sessions';

    public const ID_FIELD = 'session_id';

    public const FILTERABLE = ['patient_uid', 'supervisor_uid', 'channel', 'status'];
}
