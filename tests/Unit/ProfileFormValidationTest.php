<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FirestoreCrudController;
use App\Services\FirestoreAccessService;
use App\Support\MexicoPhoneNumber;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

class ProfileFormValidationTest extends TestCase
{
    public function test_mexico_phone_normalizes_supported_input_formats(): void
    {
        $this->assertSame('+524491234567', MexicoPhoneNumber::normalize('4491234567'));
        $this->assertSame('+524491234567', MexicoPhoneNumber::normalize('(449) 123-4567'));
        $this->assertSame('+524491234567', MexicoPhoneNumber::normalize('+52 449 123 4567'));
        $this->assertTrue(MexicoPhoneNumber::isValid('+524491234567'));
    }

    public function test_mexico_phone_rejects_wrong_length_foreign_prefix_and_letters(): void
    {
        $this->assertFalse(MexicoPhoneNumber::isValid('449123456'));
        $this->assertFalse(MexicoPhoneNumber::isValid('+14155552671'));
        $this->assertFalse(MexicoPhoneNumber::isValid('449CALLNOW'));
    }

    public function test_crud_payload_normalizes_new_phone_write_before_validation(): void
    {
        $controller = $this->controllerWithoutConstructor();
        $payload = $this->invoke($controller, 'normalizeCompatibilityPayload', [
            'patients',
            ['phone' => '(449) 123-4567'],
            false,
        ]);

        $this->assertSame('+524491234567', $payload['phone']);
    }

