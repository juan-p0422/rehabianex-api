<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FirestoreCrudController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class NotificationSettingsContractTest extends TestCase
{
    public function test_android_daily_note_input_maps_to_daily_check_in_contract(): void
    {
        $payload = $this->invoke('normalizeCompatibilityPayload', [
            'notification-settings',
            [
                'user_uid' => 'patient-1',
                'daily_note_enabled' => true,
                'daily_note_time' => '21:30',
            ],
            true,
        ]);

        $this->assertSame('patient-1', $payload['patient_uid']);
        $this->assertTrue($payload['daily_note_enabled']);
        $this->assertTrue($payload['daily_check_in_enabled']);
        $this->assertSame('21:30', $payload['daily_note_time']);
        $this->assertSame('21:30', $payload['daily_check_in_time']);
        $this->assertSame('America/Mexico_City', $payload['timezone']);
    }

    public function test_contract_input_maps_back_to_android_aliases(): void
    {
        $payload = $this->invoke('normalizeCompatibilityPayload', [
            'notification-settings',
            [
                'daily_check_in_enabled' => true,
                'daily_check_in_time' => '20:00',
            ],
            false,
        ]);

        $this->assertTrue($payload['daily_note_enabled']);
        $this->assertSame('20:00', $payload['daily_note_time']);
        $this->assertArrayNotHasKey('timezone', $payload);
    }

    public function test_output_always_contains_official_aliases_and_defaults(): void
    {
        $output = $this->invoke('notificationSettingsOutput', [[
            'settings_id' => 'settings-1',
            'patient_uid' => 'patient-1',
            'daily_check_in_enabled' => true,
            'daily_check_in_time' => '22:00',
        ]]);

        $this->assertSame('settings-1', $output['settings_id']);
        $this->assertSame('settings-1', $output['notification_setting_id']);
        $this->assertSame('patient-1', $output['user_uid']);
        $this->assertSame('patient-1', $output['patient_uid']);
        $this->assertTrue($output['daily_check_in_enabled']);
        $this->assertTrue($output['daily_note_enabled']);
        $this->assertSame('22:00', $output['daily_check_in_time']);
        $this->assertSame('22:00', $output['daily_note_time']);
        $this->assertSame('America/Mexico_City', $output['timezone']);
        $this->assertArrayHasKey('sober_day_enabled', $output);
        $this->assertArrayHasKey('achievement_enabled', $output);
        $this->assertArrayHasKey('event_reminders_enabled', $output);
        $this->assertArrayHasKey('motivational_enabled', $output);
    }

    public function test_new_settings_use_patient_uid_as_deterministic_document_id(): void
    {
        $id = $this->invoke('newDocumentId', [
            'notification-settings',
            ['patient_uid' => 'patient-1'],
        ]);

        $this->assertSame('patient-1', $id);
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass(FirestoreCrudController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod($method)->invokeArgs($controller, $arguments);
    }
}
