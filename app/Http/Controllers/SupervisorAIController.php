<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiErrorResponse;
use App\Rules\NonBlankText;
use App\Services\AIService;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SupervisorAIController extends Controller
{
    protected $db;

    protected $ai;

    public function __construct(
        FirebaseService $firebase,
        AIService $ai,
        private FirestoreAccessService $access
    ) {
        $this->db = $firebase->db();
        $this->ai = $ai;
    }

    public function chat(Request $request)
    {
        if ($this->access->role($request) !== 'supervisor') {
            abort(403, 'Solo los supervisores pueden usar este endpoint.');
        }

        $validator = Validator::make($request->all(), [
            'supervisor_uid' => ['required', 'string', 'max:128'],
            'question' => [
                'required',
                'string',
                new NonBlankText,
                'max:'.max(1, (int) config('ai.question_max_chars', 800)),
            ],
            'mode' => ['nullable', 'in:ai,local'],
        ]);

        if ($validator->fails()) {
            return ApiErrorResponse::make(
                'Los datos enviados no son validos.',
                422,
                $validator->errors()->toArray()
            );
        }

        $data = $validator->validated();
        $supervisorUid = $this->access->uid($request);

        if ($data['supervisor_uid'] !== $supervisorUid) {
            abort(403, 'supervisor_uid no coincide con la sesion autenticada.');
        }

        $this->access->assertAuthorizedSupervisor($supervisorUid);

        $question = $this->redactFreeText($data['question']);
        $mode = $data['mode'] ?? 'ai';
        $patients = $this->getAuthorizedPatients($supervisorUid);
        $question = $this->redactPatientIdentifiers($question, $patients);
        $question = $this->sanitizeExternalQuestion($question);
        $patientUids = array_column($patients, 'uid');
        $period = $this->contextPeriod();

        $notes = $this->getNotesForPatients($patientUids, $period);

        $context = $this->buildAIContext($supervisorUid, $patients, $notes, $period);
        $publicContext = $this->publicContext($context);
        $quota = $this->providerQuota($supervisorUid);

        if ($mode === 'local') {
            $answer = $this->generateSafeLocalAnswer(strtolower($question), $context);

            return response()->json([
                'ok' => true,
                'mode' => 'local',
                'supervisor_uid' => $supervisorUid,
                'question' => $question,
                'answer' => $answer,
                'context' => $publicContext,
                'quota' => $quota,
                ...$this->disabledHistoryState(),
            ]);
        }

        if (($safetyAnswer = $this->clinicalSafetyAnswer($question)) !== null) {
            return response()->json([
                'ok' => true,
                'mode' => 'local',
                'message' => 'La solicitud se respondió localmente por seguridad clínica.',
                'supervisor_uid' => $supervisorUid,
                'question' => $question,
                'answer' => $safetyAnswer,
                'context' => $publicContext,
                'quota' => $quota,
                'safety_intervention' => 'clinical_boundary',
                ...$this->disabledHistoryState(),
            ]);
        }

        if ($quota['remaining'] <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Se alcanzó el límite diario de consultas al proveedor de IA.',
                'errors' => (object) [],
                'retry_after_seconds' => $this->providerQuotaRetryAfter($supervisorUid),
                'quota' => $quota,
            ], 429, [
                'Retry-After' => (string) $this->providerQuotaRetryAfter($supervisorUid),
            ]);
        }

        RateLimiter::hit($this->providerQuotaKey($supervisorUid), 86400);
        $quota = $this->providerQuota($supervisorUid);

        $aiResponse = $this->ai->askSupervisorAssistant($question, $context);

        if (! $aiResponse['ok']) {
            if (($aiResponse['error_code'] ?? null) === 'configuration_missing') {
                RateLimiter::decrement($this->providerQuotaKey($supervisorUid));
                $quota = $this->providerQuota($supervisorUid);
            }

            $fallbackAnswer = $this->generateSafeLocalAnswer(strtolower($question), $context);

            return response()->json([
                'ok' => true,
                'mode' => 'fallback_local',
                'message' => 'El proveedor de IA no está disponible. Se usó una respuesta local segura.',
                'provider_error' => [
                    'code' => $aiResponse['error_code'] ?? 'provider_unavailable',
                    'retryable' => (bool) ($aiResponse['retryable'] ?? false),
                ],
                'supervisor_uid' => $supervisorUid,
                'question' => $question,
                'answer' => $fallbackAnswer,
                'context' => $publicContext,
                'quota' => $quota,
                ...$this->disabledHistoryState(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'ai',
            'supervisor_uid' => $supervisorUid,
            'question' => $question,
            'answer' => $aiResponse['answer'],
            'model' => $aiResponse['model'] ?? null,
            'usage' => $aiResponse['usage'] ?? null,
            'context' => $publicContext,
            'quota' => $quota,
            ...$this->disabledHistoryState(),
        ]);
    }

    public function eligibility(Request $request)
    {
        if ($this->access->role($request) !== 'supervisor') {
            abort(403, 'Solo los supervisores pueden usar este endpoint.');
        }

        $supervisorUid = $this->access->uid($request);
        $this->access->assertAuthorizedSupervisor($supervisorUid);
        $eligiblePatients = $this->getAuthorizedPatients($supervisorUid);
        $count = count($eligiblePatients);

        return response()->json([
            'ok' => true,
            'eligible' => $count > 0,
            'eligible_patients_count' => $count,
            'required_scope' => 'ai_chat_summary',
        ]);
    }

    private function disabledHistoryState(): array
    {
        return [
            'stored' => false,
            'session_id' => null,
            'history_persistence' => 'disabled',
        ];
    }

    private function getAuthorizedPatients($supervisorUid)
    {
        $documents = $this->db
            ->collection('patients')
            ->where('wants_supervision', '=', true)
            ->where('supervisor_uid', '=', $supervisorUid)
            ->documents();

        $patients = [];

        foreach ($documents as $document) {
            if ($document->exists()) {
                $patient = $document->data();
                $patientUid = (string) ($patient['uid'] ?? $document->id());

                if ($this->access->supervisorCanAccessPatient(
                    $supervisorUid,
                    $patientUid,
                    ['ai_chat_summary']
                )) {
                    $patient['uid'] = $patientUid;
                    $patients[] = $patient;
                }
            }
        }

        return $patients;
    }

    private function getNotesForPatients($patientUids, array $period)
    {
        $notes = [];

        foreach ($patientUids as $patientUid) {
            $documents = $this->db
                ->collection('patient_notes')
                ->where('patient_uid', '=', $patientUid)
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $note = $document->data();

                    if ($this->isWithinContextPeriod(
                        $note['created_at'] ?? null,
                        $period['start_at'],
                        $period['end_at']
                    )) {
                        $notes[] = $note;
                    }
                }
            }
        }

        usort($notes, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return array_slice($notes, 0, max(1, (int) config('ai.context_max_notes', 20)));
    }

    private function buildAIContext($supervisorUid, $patients, $notes, ?array $period = null)
    {
        $period ??= $this->contextPeriod();
        $patientsByUid = [];
        $patientNumber = 0;

        foreach ($patients as $patient) {
            $patientNumber++;
            $patientsByUid[$patient['uid']] = [
                'patient_ref' => sprintf('P-%03d', $patientNumber),
                'recovery_days' => $this->recoveryDays($patient['sobriety_start_date'] ?? null),
            ];
        }

        $cleanNotes = [];

        foreach ($notes as $note) {
            $patientUid = $note['patient_uid'] ?? null;

            if (! $patientUid || ! isset($patientsByUid[$patientUid])) {
                continue;
            }

            $cleanNotes[] = [
                'patient_ref' => $patientsByUid[$patientUid]['patient_ref'],
                'mood_score' => $note['mood_score'] ?? null,
                'anxiety_level' => $note['anxiety_level'] ?? null,
                'craving_level' => $note['craving_level'] ?? null,
                'energy_level' => $note['energy_level'] ?? null,
                'sleep_quality' => $note['sleep_quality'] ?? null,
                'had_relapse' => $note['had_relapse'] ?? false,
                'ai_risk_score' => $note['ai_risk_score'] ?? null,
                'ai_risk_level' => $note['ai_risk_level'] ?? null,
                'days_ago' => $this->daysAgo($note['created_at'] ?? null),
            ];
        }

        return [
            'app' => 'RehabiAnex',
            'privacy_rule' => 'Contexto minimizado de pacientes vinculados con consentimiento activo para ai_chat_summary.',
            'authorized_patients_count' => count($patientsByUid),
            'notes_count' => count($cleanNotes),
            'period' => $this->publicPeriod($period),
            'data_limits' => $this->contextDataLimits(),
            'patients' => array_values($patientsByUid),
            'recent_notes' => $cleanNotes,
        ];
    }

    private function redactFreeText(string $text): string
    {
        $text = preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '[correo omitido]',
            $text
        ) ?? $text;

        return preg_replace(
            '/(?<!\w)(?:\+?\d[\d\s().\-]{7,}\d)(?!\w)/u',
            '[teléfono omitido]',
            $text
        ) ?? $text;
    }

    private function redactPatientIdentifiers(string $text, array $patients): string
    {
        foreach (array_values($patients) as $index => $patient) {
            $reference = sprintf('P-%03d', $index + 1);

            foreach ([
                $patient['uid'] ?? null,
                $patient['full_name'] ?? null,
                $patient['nickname'] ?? null,
            ] as $identifier) {
                if (is_string($identifier) && trim($identifier) !== '') {
                    $text = str_ireplace($identifier, $reference, $text);
                }
            }
        }

        return $text;
    }

    private function sanitizeExternalQuestion(string $text): string
    {
        $text = $this->redactFreeText($text);
        $text = preg_replace(
            '/\b(?:me llamo|se llama|nombre(?:\s+del\s+paciente)?\s*[:=]?)\s+[\p{L}][\p{L}\s.\'\-]{1,80}/iu',
            '[nombre omitido]',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/(?<![\w-])[A-Za-z0-9_-]{20,128}(?![\w-])/u',
            '[identificador omitido]',
            $text
        ) ?? $text;

        return preg_replace(
            '/\b(?:calle|avenida|av\.?|boulevard|blvd\.?|carretera|privada|domicilio|colonia|fraccionamiento)\b[^\n,.;]{2,120}/iu',
            '[direccion omitida]',
            $text
        ) ?? $text;
    }

    private function recoveryDays(mixed $date): ?int
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return max(0, Carbon::parse($date)->startOfDay()->diffInDays(now()->startOfDay()));
        } catch (Throwable) {
            return null;
        }
    }

    private function daysAgo(mixed $date): ?int
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return max(0, Carbon::parse($date)->diffInDays(now()));
        } catch (Throwable) {
            return null;
        }
    }

    private function generateSafeLocalAnswer(string $question, array $context): string
    {
        $notes = $context['recent_notes'] ?? [];
        $patients = (int) ($context['authorized_patients_count'] ?? 0);

        if ($patients === 0) {
            return 'No hay pacientes vinculados con consentimiento activo para el resumen de IA.';
        }

        if ($notes === []) {
            return 'No hay registros autorreportados disponibles en el periodo consultado. '
                .'La ausencia de datos no permite inferir recaída, diagnóstico ni estado clínico.';
        }

        $highAnxiety = count(array_filter(
            $notes,
            fn (array $note): bool => (int) ($note['anxiety_level'] ?? 0) >= 7
        ));
        $highCraving = count(array_filter(
            $notes,
            fn (array $note): bool => (int) ($note['craving_level'] ?? 0) >= 7
        ));
        $highRisk = count(array_filter(
            $notes,
            fn (array $note): bool => in_array(
                $note['ai_risk_level'] ?? null,
                ['high', 'critical'],
                true
            )
        ));

        return "Resumen local seguro: {$patients} pacientes autorizados, "
            .count($notes)." registros recientes, {$highAnxiety} con ansiedad alta, "
            ."{$highCraving} con craving alto y {$highRisk} con señales de riesgo alto. "
            .'Esto no es diagnóstico, prescripción ni terapia y no sustituye atención profesional.';
    }

    private function contextPeriod(): array
    {
        $timezone = (string) config('ai.context_timezone', 'America/Mexico_City');
        $days = max(1, (int) config('ai.context_days', 7));
        $end = now($timezone);
        $start = $end->copy()->startOfDay()->subDays($days - 1);

        return [
            'start_at' => $start,
            'end_at' => $end,
            'timezone' => $timezone,
            'days' => $days,
        ];
    }

    private function publicPeriod(array $period): array
    {
        return [
            'start_date' => $period['start_at']->toDateString(),
            'end_date' => $period['end_at']->toDateString(),
            'timezone' => $period['timezone'],
            'label' => sprintf('Últimos %d días naturales', $period['days']),
        ];
    }

    private function contextDataLimits(): array
    {
        return [
            sprintf(
                'Se incluyen como máximo %d registros autorreportados del periodo indicado.',
                max(1, (int) config('ai.context_max_notes', 20))
            ),
            'Los datos faltantes no se interpretan como recaída ni como estado clínico.',
            'Las referencias de pacientes son efímeras y no equivalen a anonimización garantizada.',
        ];
    }

    private function publicContext(array $context): array
    {
        return [
            'authorized_patients_count' => (int) ($context['authorized_patients_count'] ?? 0),
            'notes_count' => (int) ($context['notes_count'] ?? 0),
            'period' => $context['period'] ?? null,
            'data_limits' => $context['data_limits'] ?? [],
        ];
    }

    private function isWithinContextPeriod(
        mixed $date,
        Carbon $periodStart,
        ?Carbon $periodEnd = null
    ): bool {
        if (! is_string($date) || trim($date) === '') {
            return false;
        }

        try {
            $dateTime = Carbon::parse($date);
            $end = ($periodEnd ?? now($periodStart->timezone))->copy()->utc();

            return $dateTime->betweenIncluded($periodStart->copy()->utc(), $end);
        } catch (Throwable) {
            return false;
        }
    }

    private function clinicalSafetyAnswer(string $question): ?string
    {
        $diagnosis = preg_match(
            '/\b(?:diagn[oó]stic\w*|qu[eé]\s+(?:tiene|padece)|determina(?:r)?\s+(?:la\s+)?enfermedad)\b/iu',
            $question
        ) === 1;
        $prescription = preg_match(
            '/\b(?:recet\w*|prescrib\w*|medicament\w*|f[aá]rmac\w*|dosis|dosificaci[oó]n|\d+(?:[.,]\d+)?\s*(?:mg|ml))\b/iu',
            $question
        ) === 1;
        $clinicalDecision = preg_match(
            '/\b(?:iniciar|suspender|cambiar|indicar|decidir)\b.{0,40}\b(?:tratamiento|terapia|medicamento|dosis)\b/iu',
            $question
        ) === 1;

        if (! $diagnosis && ! $prescription && ! $clinicalDecision) {
            return null;
        }

        return 'No puedo emitir diagnósticos, prescribir medicamentos, indicar dosis ni tomar decisiones clínicas. '
            .'Puedo resumir únicamente los registros autorreportados autorizados. '
            .'Para una valoración o decisión terapéutica, consulta a un profesional habilitado.';
    }

    private function providerQuotaKey(string $supervisorUid): string
    {
        return 'ai-provider-daily:'.$supervisorUid;
    }

    private function providerQuota(string $supervisorUid): array
    {
        $key = $this->providerQuotaKey($supervisorUid);
        $limit = max(1, (int) config('ai.provider_calls_per_day', 50));
        $retryAfter = RateLimiter::availableIn($key);

        return [
            'limit' => $limit,
            'remaining' => RateLimiter::remaining($key, $limit),
            'reset_at' => $retryAfter > 0 ? now()->addSeconds($retryAfter)->toIso8601String() : null,
            'window' => 'rolling_24_hours',
        ];
    }

    private function providerQuotaRetryAfter(string $supervisorUid): int
    {
        return max(1, RateLimiter::availableIn($this->providerQuotaKey($supervisorUid)));
    }

    private function generateLocalAnswer($question, $patients, $notes)
    {
        if (count($patients) === 0) {
            return 'No hay pacientes que hayan autorizado supervisión para este supervisor.';
        }

        if (str_contains($question, 'ansiedad') || str_contains($question, 'ansiosos')) {
            return $this->answerHighAnxiety($patients, $notes);
        }

        if (str_contains($question, 'craving') || str_contains($question, 'consumir')) {
            return $this->answerHighCraving($patients, $notes);
        }

        if (str_contains($question, 'riesgo') || str_contains($question, 'recaída') || str_contains($question, 'recaida')) {
            return $this->answerRiskPatients($patients, $notes);
        }

        return $this->answerGeneralSummary($patients, $notes);
    }

    private function answerHighAnxiety($patients, $notes)
    {
        $highNotes = array_filter($notes, function ($note) {
            return ($note['anxiety_level'] ?? 0) >= 7;
        });

        if (count($highNotes) === 0) {
            return 'No se encontraron pacientes supervisados con ansiedad alta en los registros disponibles.';
        }

        $lines = ['Pacientes con ansiedad alta detectada:'];

        foreach ($highNotes as $note) {
            $patient = $this->findPatient($patients, $note['patient_uid']);

            $name = $this->displayPatientName($patient);

            $lines[] = "- {$name}: ansiedad {$note['anxiety_level']}/10, craving {$note['craving_level']}/10. Nota: {$note['note_text']}";
        }

        $lines[] = 'Se recomienda revisar estos casos con prioridad, especialmente si la ansiedad aparece junto con craving elevado.';

        return implode("\n", $lines);
    }

    private function answerHighCraving($patients, $notes)
    {
        $highNotes = array_filter($notes, function ($note) {
            return ($note['craving_level'] ?? 0) >= 7;
        });

        if (count($highNotes) === 0) {
            return 'No se encontraron registros con craving alto entre los pacientes supervisados.';
        }

        $lines = ['Pacientes con craving alto detectado:'];

        foreach ($highNotes as $note) {
            $patient = $this->findPatient($patients, $note['patient_uid']);

            $name = $this->displayPatientName($patient);

            $lines[] = "- {$name}: craving {$note['craving_level']}/10, ansiedad {$note['anxiety_level']}/10. Detonantes: ".implode(', ', $note['triggers'] ?? []);
        }

        $lines[] = 'Estos registros pueden indicar momentos de mayor vulnerabilidad y conviene dar seguimiento cercano.';

        return implode("\n", $lines);
    }

    private function answerRiskPatients($patients, $notes)
    {
        $riskNotes = array_filter($notes, function ($note) {
            return in_array($note['ai_risk_level'] ?? 'low', ['high', 'critical']);
        });

        if (count($riskNotes) === 0) {
            return 'No se detectaron pacientes supervisados con riesgo alto o crítico en los registros actuales.';
        }

        $lines = ['Pacientes con posible riesgo elevado:'];

        foreach ($riskNotes as $note) {
            $patient = $this->findPatient($patients, $note['patient_uid']);

            $name = $this->displayPatientName($patient);

            $lines[] = "- {$name}: nivel {$note['ai_risk_level']}, puntaje {$note['ai_risk_score']}/100. Motivo reportado: {$note['note_text']}";
        }

        $lines[] = 'Esta respuesta no representa un diagnóstico. Solo resume señales registradas por los usuarios.';

        return implode("\n", $lines);
    }

    private function answerGeneralSummary($patients, $notes)
    {
        $totalPatients = count($patients);
        $totalNotes = count($notes);

        $highRisk = count(array_filter($notes, function ($note) {
            return ($note['ai_risk_level'] ?? '') === 'high';
        }));

        $criticalRisk = count(array_filter($notes, function ($note) {
            return ($note['ai_risk_level'] ?? '') === 'critical';
        }));

        return "Resumen general: tienes {$totalPatients} pacientes autorizados y {$totalNotes} notas registradas. Hay {$highRisk} registros con riesgo alto y {$criticalRisk} con riesgo crítico. Para mayor detalle puedes preguntar por ansiedad, craving o riesgo de recaída.";
    }

    private function findPatient($patients, $patientUid)
    {
        foreach ($patients as $patient) {
            if (($patient['uid'] ?? null) === $patientUid) {
                return $patient;
            }
        }

        return null;
    }

    private function displayPatientName($patient)
    {
        if (! $patient) {
            return 'Paciente desconocido';
        }

        if (($patient['is_anonymous'] ?? false) === true) {
            return 'Paciente anónimo '.($patient['uid'] ?? '');
        }

        return $patient['full_name'] ?? $patient['uid'];
    }
}
