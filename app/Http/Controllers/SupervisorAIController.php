<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiErrorResponse;
use App\Services\AIService;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
            'question' => ['required', 'string', 'max:3000'],
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

        $notes = $this->getNotesForPatients($patientUids);

        $context = $this->buildAIContext($supervisorUid, $patients, $notes);

        if ($mode === 'local') {
            $answer = $this->generateSafeLocalAnswer(strtolower($question), $context);

            return response()->json([
                'ok' => true,
                'mode' => 'local',
                'supervisor_uid' => $supervisorUid,
                'question' => $question,
                'answer' => $answer,
                'context' => [
                    'authorized_patients_count' => count($patients),
                    'notes_count' => count($notes),
                ],
                ...$this->disabledHistoryState(),
            ]);
        }

        $aiResponse = $this->ai->askSupervisorAssistant($question, $context);

        if (! $aiResponse['ok']) {
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
                'context' => [
                    'authorized_patients_count' => count($patients),
                    'notes_count' => count($notes),
                ],
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
            'context' => [
                'authorized_patients_count' => count($patients),
                'notes_count' => count($notes),
            ],
            ...$this->disabledHistoryState(),
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
                    $patients[] = $patient;
                }
            }
        }

        return $patients;
    }

    private function getNotesForPatients($patientUids)
    {
        $notes = [];

        foreach ($patientUids as $patientUid) {
            $documents = $this->db
                ->collection('patient_notes')
                ->where('patient_uid', '=', $patientUid)
                ->documents();

            foreach ($documents as $document) {
                if ($document->exists()) {
                    $notes[] = $document->data();
                }
            }
        }

        usort($notes, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return array_slice($notes, 0, 20);
    }

    private function buildAIContext($supervisorUid, $patients, $notes)
    {
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
