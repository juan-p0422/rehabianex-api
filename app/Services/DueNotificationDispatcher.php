<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

class DueNotificationDispatcher
{
    private $db;

    private array $settingsByPatient = [];

    public function __construct(
        private FirebaseService $firebase,
        private FcmNotificationDispatcher $dispatcher,
    ) {}

    /**
     * @return array{agenda: int, check_in: int}
     */
    public function dispatch(): array
    {
        if (! config('fcm.enabled') || config('fcm.dry_run', true)) {
            return ['agenda' => 0, 'check_in' => 0];
        }

        $this->db = $this->firebase->db();
        $this->settingsByPatient = [];
        $now = CarbonImmutable::now('UTC');

        return [
            'agenda' => $this->dispatchDueAgenda($now),
            'check_in' => $this->dispatchDueCheckins($now),
        ];
    }

    private function dispatchDueAgenda(CarbonImmutable $now): int
    {
        $processed = 0;

        foreach ($this->db->collection('agenda_events')->documents() as $document) {
            if (! $document->exists()) {
                continue;
            }

            $event = $document->data();
            $eventId = (string) (($event['event_id'] ?? null) ?: $document->id());
            $patientUid = (string) ($event['patient_uid'] ?? '');
            $startsAt = $this->parseDate($event['starts_at'] ?? null);

            if ($eventId === '' || $patientUid === '' || $startsAt === null
                || $startsAt->isAfter($now)
                || ($event['status'] ?? 'scheduled') !== 'scheduled'
                || ! empty($event['deleted_at'])
                || ! $this->eventRemindersEnabled($patientUid)) {
                continue;
            }

            $event['event_id'] = $eventId;
            $event['starts_at'] = $startsAt->toIso8601String();
            $this->dispatcher->sendAppointmentReminder($event);
            $processed++;
        }

        return $processed;
    }

    private function dispatchDueCheckins(CarbonImmutable $now): int
    {
        $processed = 0;

        foreach ($this->db->collection('notification_settings')->documents() as $document) {
            if (! $document->exists()) {
                continue;
            }

            $settings = $document->data();
            $patientUid = (string) ($settings['patient_uid'] ?? $settings['user_uid'] ?? '');
            $enabled = ($settings['daily_check_in_enabled']
                ?? $settings['daily_note_enabled']
                ?? false) === true;
            $time = (string) ($settings['daily_check_in_time']
                ?? $settings['daily_note_time']
                ?? '');
            $timezone = (string) ($settings['timezone'] ?? 'America/Mexico_City');

            if ($patientUid === '' || ! $enabled || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
                continue;
            }

            try {
                $localNow = $now->setTimezone($timezone);
                $scheduledLocal = CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    $localNow->format('Y-m-d').' '.$time,
                    $timezone,
                );
            } catch (Throwable) {
                continue;
            }

            if ($scheduledLocal === false || $scheduledLocal->isAfter($localNow)) {
                continue;
            }

            $scheduledAt = $scheduledLocal->setTimezone('UTC')->toIso8601String();
            $entityId = 'chk_'.substr(hash('sha256', $patientUid.'|'.$scheduledAt), 0, 32);
            $this->dispatcher->sendProgressCheckin($patientUid, $entityId, $scheduledAt);
            $processed++;
        }

        return $processed;
    }

    private function eventRemindersEnabled(string $patientUid): bool
    {
        if (array_key_exists($patientUid, $this->settingsByPatient)) {
            return $this->settingsByPatient[$patientUid];
        }

        $enabled = false;
        $documents = $this->db->collection('notification_settings')
            ->where('patient_uid', '=', $patientUid)
            ->limit(1)
            ->documents();

        foreach ($documents as $document) {
            if ($document->exists()) {
                $enabled = ($document->data()['event_reminders_enabled'] ?? false) === true;
            }
        }

        return $this->settingsByPatient[$patientUid] = $enabled;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
