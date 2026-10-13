<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FirestoreCrudController;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

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
        config([
            'adaptive_support.available' => true,
            'adaptive_support.rules_version' => 'experimental-v1',
        ]);

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
        $this->assertTrue($output['adaptive_rules_available']);
        $this->assertSame('experimental-v1', $output['adaptive_rules_version']);
        $this->assertFalse($output['adaptive_suggestions_enabled']);
        $this->assertFalse($output['inactivity_reminder_enabled']);
        $this->assertSame(3, $output['inactivity_days']);
        $this->assertSame('18:00', $output['inactivity_reminder_time']);
        $this->assertSame(8, $output['adaptive_craving_threshold']);
        $this->assertSame(8, $output['adaptive_anxiety_threshold']);
    }

    public function test_adaptive_preferences_accept_documented_boundaries(): void
    {
        $this->invoke('validatePayload', [
            'notification-settings',
            [
                'adaptive_suggestions_enabled' => true,
                'inactivity_reminder_enabled' => true,
                'inactivity_days' => 2,
                'inactivity_reminder_time' => '00:00',
                'adaptive_craving_threshold' => 6,
                'adaptive_anxiety_threshold' => 10,
            ],
            false,
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_adaptive_preferences_reject_out_of_range_values(): void
    {
        try {
            $this->invoke('validatePayload', [
                'notification-settings',
                [
                    'inactivity_days' => 15,
                    'inactivity_reminder_time' => '24:00',
                    'adaptive_craving_threshold' => 5,
                    'adaptive_anxiety_threshold' => 11,
                ],
                false,
            ]);
            $this->fail('Los valores fuera del contrato debían rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inactivity_days', $exception->errors());
            $this->assertArrayHasKey('inactivity_reminder_time', $exception->errors());
            $this->assertArrayHasKey('adaptive_craving_threshold', $exception->errors());
            $this->assertArrayHasKey('adaptive_anxiety_threshold', $exception->errors());
        }
    }

    public function test_global_switch_cannot_be_overridden_by_stored_data(): void
    {
        config(['adaptive_support.available' => false]);

        $output = $this->invoke('notificationSettingsOutput', [[
            'adaptive_rules_available' => true,
            'adaptive_rules_version' => 'untrusted-version',
            'adaptive_suggestions_enabled' => true,
        ]]);

        $this->assertFalse($output['adaptive_rules_available']);
        $this->assertSame('experimental-v1', $output['adaptive_rules_version']);
        $this->assertTrue($output['adaptive_suggestions_enabled']);
    }

    public function test_adaptive_contract_uses_existing_resources_and_prohibits_third_party_notifications(): void
    {
        $this->assertSame('grounding_54321', config('adaptive_support.resources.craving'));
        $this->assertSame('breathing_446', config('adaptive_support.resources.anxiety'));
        $this->assertFalse(config('adaptive_support.third_party_notifications'));
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
