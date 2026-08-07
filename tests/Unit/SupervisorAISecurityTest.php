<?php

namespace Tests\Unit;

use App\Http\Controllers\SupervisorAIController;
use App\Services\AIService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class SupervisorAISecurityTest extends TestCase
{
    public function test_provider_context_is_minimized_and_pseudonymized(): void
    {
        $context = $this->controllerMethod('buildAIContext', [
            'supervisor-secret',
            [[
                'uid' => 'patient-secret',
                'full_name' => 'Nombre Privado',
                'email' => 'patient@example.com',
                'phone' => '+524491234567',
                'gender' => 'private',
                'sobriety_start_date' => now()->subDays(20)->toIso8601String(),
                'supervisor_uid' => 'supervisor-secret',
                'wants_supervision' => true,
            ]],
            [[
                'note_id' => 'note-secret',
                'patient_uid' => 'patient-secret',
                'mood' => 'Texto libre privado',
                'mood_score' => 4,
                'anxiety_level' => 8,
                'craving_level' => 7,
                'energy_level' => 3,
                'sleep_quality' => 2,
                'had_relapse' => false,
                'triggers' => ['Dato privado'],
                'note_text' => 'Correo patient@example.com y teléfono +524491234567',
                'ai_risk_score' => 72,
                'ai_risk_level' => 'high',
                'created_at' => now()->subDay()->toIso8601String(),
            ]],
        ]);
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->assertSame('P-001', $context['patients'][0]['patient_ref']);
        $this->assertSame(20, $context['patients'][0]['recovery_days']);
        $this->assertSame('P-001', $context['recent_notes'][0]['patient_ref']);
        $this->assertSame(8, $context['recent_notes'][0]['anxiety_level']);
        $this->assertStringNotContainsString('patient-secret', $encoded);
        $this->assertStringNotContainsString('supervisor-secret', $encoded);
        $this->assertStringNotContainsString('Nombre Privado', $encoded);
        $this->assertStringNotContainsString('patient@example.com', $encoded);
        $this->assertStringNotContainsString('+524491234567', $encoded);
        $this->assertStringNotContainsString('Texto libre privado', $encoded);
        $this->assertStringNotContainsString('Dato privado', $encoded);
    }

    public function test_question_redacts_email_and_phone(): void
    {
        $redacted = $this->controllerMethod('redactFreeText', [
            'Contacta a ana@example.com o al +52 449 123 4567.',
        ]);

        $this->assertSame(
            'Contacta a [correo omitido] o al [teléfono omitido].',
            $redacted
        );
    }

    public function test_question_replaces_patient_name_nickname_and_uid_with_reference(): void
    {
        $redacted = $this->controllerMethod('redactPatientIdentifiers', [
            'Resume a Ana López, Anita y patient-secret.',
            [[
                'uid' => 'patient-secret',
                'full_name' => 'Ana López',
                'nickname' => 'Anita',
            ]],
        ]);

        $this->assertSame('Resume a P-001, P-001 y P-001.', $redacted);
    }

    public function test_external_question_redacts_addresses_and_uid_like_identifiers(): void
    {
        $redacted = $this->controllerMethod('sanitizeExternalQuestion', [
            'Visita Calle Reforma 123, revisa abcdefghijklmnopqrstuvwxyz12.',
        ]);

        $this->assertStringNotContainsString('Calle Reforma 123', $redacted);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz12', $redacted);
        $this->assertStringContainsString('[direccion omitida]', $redacted);
        $this->assertStringContainsString('[identificador omitido]', $redacted);
    }

    public function test_external_question_redacts_basic_declared_name_patterns(): void
    {
        $redacted = $this->controllerMethod('sanitizeExternalQuestion', [
            'El paciente se llama Juan Perez y requiere un resumen.',
        ]);

        $this->assertStringNotContainsString('Juan Perez', $redacted);
        $this->assertStringContainsString('[nombre omitido]', $redacted);
    }

    public function test_controller_source_does_not_write_ai_history_collections(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SupervisorAIController.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("collection('ai_chat_sessions')", $source);
        $this->assertStringNotContainsString("collection('ai_chat_messages')", $source);
        $this->assertStringNotContainsString("'content' =>", $source);
    }

    public function test_system_prompt_contains_all_safety_restrictions(): void
    {
        $prompt = $this->serviceMethod('buildSystemPrompt');

        $this->assertStringContainsString('No realices diagnósticos', $prompt);
        $this->assertStringContainsString('No prescribas', $prompt);
        $this->assertStringContainsString('No proporciones terapia', $prompt);
        $this->assertStringContainsString('No sustituyas atención', $prompt);
        $this->assertStringContainsString('No intentes reidentificar', $prompt);
        $this->assertStringContainsString('protocolo profesional o de emergencia', $prompt);
    }

    public function test_missing_configuration_has_stable_safe_error(): void
    {
        config()->set('ai.api_key', null);

        $result = (new AIService())->askSupervisorAssistant('Pregunta', []);

        $this->assertSame([
            'ok' => false,
            'answer' => null,
            'error_code' => 'configuration_missing',
            'retryable' => false,
        ], $result);
    }

    public function test_provider_rate_limit_timeout_unavailable_and_empty_response_are_classified(): void
    {
        $this->configureProvider();

        Http::fakeSequence()
            ->push([], 429)
            ->push([], 504)
            ->push([], 503)
            ->push([
                'choices' => [['message' => ['content' => '   ']]],
            ], 200);

        $this->assertSame(
            'provider_rate_limited',
            (new AIService())->askSupervisorAssistant('Pregunta', [])['error_code']
        );

        $this->assertSame(
            'provider_timeout',
            (new AIService())->askSupervisorAssistant('Pregunta', [])['error_code']
        );

        $this->assertSame(
            'provider_unavailable',
            (new AIService())->askSupervisorAssistant('Pregunta', [])['error_code']
        );

        $this->assertSame(
            'empty_response',
            (new AIService())->askSupervisorAssistant('Pregunta', [])['error_code']
        );
    }

    public function test_provider_request_contains_only_safe_context_and_safe_usage_is_returned(): void
    {
        $this->configureProvider();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'Resumen seguro']]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
                'provider_internal' => 'hidden',
            ],
        ])]);
        $context = [
            'patients' => [['patient_ref' => 'P-001', 'recovery_days' => 20]],
            'recent_notes' => [[
                'patient_ref' => 'P-001',
                'anxiety_level' => 8,
                'ai_risk_level' => 'high',
            ]],
        ];

        $result = (new AIService())->askSupervisorAssistant('Resumen', $context);

        $this->assertTrue($result['ok']);
        $this->assertSame([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
        ], $result['usage']);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE);

            return str_contains($serialized, 'P-001')
                && str_contains($serialized, 'No realices diagnósticos')
                && ! str_contains($serialized, 'provider_internal')
                && ! str_contains($serialized, 'email')
                && ! str_contains($serialized, 'phone');
        });
    }

    private function controllerMethod(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass(SupervisorAIController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod($method)->invokeArgs($controller, $arguments);
    }

    private function serviceMethod(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass(AIService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod($method)->invokeArgs($service, $arguments);
    }

    private function configureProvider(): void
    {
        config()->set('ai.api_key', 'test-secret');
        config()->set('ai.base_url', 'https://provider.test/chat');
        config()->set('ai.model', 'test-model');
        config()->set('ai.site_url', 'https://rehabianex.test');
        config()->set('ai.app_name', 'RehabiAnex Tests');
    }
}
