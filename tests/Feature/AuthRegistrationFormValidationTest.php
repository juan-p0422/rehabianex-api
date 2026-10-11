<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Services\FcmNotificationDispatcher;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AuthRegistrationFormValidationTest extends TestCase
{
    public function test_registration_rejects_invalid_mexico_phone_and_blank_name(): void
    {
        $response = $this->controller()->register(Request::create(
            '/api/auth/register',
            'POST',
            [
                'email' => 'synthetic@example.test',
                'password' => 'SyntheticPassword123!',
                'full_name' => '   ',
                'role' => 'patient',
                'phone' => '+14155552671',
                'privacy_notice_accepted' => true,
                'privacy_notice_version' => '2026-08-01',
            ],
        ));
        $errors = $response->getData(true)['errors'];

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('full_name', $errors);
        $this->assertArrayHasKey('phone', $errors);
    }

    public function test_other_supervisor_registration_requires_description(): void
    {
        $response = $this->controller()->register(Request::create(
            '/api/auth/register',
            'POST',
            [
                'email' => 'supervisor@example.test',
                'password' => 'SyntheticPassword123!',
                'full_name' => 'Supervisora Álvarez',
                'role' => 'supervisor',
                'supervisor_type' => 'other',
                'privacy_notice_accepted' => true,
                'privacy_notice_version' => '2026-08-01',
            ],
        ));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('supervisor_type_other', $response->getData(true)['errors']);
        $this->assertSame(
            'Describe el tipo de supervisor cuando seleccionas Otro.',
            $response->getData(true)['errors']['supervisor_type_other'][0]
        );
    }

    private function controller(): AuthController
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('auth')->once()->andReturn(Mockery::mock());
        $firebase->shouldReceive('db')->once()->andReturn(Mockery::mock());

        return new AuthController(
            $firebase,
            Mockery::mock(FcmNotificationDispatcher::class),
        );
    }
}
