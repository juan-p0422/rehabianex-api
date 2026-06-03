<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use App\Services\AIService;
use Illuminate\Http\Request;

class SupervisorAIController extends Controller
{
    protected $db;
    protected $ai;

    public function __construct(FirebaseService $firebase, AIService $ai)
    {
        $this->db = $firebase->db();
        $this->ai = $ai;
    }

    public function chat(Request $request)
    {
        $data = $request->json()->all();

        if (empty($data)) {
            $data = $request->all();
        }

        $supervisorUid = $data['supervisor_uid'] ?? null;
        $question = $data['question'] ?? null;
        $mode = $data['mode'] ?? 'ai';

        if (!$supervisorUid || !$question) {
            return response()->json([
                'ok' => false,
                'message' => 'Debes enviar supervisor_uid y question.',
                'received' => $data,
            ], 422);
        }

        $patients = $this->getAuthorizedPatients($supervisorUid);
        $patientUids = array_column($patients, 'uid');

        $notes = $this->getNotesForPatients($patientUids);

        $context = $this->buildAIContext($supervisorUid, $patients, $notes);

        if ($mode === 'local') {
            $answer = $this->generateLocalAnswer(strtolower($question), $patients, $notes);

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
                'stored' => false,
            ]);
        }

        $aiResponse = $this->ai->askSupervisorAssistant($question, $context);

        if (!$aiResponse['ok']) {
            $fallbackAnswer = $this->generateLocalAnswer(strtolower($question), $patients, $notes);

            return response()->json([
                'ok' => true,
                'mode' => 'fallback_local',
                'message' => 'La IA no respondió correctamente. Se usó respuesta local.',
                'ai_error' => $aiResponse,
                'supervisor_uid' => $supervisorUid,
                'question' => $question,
                'answer' => $fallbackAnswer,
                'context' => [
                    'authorized_patients_count' => count($patients),
                    'notes_count' => count($notes),
                ],
                'stored' => false,
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
            'stored' => false,
        ]);
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
                $patients[] = $document->data();
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

        foreach ($patients as $patient) {
            $patientsByUid[$patient['uid']] = [
                'uid' => $patient['uid'],
                'display_name' => $this->displayPatientName($patient),
                'age' => $patient['age'] ?? null,
                'gender' => $patient['gender'] ?? null,
                'sobriety_start_date' => $patient['sobriety_start_date'] ?? null,
                'is_anonymous' => $patient['is_anonymous'] ?? false,
                'wants_supervision' => $patient['wants_supervision'] ?? false,
                'supervisor_uid' => $patient['supervisor_uid'] ?? null,
                'status' => $patient['status'] ?? null,
            ];
        }

        $cleanNotes = [];

        foreach ($notes as $note) {
            $patientUid = $note['patient_uid'] ?? null;

            if (!$patientUid || !isset($patientsByUid[$patientUid])) {
                continue;
            }

            $cleanNotes[] = [
                'note_id' => $note['note_id'] ?? null,
                'patient_uid' => $patientUid,
                'patient_display_name' => $patientsByUid[$patientUid]['display_name'],
                'mood' => $note['mood'] ?? null,
                'mood_score' => $note['mood_score'] ?? null,
                'anxiety_level' => $note['anxiety_level'] ?? null,
                'craving_level' => $note['craving_level'] ?? null,
                'energy_level' => $note['energy_level'] ?? null,
                'sleep_quality' => $note['sleep_quality'] ?? null,
                'had_relapse' => $note['had_relapse'] ?? false,
                'triggers' => $note['triggers'] ?? [],
                'note_text' => $note['note_text'] ?? '',
                'ai_risk_score' => $note['ai_risk_score'] ?? null,
                'ai_risk_level' => $note['ai_risk_level'] ?? null,
                'created_at' => $note['created_at'] ?? null,
            ];
        }

        return [
            'app' => 'RehabiAnex',
            'supervisor_uid' => $supervisorUid,
            'privacy_rule' => 'El supervisor solo puede consultar pacientes con wants_supervision=true y supervisor_uid igual al supervisor autenticado.',
            'authorized_patients_count' => count($patientsByUid),
            'notes_count' => count($cleanNotes),
            'patients' => array_values($patientsByUid),
            'recent_notes' => $cleanNotes,
        ];
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

            $lines[] = "- {$name}: craving {$note['craving_level']}/10, ansiedad {$note['anxiety_level']}/10. Detonantes: " . implode(', ', $note['triggers'] ?? []);
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
        if (!$patient) {
            return 'Paciente desconocido';
        }

        if (($patient['is_anonymous'] ?? false) === true) {
            return 'Paciente anónimo ' . ($patient['uid'] ?? '');
        }

        return $patient['full_name'] ?? $patient['uid'];
    }
}
