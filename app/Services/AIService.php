<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AIService
{
    public function askSupervisorAssistant(string $question, array $context): array
    {
        $apiKey = config('ai.api_key');
        $baseUrl = config('ai.base_url');
        $model = config('ai.model');

        if (!$apiKey) {
            return [
                'ok' => false,
                'answer' => null,
                'error' => 'No se configuró AI_API_KEY en el archivo .env.',
            ];
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($question, $context);

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('ai.site_url'),
                    'X-Title' => config('ai.app_name'),
                ])
                ->post($baseUrl, [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt,
                        ],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 700,
                ]);

            if (!$response->successful()) {
                return [
                    'ok' => false,
                    'answer' => null,
                    'error' => 'Error al consultar el proveedor de IA.',
                    'details' => $response->json(),
                    'status' => $response->status(),
                ];
            }

            $data = $response->json();

            $answer = $data['choices'][0]['message']['content'] ?? null;

            if (!$answer) {
                return [
                    'ok' => false,
                    'answer' => null,
                    'error' => 'La IA no devolvió una respuesta válida.',
                    'details' => $data,
                ];
            }

            return [
                'ok' => true,
                'answer' => trim($answer),
                'model' => $model,
                'usage' => $data['usage'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'answer' => null,
                'error' => 'Excepción al consultar IA: ' . $e->getMessage(),
            ];
        }
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
Eres el asistente de apoyo para supervisores de RehabiAnex, una aplicación de acompañamiento para personas en recuperación por consumo de sustancias.

Tu función:
- Ayudar al Doctor, Padrino o Supervisor a interpretar registros autorizados de pacientes.
- Resumir señales emocionales como ansiedad, craving, estado de ánimo, sueño, detonantes y riesgo calculado.
- Responder de forma clara, breve y útil.

Reglas estrictas:
- No diagnostiques.
- No prescribas tratamientos.
- No inventes datos.
- No menciones pacientes que no aparezcan en el contexto.
- Si no hay datos suficientes, dilo claramente.
- Usa únicamente el contexto entregado por Laravel.
- No pidas acceso a bases de datos.
- No digas que puedes consultar Firebase directamente.
- No recomiendes medicamentos.
- No sustituyes terapia, atención médica ni intervención de emergencia.

Estilo de respuesta:
- Español claro.
- Tono profesional, humano y prudente.
- Organiza la respuesta en viñetas si ayuda.
- Señala prioridades de seguimiento cuando existan registros de riesgo alto o crítico.
- Incluye una advertencia breve cuando hables de riesgo: "Esto no representa un diagnóstico; es un resumen de registros reportados por el usuario."

Privacidad:
- Solo puedes hablar de los pacientes incluidos en el contexto.
- Si un paciente aparece como anónimo, no intentes identificarlo.
PROMPT;
    }

    private function buildUserPrompt(string $question, array $context): string
    {
        $jsonContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
Pregunta del supervisor:
{$question}

Contexto autorizado entregado por Laravel:
{$jsonContext}

Responde usando únicamente el contexto anterior.
PROMPT;
    }
}
