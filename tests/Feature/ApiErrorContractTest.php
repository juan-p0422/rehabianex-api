<?php

namespace Tests\Feature;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    public function test_missing_api_route_uses_official_error_envelope(): void
    {
        $this->getJson('/api/route-that-does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'ok' => false,
                'message' => 'El recurso solicitado no fue encontrado.',
                'errors' => [],
            ]);
    }

    public function test_all_official_statuses_have_stable_error_envelope(): void
    {
        foreach ([400, 401, 403, 404, 409, 422, 429, 500, 502] as $status) {
            $response = ApiErrorResponse::make(
                ApiErrorResponse::defaultMessage($status),
                $status
            );
            $payload = json_decode($response->getContent(), true);

            $this->assertSame($status, $response->getStatusCode());
            $this->assertFalse($payload['ok']);
            $this->assertIsString($payload['message']);
            $this->assertNotSame('', $payload['message']);
            $this->assertSame([], $payload['errors']);
            $this->assertStringContainsString('"errors":{}', $response->getContent());
        }
    }

    public function test_server_and_provider_exceptions_do_not_expose_internal_messages(): void
    {
        $serverError = ApiErrorResponse::fromException(
            new HttpException(500, 'Stack trace and database password')
        );
        $providerError = ApiErrorResponse::fromException(
            new HttpException(502, 'Firebase private response body')
        );

        $this->assertStringNotContainsString('password', $serverError->getContent());
        $this->assertStringNotContainsString('private response', $providerError->getContent());
        $this->assertSame(
            ApiErrorResponse::defaultMessage(500),
            $serverError->getData(true)['message']
        );
        $this->assertSame(
            ApiErrorResponse::defaultMessage(502),
            $providerError->getData(true)['message']
        );
    }

    public function test_rate_limit_exception_uses_readable_message(): void
    {
        $response = ApiErrorResponse::fromException(
            new HttpException(429, 'Too Many Attempts.')
        );

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(
            'Demasiadas solicitudes. Intenta nuevamente mas tarde.',
            $response->getData(true)['message']
        );
    }

    public function test_malformed_request_uses_safe_400_without_parser_details(): void
    {
        Route::post('/api/error-contract/malformed-json', function (Request $request) {
            throw new BadRequestHttpException('JSON parser syntax error at byte 9.');
        });

        $response = $this->call(
            'POST',
            '/api/error-contract/malformed-json',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '{"email":'
        );

        $response->assertStatus(400)
            ->assertExactJson([
                'ok' => false,
                'message' => 'La solicitud no es valida.',
                'errors' => [],
            ]);
        $this->assertStringNotContainsString('syntax', strtolower($response->getContent()));
        $this->assertStringNotContainsString('json', strtolower($response->getContent()));
    }

    public function test_validation_exception_keeps_field_errors_with_422(): void
    {
        $response = ApiErrorResponse::fromException(
            ValidationException::withMessages([
                'email' => ['El correo es obligatorio.'],
            ])
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'ok' => false,
            'message' => 'Los datos enviados no son validos.',
            'errors' => [
                'email' => ['El correo es obligatorio.'],
            ],
        ], $response->getData(true));
    }

    public function test_crud_style_validation_keeps_all_field_errors(): void
    {
        $validator = Validator::make([
            'age' => 121,
            'timezone' => 'not-a-timezone',
        ], [
            'age' => ['integer', 'max:120'],
            'timezone' => ['timezone'],
        ]);

        $response = ApiErrorResponse::fromException(
            new ValidationException($validator)
        );

        $responseData = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('age', $responseData['errors']);
        $this->assertArrayHasKey('timezone', $responseData['errors']);
        $this->assertStringContainsString('"errors":{', $response->getContent());
    }

    public function test_business_http_errors_keep_readable_messages(): void
    {
        foreach ([
            401 => 'Token invalido o expirado.',
            403 => 'No tienes permiso para consultar este paciente.',
            404 => 'Paciente no encontrado.',
            409 => 'El paciente ya esta vinculado.',
            422 => 'El campo status no es valido.',
        ] as $status => $message) {
            $response = ApiErrorResponse::fromException(
                new HttpException($status, $message)
            );

            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame($message, $response->getData(true)['message']);
            $this->assertSame([], $response->getData(true)['errors']);
        }
    }
}
