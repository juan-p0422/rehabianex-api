<?php

namespace App\Support;

final class ProfileFormOptions
{
    /**
     * `padrino` remains accepted for legacy Android clients. New clients use
     * `support_sponsor`.
     */
    public const SUPERVISOR_TYPES = [
        'doctor',
        'psychiatrist',
        'psychologist',
        'clinical_psychologist',
        'social_worker',
        'support_sponsor',
        'other',
        'padrino',
    ];

    public const CONTACT_RELATIONSHIP_CATEGORIES = [
        'family',
        'friend',
        'partner',
        'health_professional',
        'support_peer',
        'work_or_school',
        'other',
    ];
}
