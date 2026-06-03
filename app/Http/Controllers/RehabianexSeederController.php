<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Carbon\Carbon;

class RehabianexSeederController extends Controller
{
    protected $db;

    public function __construct(FirebaseService $firebase)
    {
        $this->db = $firebase->db();
    }

    public function seed()
    {
        $now = Carbon::now()->toIso8601String();

        $supervisors = [
            [
                'uid' => 'supervisor_001',
                'full_name' => 'Dr. Carlos Méndez',
                'email' => 'supervisor1@test.com',
                'supervisor_type' => 'doctor',
                'phone' => '4491234567',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'uid' => 'supervisor_002',
                'full_name' => 'Padrino Miguel Herrera',
                'email' => 'supervisor2@test.com',
                'supervisor_type' => 'padrino',
                'phone' => '4497654321',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $patients = [
            [
                'uid' => 'patient_001',
                'full_name' => 'Ana López',
                'email' => 'paciente1@test.com',
                'age' => 29,
                'gender' => 'female',
                'sobriety_start_date' => '2026-05-01',
                'is_anonymous' => false,
                'wants_supervision' => true,
                'supervisor_uid' => 'supervisor_001',
                'supervision_accepted_at' => $now,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'uid' => 'patient_002',
                'full_name' => 'Luis Ramírez',
                'email' => 'paciente2@test.com',
                'age' => 35,
                'gender' => 'male',
                'sobriety_start_date' => '2026-04-15',
                'is_anonymous' => false,
                'wants_supervision' => true,
                'supervisor_uid' => 'supervisor_001',
                'supervision_accepted_at' => $now,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'uid' => 'patient_003',
                'full_name' => 'Usuario Anónimo 003',
                'email' => 'anonimo3@test.com',
                'age' => 24,
                'gender' => 'other',
                'sobriety_start_date' => '2026-05-20',
                'is_anonymous' => true,
                'wants_supervision' => false,
                'supervisor_uid' => null,
                'supervision_accepted_at' => null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'uid' => 'patient_004',
                'full_name' => 'María Torres',
                'email' => 'paciente4@test.com',
                'age' => 41,
                'gender' => 'female',
                'sobriety_start_date' => '2026-03-10',
                'is_anonymous' => false,
                'wants_supervision' => true,
                'supervisor_uid' => 'supervisor_002',
                'supervision_accepted_at' => $now,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $notes = [
            [
                'note_id' => 'note_001',
                'patient_uid' => 'patient_001',
                'mood' => 'ansiosa',
                'mood_score' => 4,
                'anxiety_level' => 8,
                'craving_level' => 7,
                'energy_level' => 4,
                'sleep_quality' => 3,
                'had_relapse' => false,
                'triggers' => ['soledad', 'noche', 'discusión familiar'],
                'note_text' => 'Hoy me sentí ansiosa por la noche y tuve ganas de consumir.',
                'ai_risk_score' => 78,
                'ai_risk_level' => 'high',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'note_id' => 'note_002',
                'patient_uid' => 'patient_001',
                'mood' => 'triste',
                'mood_score' => 5,
                'anxiety_level' => 6,
                'craving_level' => 5,
                'energy_level' => 5,
                'sleep_quality' => 4,
                'had_relapse' => false,
                'triggers' => ['recuerdos', 'estrés'],
                'note_text' => 'Me sentí triste, pero logré hablar con una persona de confianza.',
                'ai_risk_score' => 55,
                'ai_risk_level' => 'medium',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'note_id' => 'note_003',
                'patient_uid' => 'patient_002',
                'mood' => 'irritable',
                'mood_score' => 3,
                'anxiety_level' => 9,
                'craving_level' => 8,
                'energy_level' => 3,
                'sleep_quality' => 2,
                'had_relapse' => false,
                'triggers' => ['enojo', 'problemas laborales'],
                'note_text' => 'Tuve mucho enojo después del trabajo y pensé en consumir.',
                'ai_risk_score' => 86,
                'ai_risk_level' => 'critical',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'note_id' => 'note_004',
                'patient_uid' => 'patient_003',
                'mood' => 'estable',
                'mood_score' => 7,
                'anxiety_level' => 3,
                'craving_level' => 2,
                'energy_level' => 7,
                'sleep_quality' => 8,
                'had_relapse' => false,
                'triggers' => [],
                'note_text' => 'Día tranquilo, sin ganas fuertes de consumir.',
                'ai_risk_score' => 20,
                'ai_risk_level' => 'low',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'note_id' => 'note_005',
                'patient_uid' => 'patient_004',
                'mood' => 'preocupada',
                'mood_score' => 5,
                'anxiety_level' => 7,
                'craving_level' => 6,
                'energy_level' => 4,
                'sleep_quality' => 3,
                'had_relapse' => false,
                'triggers' => ['familia', 'dinero'],
                'note_text' => 'Me preocupan temas económicos y eso me movió emocionalmente.',
                'ai_risk_score' => 62,
                'ai_risk_level' => 'medium',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($supervisors as $supervisor) {
            $this->db->collection('supervisors')->document($supervisor['uid'])->set($supervisor);
        }

        foreach ($patients as $patient) {
            $this->db->collection('patients')->document($patient['uid'])->set($patient);
        }

        foreach ($notes as $note) {
            $this->db->collection('patient_notes')->document($note['note_id'])->set($note);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Base demo de RehabiAnex creada correctamente.',
            'collections' => [
                'supervisors' => count($supervisors),
                'patients' => count($patients),
                'patient_notes' => count($notes),
            ],
        ]);
    }
}
