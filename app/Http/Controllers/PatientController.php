<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PatientController extends Controller
{
    protected $db;

    public function __construct(FirebaseService $firebase)
    {
        $this->db = $firebase->db();
    }

    public function supervisedPatients($supervisorUid)
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

        return response()->json([
            'ok' => true,
            'supervisor_uid' => $supervisorUid,
            'patients' => $patients,
        ]);
    }

    public function show($patientUid)
    {
        $document = $this->db
            ->collection('patients')
            ->document($patientUid)
            ->snapshot();

        if (!$document->exists()) {
            return response()->json([
                'ok' => false,
                'message' => 'Paciente no encontrado.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'patient' => $document->data(),
        ]);
    }

    public function notes($patientUid)
    {
        $documents = $this->db
            ->collection('patient_notes')
            ->where('patient_uid', '=', $patientUid)
            ->documents();

        $notes = [];

        foreach ($documents as $document) {
            if ($document->exists()) {
                $notes[] = $document->data();
            }
        }

        return response()->json([
            'ok' => true,
            'patient_uid' => $patientUid,
            'notes' => $notes,
        ]);
    }

    public function storeNote(Request $request, $patientUid)
    {
        $data = $request->json()->all();

        if (empty($data)) {
            $data = $request->all();
        }

        $now = Carbon::now()->toIso8601String();
        $noteId = 'note_' . uniqid();

        $anxiety = (int) ($data['anxiety_level'] ?? 0);
        $craving = (int) ($data['craving_level'] ?? 0);
        $moodScore = (int) ($data['mood_score'] ?? 5);
        $hadRelapse = (bool) ($data['had_relapse'] ?? false);

        $riskScore = $this->calculateRiskScore($anxiety, $craving, $moodScore, $hadRelapse);
        $riskLevel = $this->calculateRiskLevel($riskScore);

        $note = [
            'note_id' => $noteId,
            'patient_uid' => $patientUid,
            'mood' => $data['mood'] ?? 'sin especificar',
            'mood_score' => $moodScore,
            'anxiety_level' => $anxiety,
            'craving_level' => $craving,
            'energy_level' => (int) ($data['energy_level'] ?? 5),
            'sleep_quality' => (int) ($data['sleep_quality'] ?? 5),
            'had_relapse' => $hadRelapse,
            'triggers' => $data['triggers'] ?? [],
            'note_text' => $data['note_text'] ?? '',
            'ai_risk_score' => $riskScore,
            'ai_risk_level' => $riskLevel,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->db
            ->collection('patient_notes')
            ->document($noteId)
            ->set($note);

        return response()->json([
            'ok' => true,
            'message' => 'Nota creada correctamente.',
            'note' => $note,
        ], 201);
    }

    private function calculateRiskScore($anxiety, $craving, $moodScore, $hadRelapse)
    {
        $risk = 0;

        $risk += $anxiety * 4;
        $risk += $craving * 5;
        $risk += (10 - $moodScore) * 3;

        if ($hadRelapse) {
            $risk += 30;
        }

        return min($risk, 100);
    }

    private function calculateRiskLevel($riskScore)
    {
        if ($riskScore >= 85) {
            return 'critical';
        }

        if ($riskScore >= 65) {
            return 'high';
        }

        if ($riskScore >= 40) {
            return 'medium';
        }

        return 'low';
    }
}
