<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class AIService
{
    public function askSupervisorAssistant(string $question, array $context): array
    {
        $apiKey = trim((string) config('ai.api_key'));
        $baseUrl = trim((string) config('ai.base_url'));
        $model = trim((string) config('ai.model'));

        if ($apiKey === '' || $baseUrl === '' || $model === '') {
            return $this->failure('configuration_missing', false);
        }

        try {
            $response = Http::connectTimeout(10)
                ->timeout(45)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('ai.site_url'),
                    'X-Title' => config('ai.app_name'),
                ])
                ->post($baseUrl, [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->buildSystemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->buildUserPrompt($question, $context),
                        ],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 700,
                ]);

            if ($response->status() === 429) {
                return $this->failure('provider_rate_limited', true);
            }

            if (in_array($response->status(), [408, 504], true)) {
                return $this->failure('provider_timeout', true);
            }

            if (! $response->successful()) {
                return $this->failure('provider_unavailable', $response->serverError());
            }

            $answer = trim((string) ($response->json('choices.0.message.content') ?? ''));

            if ($answer === '') {
                return $this->failure('empty_response', true);
            }

            return [
                'ok' => true,
                'answer' => $answer,
                'model' => $model,
                'usage' => $this->safeUsage($response->json('usage')),
            ];
        } catch (ConnectionException) {
            return $this->failure('provider_timeout', true);
        } catch (Throwable) {
            return $this->failure('provider_unavailable', true);
        }
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres un asistente de apoyo para supervisores de RehabiAnex.

Usa únicamente el contexto minimizado entregado por el backend.

Reglas obligatorias:
- No realices diagnósticos ni afirmes que una persona tiene una enfermedad.
- No prescribas medicamentos, tratamientos ni cambios de dosis.
- No proporciones terapia ni simules una sesión terapéutica.
- No sustituyas atención médica, psicológica, de emergencia ni criterio profesional.
- No inventes datos ni identidades.
- No intentes reidentificar referencias como P-001.
- No solicites teléfonos, correos, nombres, contactos de apoyo ni acceso a Firebase.
- No repitas posibles datos personales incluidos accidentalmente en la pregunta.
- Si falta información, indícalo.
- Ante señales graves, recomienda aplicar el protocolo profesional o de emergencia correspondiente.

Incluye cuando corresponda: "Esto no representa un diagnóstico; es un resumen de registros reportados por el usuario."
PROMPT;
    }

    private function buildUserPrompt(string $question, array $context): string
    {
        $jsonContext = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return <<<PROMPT
Pregunta del supervisor:
{$question}

Contexto autorizado, minimizado y seudonimizado:
{$jsonContext}

Responde únicamente con base en este contexto.
PROMPT;
    }

    private function failure(string $code, bool $retryable): array
    {
        return [
            'ok' => false,
            'answer' => null,
            'error_code' => $code,
            'retryable' => $retryable,
        ];
    }

    private function safeUsage(mixed $usage): ?array
    {
        if (! is_array($usage)) {
            return null;
        }

        return array_intersect_key($usage, array_flip([
            'prompt_tokens',
            'completion_tokens',
            'total_tokens',
        ]));
    }
}
