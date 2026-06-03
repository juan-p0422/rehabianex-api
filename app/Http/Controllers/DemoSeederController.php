<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Carbon\Carbon;

class DemoSeederController extends Controller
{
    protected $db;

    public function __construct(FirebaseService $firebase)
    {
        $this->db = $firebase->db();
    }

    public function seed()
    {
        $now = Carbon::now()->toIso8601String();

        $supervisor = [
            'uid' => 'supervisor_001',
            'role' => 'supervisor',
            'display_name' => 'Dr. Carlos Méndez',
            'email' => 'supervisor@test.com',
            'professional_type' => 'doctor',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $patientUser = [
            'uid' => 'patient_001',
            'role' => 'patient',
            'display_name' => 'Ana López',
            'email' => 'paciente@test.com',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $patientProfile = [
            'patient_id' => 'patient_001',
            'user_uid' => 'patient_001',
            'supervisor_uid' => 'supervisor_001',
            'full_name' => 'Ana López',
            'age' => 34,
            'gender' => 'female',
            'diagnosis' => 'Rehabilitación de rodilla',
            'risk_level' => 'medium',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $rehabPlan = [
            'plan_id' => 'plan_001',
            'patient_id' => 'patient_001',
            'supervisor_uid' => 'supervisor_001',
            'title' => 'Plan inicial de rehabilitación de rodilla',
            'objective' => 'Mejorar movilidad, fuerza y control del dolor.',
            'frequency_per_week' => 3,
            'duration_weeks' => 6,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $session = [
            'session_id' => 'session_001',
            'plan_id' => 'plan_001',
            'patient_id' => 'patient_001',
            'scheduled_date' => $now,
            'status' => 'pending',
            'pain_before' => null,
            'pain_after' => null,
            'notes' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $exerciseLog = [
            'log_id' => 'log_001',
            'session_id' => 'session_001',
            'patient_id' => 'patient_001',
            'exercise_name' => 'Elevación de pierna recta',
            'sets' => 3,
            'repetitions' => 10,
            'pain_level' => 2,
            'completed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $aiRecommendation = [
            'recommendation_id' => 'recommendation_001',
            'patient_id' => 'patient_001',
            'session_id' => 'session_001',
            'type' => 'rehab_adjustment',
            'summary' => 'Mantener intensidad moderada y monitorear dolor posterior a la sesión.',
            'risk_alert' => false,
            'generated_by' => 'demo_ai',
            'created_at' => $now,
        ];

        $this->db->collection('users')->document('supervisor_001')->set($supervisor);
        $this->db->collection('users')->document('patient_001')->set($patientUser);
        $this->db->collection('patients')->document('patient_001')->set($patientProfile);
        $this->db->collection('rehab_plans')->document('plan_001')->set($rehabPlan);
        $this->db->collection('sessions')->document('session_001')->set($session);
        $this->db->collection('exercise_logs')->document('log_001')->set($exerciseLog);
        $this->db->collection('ai_recommendations')->document('recommendation_001')->set($aiRecommendation);

        return response()->json([
            'ok' => true,
            'message' => 'Datos demo creados correctamente en Firestore.',
            'created' => [
                'users' => ['supervisor_001', 'patient_001'],
                'patients' => ['patient_001'],
                'rehab_plans' => ['plan_001'],
                'sessions' => ['session_001'],
                'exercise_logs' => ['log_001'],
                'ai_recommendations' => ['recommendation_001'],
            ],
        ]);
    }
}
