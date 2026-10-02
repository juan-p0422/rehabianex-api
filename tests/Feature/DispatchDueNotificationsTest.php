<?php

namespace Tests\Feature;

use App\Services\FcmNotificationDispatcher;
use App\Services\FirebaseService;
use Tests\TestCase;

class DispatchDueNotificationsTest extends TestCase
{
    public function test_command_dispatches_due_agenda_and_daily_checkin_candidates(): void
    {
        config(['fcm.enabled' => true, 'fcm.dry_run' => false]);
        $db = new DueNotificationsFakeDatabase([
            'agenda_events' => [
                'event-due' => [
                    'event_id' => 'event-due',
                    'patient_uid' => 'patient-synthetic',
                    'starts_at' => now()->subMinute()->toIso8601String(),
                    'status' => 'scheduled',
                ],
                'event-future' => [
                    'event_id' => 'event-future',
                    'patient_uid' => 'patient-synthetic',
                    'starts_at' => now()->addHour()->toIso8601String(),
                    'status' => 'scheduled',
                ],
            ],
            'notification_settings' => [
                'settings-synthetic' => [
                    'patient_uid' => 'patient-synthetic',
                    'event_reminders_enabled' => true,
                    'daily_check_in_enabled' => true,
                    'daily_check_in_time' => '00:00',
                    'timezone' => 'UTC',
                ],
            ],
        ]);
        $firebase = $this->createMock(FirebaseService::class);
        $firebase->method('db')->willReturn($db);
        $dispatcher = $this->createMock(FcmNotificationDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('sendAppointmentReminder')
            ->with($this->callback(fn (array $event): bool => $event['event_id'] === 'event-due'));
        $dispatcher->expects($this->once())
            ->method('sendProgressCheckin')
            ->with(
                'patient-synthetic',
                $this->callback(fn (string $id): bool => str_starts_with($id, 'chk_')),
                $this->callback(fn (string $scheduledAt): bool => str_contains($scheduledAt, 'T')),
            );

        $this->app->instance(FirebaseService::class, $firebase);
        $this->app->instance(FcmNotificationDispatcher::class, $dispatcher);

        $this->artisan('notifications:dispatch-due')
            ->expectsOutputToContain('Candidatos procesados: agenda=1, check-in=1.')
            ->assertSuccessful();
    }

    public function test_command_is_a_safe_noop_when_fcm_is_disabled(): void
    {
        config(['fcm.enabled' => false]);
        $firebase = $this->createMock(FirebaseService::class);
        $firebase->expects($this->never())->method('db');
        $dispatcher = $this->createMock(FcmNotificationDispatcher::class);
        $dispatcher->expects($this->never())->method('sendAppointmentReminder');
        $dispatcher->expects($this->never())->method('sendProgressCheckin');

        $this->app->instance(FirebaseService::class, $firebase);
        $this->app->instance(FcmNotificationDispatcher::class, $dispatcher);

        $this->artisan('notifications:dispatch-due')->assertSuccessful();
    }

    public function test_command_is_a_safe_noop_in_dry_run_to_preserve_dedupe(): void
    {
        config(['fcm.enabled' => true, 'fcm.dry_run' => true]);
        $firebase = $this->createMock(FirebaseService::class);
        $firebase->expects($this->never())->method('db');
        $dispatcher = $this->createMock(FcmNotificationDispatcher::class);
        $dispatcher->expects($this->never())->method('sendAppointmentReminder');
        $dispatcher->expects($this->never())->method('sendProgressCheckin');

        $this->app->instance(FirebaseService::class, $firebase);
        $this->app->instance(FcmNotificationDispatcher::class, $dispatcher);

        $this->artisan('notifications:dispatch-due')
            ->expectsOutputToContain('FCM está en dry-run; no se reservaron recordatorios en el ledger.')
            ->assertSuccessful();
    }
}

class DueNotificationsFakeDatabase
{
    public function __construct(public array $collections) {}

    public function collection(string $name): DueNotificationsFakeCollection
    {
        return new DueNotificationsFakeCollection($this->collections[$name] ?? []);
    }
}

class DueNotificationsFakeCollection
{
    private array $filters = [];

    public function __construct(private array $documents) {}

    public function where(string $field, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->filters[] = [$field, $operator, $value];

        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->documents = array_slice($this->filtered(), 0, $limit, true);
        $clone->filters = [];

        return $clone;
    }

    public function documents(): array
    {
        $snapshots = [];

        foreach ($this->filtered() as $id => $data) {
            $snapshots[] = new DueNotificationsFakeSnapshot((string) $id, $data);
        }

        return $snapshots;
    }

    private function filtered(): array
    {
        return array_filter($this->documents, function (array $data): bool {
            foreach ($this->filters as [$field, $operator, $value]) {
                if ($operator !== '=' || ($data[$field] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        });
    }
}

class DueNotificationsFakeSnapshot
{
    public function __construct(
        private string $id,
        private array $data,
    ) {}

    public function exists(): bool
    {
        return true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function data(): array
    {
        return $this->data;
    }
}
