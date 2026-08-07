<?php

namespace App\Models\Firestore;

class AiChatMessage extends FirestoreResource
{
    public const COLLECTION = 'ai_chat_messages';

    public const ID_FIELD = 'message_id';

    public const FILTERABLE = ['session_id', 'sender_type'];
}
