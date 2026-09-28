<?php

namespace App\Jobs;

use App\Services\FcmService;
use App\Services\FirebaseService;
use App\Support\FcmNotificationTypes;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAppointmentReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $eventId,
        public string $patientUid,
        public string $expectedStartsAt,
        public string $notificationId,
    ) {}

    public function handle(FcmService $fcm, FirebaseService $firebase): void
    {
        if (! config('fcm.enabled')) {
            return;
        }

        $event = $firebase->db()
            ->collection('agenda_events')
            ->document($this->eventId)
            ->snapshot();

        if (! $event->exists()) {
            return;
        }

        $data = $event->data();

        if (! empty($data['deleted_at'])
            || ($data['status'] ?? 'scheduled') !== 'scheduled'
            || (string) ($data['patient_uid'] ?? '') !== $this->patientUid
            || (string) ($data['starts_at'] ?? '') !== $this->expectedStartsAt
            || ! $this->eventRemindersEnabled($firebase)) {
            return;
        }

        $fcm->sendToUser(
            $this->patientUid,
            FcmNotificationTypes::APPOINTMENT_REMINDER,
            '/agenda-events/'.$this->eventId,
            $this->eventId,
            $this->notificationId,
            now()->toIso8601String(),
            'normal',
        );
    }

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    private function eventRemindersEnabled(FirebaseService $firebase): bool
    {
        $settings = $firebase->db()
            ->collection('notification_settings')
            ->where('patient_uid', '=', $this->patientUid)
            ->limit(1)
            ->documents();

        foreach ($settings as $document) {
            if ($document->exists()) {
                return ($document->data()['event_reminders_enabled'] ?? false) === true;
            }
        }

        return false;
    }
}