    public function test_profile_and_contact_payloads_accept_accents_spaces_and_boundary_lengths(): void
    {
        $controller = $this->controllerWithoutConstructor();

        $this->invoke($controller, 'validatePayload', [
            'patients',
            [
                'full_name' => 'María José de la O',
                'nickname' => str_repeat('á', 80),
                'phone' => '+524491234567',
            ],
            false,
        ]);
        $this->invoke($controller, 'validatePayload', [
            'support-contacts',
            [
                'name' => 'José Luis Álvarez',
                'phone' => '+524491234567',
                'relationship' => 'Compañero de grupo',
                'relationship_category' => 'other',
                'relationship_other' => 'Vecino de confianza',
            ],
            true,
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_profile_validation_rejects_invalid_phone_blank_name_and_oversized_text(): void
    {
        $controller = $this->controllerWithoutConstructor();

        try {
            $this->invoke($controller, 'validatePayload', [
                'patients',
                [
                    'full_name' => '   ',
                    'nickname' => str_repeat('a', 81),
                    'phone' => '+14155552671',
                ],
                false,
            ]);
            $this->fail('La validación debía rechazar el formulario inválido.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('full_name', $exception->errors());
            $this->assertArrayHasKey('nickname', $exception->errors());
            $this->assertArrayHasKey('phone', $exception->errors());
        }
    }

    public function test_other_supervisor_type_requires_description(): void
    {
        $controller = $this->controllerWithoutConstructor();

        try {
            $this->invoke($controller, 'validatePayload', [
                'supervisors',
                ['supervisor_type' => 'other'],
                false,
            ]);
            $this->fail('La opción Otro debía exigir una descripción.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Describe el tipo de supervisor cuando seleccionas Otro.',
                $exception->errors()['supervisor_type_other'][0]
            );
        }
    }

    public function test_unknown_contact_category_has_clear_validation_error(): void
    {
        $controller = $this->controllerWithoutConstructor();

        try {
            $this->invoke($controller, 'validatePayload', [
                'support-contacts',
                [
                    'name' => 'Contacto sintético',
                    'phone' => '+524491234567',
                    'relationship_category' => 'sister',
                ],
                true,
            ]);
            $this->fail('La categoría desconocida debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'La categoría de relación seleccionada no es válida.',
                $exception->errors()['relationship_category'][0]
            );
        }
    }

    public function test_other_contact_category_requires_description(): void
    {
        $controller = $this->controllerWithoutConstructor();

        try {
            $this->invoke($controller, 'validatePayload', [
                'support-contacts',
                [
                    'name' => 'Contacto sintético',
                    'phone' => '+524491234567',
                    'relationship_category' => 'other',
                ],
                true,
            ]);
            $this->fail('La categoría Otro debía exigir una descripción.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Describe la relación cuando seleccionas Otro.',
                $exception->errors()['relationship_other'][0]
            );
        }
    }

    public function test_new_form_fields_remain_inside_owner_allowlists(): void
    {
        $access = (new ReflectionClass(FirestoreAccessService::class))->newInstanceWithoutConstructor();

        $supervisorRequest = Request::create('/api/supervisors/supervisor-1', 'PATCH');
        $supervisorRequest->attributes->set('firebase_uid', 'supervisor-1');
        $supervisorRequest->attributes->set('firebase_role', 'supervisor');
        $supervisorChanges = $access->prepareUpdate(
            'supervisors',
            ['uid' => 'supervisor-1'],
            ['supervisor_type' => 'other', 'supervisor_type_other' => 'Terapeuta familiar'],
            $supervisorRequest
        );

        $patientRequest = Request::create('/api/support-contacts/contact-1', 'PATCH');
        $patientRequest->attributes->set('firebase_uid', 'patient-1');
        $patientRequest->attributes->set('firebase_role', 'patient');
        $contactChanges = $access->prepareUpdate(
            'support-contacts',
            ['contact_id' => 'contact-1', 'patient_uid' => 'patient-1'],
            [
                'relationship_category' => 'other',
                'relationship_other' => 'Vecino de confianza',
            ],
            $patientRequest
        );

        $this->assertSame('Terapeuta familiar', $supervisorChanges['supervisor_type_other']);
        $this->assertSame('other', $contactChanges['relationship_category']);
        $this->assertSame('Vecino de confianza', $contactChanges['relationship_other']);
    }

    public function test_legacy_profile_phone_is_returned_without_rewriting_it(): void
    {
        $reflection = new ReflectionClass(AuthController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $profile = $reflection->getMethod('stableProfile')->invoke($controller, [
            'uid' => 'legacy-user',
            'phone' => '449-123-4567',
        ], 'patient');

        $this->assertSame('449-123-4567', $profile['phone']);
    }

    public function test_legacy_patient_notes_route_requires_answered_scales_but_accepts_zero(): void
    {
        $controller = $this->controllerWithoutConstructor();

        $this->invoke($controller, 'validatePayload', [
            'patient-notes',
            [
                'mood' => 'Sin malestar',
                'mood_score' => 0,
                'anxiety_level' => 0,
                'craving_level' => 0,
                'had_relapse' => false,
            ],
            true,
        ]);

        try {
            $this->invoke($controller, 'validatePayload', [
                'patient-notes',
                ['mood' => 'Sin contestar'],
                true,
            ]);
            $this->fail('Las escalas ausentes debían rechazarse en la ruta de compatibilidad.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mood_score', $exception->errors());
            $this->assertArrayHasKey('anxiety_level', $exception->errors());
            $this->assertArrayHasKey('craving_level', $exception->errors());
            $this->assertArrayHasKey('had_relapse', $exception->errors());
        }
    }

    public function test_sobriety_date_uses_mexico_calendar_day_and_rejects_future_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11T00:30:00-06:00'));
        $controller = $this->controllerWithoutConstructor();

        try {
            $this->invoke($controller, 'validatePayload', [
                'patients',
                ['sobriety_start_date' => '2026-10-11'],
                false,
            ]);
            $this->invoke($controller, 'validatePayload', [
                'patients',
                ['sobriety_start_date' => '2026-10-12T04:00:00+00:00'],
                false,
            ]);

            $this->invoke($controller, 'validatePayload', [
                'patients',
                ['sobriety_start_date' => '2026-10-12'],
                false,
            ]);
            $this->fail('Una fecha futura debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'La fecha de sobriedad no puede estar en el futuro.',
                $exception->errors()['sobriety_start_date'][0]
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function controllerWithoutConstructor(): FirestoreCrudController
    {
        return (new ReflectionClass(FirestoreCrudController::class))->newInstanceWithoutConstructor();
    }

    private function invoke(object $object, string $method, array $arguments): mixed
    {
        return (new ReflectionClass($object))->getMethod($method)->invokeArgs($object, $arguments);
    }
}
