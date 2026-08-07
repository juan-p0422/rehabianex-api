<?php

use App\Models\Firestore\Achievement;
use App\Models\Firestore\AgendaEvent;
use App\Models\Firestore\AiChatMessage;
use App\Models\Firestore\AiChatSession;
use App\Models\Firestore\Consent;
use App\Models\Firestore\Intervention;
use App\Models\Firestore\LocalResource;
use App\Models\Firestore\NotificationSetting;
use App\Models\Firestore\Patient;
use App\Models\Firestore\PatientAchievement;
use App\Models\Firestore\PatientNote;
use App\Models\Firestore\SupervisionRequest;
use App\Models\Firestore\Supervisor;
use App\Models\Firestore\SupportContact;

return [
    'profile_collections' => [
        'patient' => 'patients',
        'supervisor' => 'supervisors',
        'admin' => 'admins',
    ],

    'resources' => [
        'supervisors' => Supervisor::class,
        'patients' => Patient::class,
        'patient-notes' => PatientNote::class,
        'support-contacts' => SupportContact::class,
        'agenda-events' => AgendaEvent::class,
        'achievements' => Achievement::class,
        'patient-achievements' => PatientAchievement::class,
        'local-resources' => LocalResource::class,
        'consents' => Consent::class,
        'notification-settings' => NotificationSetting::class,
        'interventions' => Intervention::class,
        'supervision-requests' => SupervisionRequest::class,
        'ai-chat-sessions' => AiChatSession::class,
        'ai-chat-messages' => AiChatMessage::class,
    ],
];
