<?php

namespace App\Console\Commands;

use App\Services\FirebaseService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SeedFirestore extends Command
{
    protected $signature = 'rehabianex:seed-firestore';

    protected $description = 'Puebla Firebase Firestore con datos de prueba para RehabiAnex.';

    public function handle(FirebaseService $firebase): int
    {
        try {
            $db = $firebase->db();
            $documents = $this->documents();
            $created = 0;
            $failed = 0;

            $this->info('Iniciando poblado de Firestore para RehabiAnex...');

            foreach ($documents as $collection => $items) {
                foreach ($items as $documentId => $payload) {
                    try {
                        $db->collection($collection)->document($documentId)->set($payload);
                        $created++;

                        $this->line("Creado/actualizado: {$collection}/{$documentId}");
                    } catch (Throwable $e) {
                        $failed++;

                        $this->error("Error en {$collection}/{$documentId}: {$e->getMessage()}");
                    }
                }
            }

            if ($failed > 0) {
                $this->warn("Firestore poblado parcialmente. Documentos correctos: {$created}. Errores: {$failed}.");

                return self::FAILURE;
            }

            $this->info("Firestore poblado correctamente. Documentos creados/actualizados: {$created}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se pudo poblar Firestore: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function documents(): array
    {
        $now = $this->iso('2026-06-28 10:00:00');
        $yesterday = $this->iso('2026-06-27 19:30:00');
        $tomorrow = $this->iso('2026-06-29 18:00:00');
        $nextWeek = $this->iso('2026-07-05 11:00:00');

        return [
            'supervisors' => [
                'supervisor_001' => [
                    'uid' => 'supervisor_001',
                    'full_name' => 'Dr. Carlos Mendez',
                    'email' => 'carlos.mendez@rehabianex.test',
                    'phone' => '+524491234567',
                    'supervisor_type' => 'clinical_psychologist',
                    'license_number' => 'PSI-AGS-1024',
                    'authorized' => true,
                    'verified' => true,
                    'supervisor_code' => 'RA-4D23AF1B',
                    'status' => 'active',
                    'specialties' => ['ansiedad', 'prevencion de recaidas', 'craving'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'supervisor_002' => [
                    'uid' => 'supervisor_002',
                    'full_name' => 'Laura Rivera',
                    'email' => 'laura.rivera@rehabianex.test',
                    'phone' => '+524497654321',
                    'supervisor_type' => 'support_sponsor',
                    'license_number' => null,
                    'authorized' => true,
                    'verified' => true,
                    'supervisor_code' => 'RA-B7A88B77',
                    'status' => 'active',
                    'specialties' => ['reuniones', 'red de apoyo', 'seguimiento diario'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'patients' => [
                'patient_001' => [
                    'uid' => 'patient_001',
                    'full_name' => 'Ana Lopez',
                    'email' => 'ana.lopez@rehabianex.test',
                    'age' => 29,
                    'gender' => 'female',
                    'sobriety_start_date' => $this->iso('2026-05-01 08:00:00'),
                    'is_anonymous' => false,
                    'wants_supervision' => true,
                    'supervisor_uid' => 'supervisor_001',
                    'supervision_status' => 'accepted',
                    'supervision_accepted_at' => $this->iso('2026-05-02 12:15:00'),
                    'primary_risks' => ['ansiedad nocturna', 'craving', 'aislamiento'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'patient_002' => [
                    'uid' => 'patient_002',
                    'full_name' => 'Luis Ramirez',
                    'email' => 'luis.ramirez@rehabianex.test',
                    'age' => 35,
                    'gender' => 'male',
                    'sobriety_start_date' => $this->iso('2026-04-15 08:00:00'),
                    'is_anonymous' => false,
                    'wants_supervision' => true,
                    'supervisor_uid' => 'supervisor_001',
                    'supervision_status' => 'accepted',
                    'supervision_accepted_at' => $this->iso('2026-04-18 09:45:00'),
                    'primary_risks' => ['estres laboral', 'detonantes familiares', 'recaidas previas'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'patient_003' => [
                    'uid' => 'patient_003',
                    'full_name' => 'Usuario Anonimo 003',
                    'email' => 'anonimo003@rehabianex.test',
                    'age' => 24,
                    'gender' => 'other',
                    'sobriety_start_date' => $this->iso('2026-05-20 08:00:00'),
                    'is_anonymous' => true,
                    'wants_supervision' => false,
                    'supervisor_uid' => null,
                    'supervision_status' => 'not_requested',
                    'supervision_accepted_at' => null,
                    'primary_risks' => ['soledad', 'ansiedad social'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'patient_notes' => [
                'note_001' => [
                    'note_id' => 'note_001',
                    'patient_uid' => 'patient_001',
                    'mood' => 'ansiosa',
                    'mood_score' => 4,
                    'anxiety_level' => 8,
                    'craving_level' => 7,
                    'energy_level' => 4,
                    'sleep_quality' => 3,
                    'had_relapse' => false,
                    'triggers' => ['soledad', 'noche', 'discusion familiar'],
                    'coping_actions' => ['respiracion 4-7-8', 'mensaje a contacto de apoyo'],
                    'note_text' => 'Senti ansiedad por la noche y aparecio craving, pero pedi apoyo antes de actuar.',
                    'ai_risk_score' => 78,
                    'ai_risk_level' => 'high',
                    'created_at' => $yesterday,
                    'updated_at' => $yesterday,
                ],
                'note_002' => [
                    'note_id' => 'note_002',
                    'patient_uid' => 'patient_002',
                    'mood' => 'irritable',
                    'mood_score' => 3,
                    'anxiety_level' => 9,
                    'craving_level' => 8,
                    'energy_level' => 3,
                    'sleep_quality' => 2,
                    'had_relapse' => false,
                    'triggers' => ['problemas laborales', 'enojo', 'recuerdo de consumo'],
                    'coping_actions' => ['llamada al supervisor', 'salir a caminar'],
                    'note_text' => 'Tuve detonantes fuertes despues del trabajo. No hubo recaida, pero necesito seguimiento.',
                    'ai_risk_score' => 86,
                    'ai_risk_level' => 'critical',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'note_003' => [
                    'note_id' => 'note_003',
                    'patient_uid' => 'patient_003',
                    'mood' => 'estable',
                    'mood_score' => 7,
                    'anxiety_level' => 3,
                    'craving_level' => 2,
                    'energy_level' => 7,
                    'sleep_quality' => 8,
                    'had_relapse' => false,
                    'triggers' => [],
                    'coping_actions' => ['reunion virtual', 'diario personal'],
                    'note_text' => 'Dia tranquilo, sin craving intenso. La reunion ayudo a mantener enfoque.',
                    'ai_risk_score' => 20,
                    'ai_risk_level' => 'low',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'support_contacts' => [
                'support_001' => [
                    'contact_id' => 'support_001',
                    'patient_uid' => 'patient_001',
                    'name' => 'Marta Lopez',
                    'relationship' => 'hermana',
                    'phone' => '+524491112233',
                    'priority' => 1,
                    'can_receive_alerts' => true,
                    'notes' => 'Contacto principal cuando haya ansiedad o craving nocturno.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'support_002' => [
                    'contact_id' => 'support_002',
                    'patient_uid' => 'patient_002',
                    'name' => 'Grupo Serenidad',
                    'relationship' => 'reunion de apoyo',
                    'phone' => '+524494445566',
                    'priority' => 1,
                    'can_receive_alerts' => true,
                    'notes' => 'Disponible antes y despues de reuniones presenciales.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'agenda_events' => [
                'event_001' => [
                    'event_id' => 'event_001',
                    'patient_uid' => 'patient_001',
                    'supervisor_uid' => 'supervisor_001',
                    'type' => 'supervision_session',
                    'title' => 'Revision de ansiedad y detonantes',
                    'starts_at' => $tomorrow,
                    'ends_at' => $this->iso('2026-06-29 18:45:00'),
                    'location' => 'Videollamada',
                    'status' => 'scheduled',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'event_002' => [
                    'event_id' => 'event_002',
                    'patient_uid' => 'patient_002',
                    'supervisor_uid' => 'supervisor_001',
                    'type' => 'support_meeting',
                    'title' => 'Reunion de apoyo semanal',
                    'starts_at' => $nextWeek,
                    'ends_at' => $this->iso('2026-07-05 12:30:00'),
                    'location' => 'Centro Comunitario Norte',
                    'status' => 'scheduled',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'achievements' => [
                'achievement_001' => [
                    'achievement_id' => 'achievement_001',
                    'title' => 'Primer registro honesto',
                    'description' => 'Completar una nota diaria identificando ansiedad, craving o detonantes.',
                    'criteria' => ['patient_notes_created' => 1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'achievement_002' => [
                    'achievement_id' => 'achievement_002',
                    'title' => '7 dias de seguimiento',
                    'description' => 'Registrar avances durante siete dias sin recaidas reportadas.',
                    'criteria' => ['days_tracked' => 7, 'relapses' => 0],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'patient_achievements' => [
                'patient_achievement_001' => [
                    'patient_achievement_id' => 'patient_achievement_001',
                    'patient_uid' => 'patient_001',
                    'achievement_id' => 'achievement_001',
                    'awarded_at' => $yesterday,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'patient_achievement_002' => [
                    'patient_achievement_id' => 'patient_achievement_002',
                    'patient_uid' => 'patient_003',
                    'achievement_id' => 'achievement_002',
                    'awarded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'local_resources' => [
                'resource_001' => [
                    'resource_id' => 'resource_001',
                    'name' => 'Centro Comunitario Norte',
                    'type' => 'support_group',
                    'city' => 'Aguascalientes',
                    'address' => 'Av. Convencion 1201',
                    'phone' => '+524498887777',
                    'meeting_schedule' => ['domingo 11:00', 'miercoles 19:00'],
                    'tags' => ['reuniones', 'prevencion de recaidas', 'apoyo presencial'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'resource_002' => [
                    'resource_id' => 'resource_002',
                    'name' => 'Linea de apoyo emocional 24/7',
                    'type' => 'hotline',
                    'city' => 'Nacional',
                    'address' => null,
                    'phone' => '800-911-2000',
                    'meeting_schedule' => [],
                    'tags' => ['crisis', 'ansiedad', 'acompanamiento'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'consents' => [
                'consent_001' => [
                    'consent_id' => 'consent_001',
                    'patient_uid' => 'patient_001',
                    'supervisor_uid' => 'supervisor_001',
                    'type' => 'supervision_and_alerts',
                    'explicit_consent' => true,
                    'consent_text' => 'Autorizo que mi supervisor vea mis notas, niveles de ansiedad, craving y alertas de riesgo.',
                    'scope' => ['patient_notes', 'agenda_events', 'support_alerts', 'ai_chat_summary'],
                    'revoked_at' => null,
                    'accepted_at' => $this->iso('2026-05-02 12:10:00'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'consent_002' => [
                    'consent_id' => 'consent_002',
                    'patient_uid' => 'patient_002',
                    'supervisor_uid' => 'supervisor_001',
                    'type' => 'supervision_and_alerts',
                    'explicit_consent' => true,
                    'consent_text' => 'Autorizo supervision y contacto preventivo ante riesgo de recaida.',
                    'scope' => ['patient_notes', 'support_contacts', 'ai_risk_flags'],
                    'revoked_at' => null,
                    'accepted_at' => $this->iso('2026-04-18 09:40:00'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'notification_settings' => [
                'notification_patient_001' => [
                    'settings_id' => 'notification_patient_001',
                    'patient_uid' => 'patient_001',
                    'daily_check_in_enabled' => true,
                    'daily_check_in_time' => '20:30',
                    'craving_alerts_enabled' => true,
                    'supervisor_alerts_enabled' => true,
                    'support_contact_alerts_enabled' => true,
                    'timezone' => 'America/Mexico_City',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'notification_patient_002' => [
                    'settings_id' => 'notification_patient_002',
                    'patient_uid' => 'patient_002',
                    'daily_check_in_enabled' => true,
                    'daily_check_in_time' => '21:00',
                    'craving_alerts_enabled' => true,
                    'supervisor_alerts_enabled' => true,
                    'support_contact_alerts_enabled' => false,
                    'timezone' => 'America/Mexico_City',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'interventions' => [
                'intervention_001' => [
                    'intervention_id' => 'intervention_001',
                    'patient_uid' => 'patient_001',
                    'supervisor_uid' => 'supervisor_001',
                    'trigger_note_id' => 'note_001',
                    'reason' => 'Craving alto con ansiedad nocturna',
                    'actions' => ['llamada breve', 'plan de seguridad', 'reunion recomendada'],
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'intervention_002' => [
                    'intervention_id' => 'intervention_002',
                    'patient_uid' => 'patient_002',
                    'supervisor_uid' => 'supervisor_001',
                    'trigger_note_id' => 'note_002',
                    'reason' => 'Riesgo critico por detonantes laborales y recaidas previas',
                    'actions' => ['contacto con supervisor autorizado', 'reunion presencial', 'seguimiento en 24 horas'],
                    'status' => 'in_progress',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'supervision_requests' => [
                'request_001' => [
                    'request_id' => 'request_001',
                    'patient_uid' => 'patient_001',
                    'supervisor_uid' => 'supervisor_001',
                    'status' => 'accepted',
                    'message' => 'Solicito seguimiento para manejar ansiedad y craving por la noche.',
                    'requested_at' => $this->iso('2026-05-02 11:45:00'),
                    'responded_at' => $this->iso('2026-05-02 12:15:00'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'request_002' => [
                    'request_id' => 'request_002',
                    'patient_uid' => 'patient_003',
                    'supervisor_uid' => 'supervisor_002',
                    'status' => 'pending',
                    'message' => 'Quiero evaluar si necesito acompanamiento en reuniones.',
                    'requested_at' => $now,
                    'responded_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'ai_chat_sessions' => [
                'ai_session_001' => [
                    'session_id' => 'ai_session_001',
                    'patient_uid' => 'patient_001',
                    'supervisor_uid' => 'supervisor_001',
                    'channel' => 'supervisor_chat',
                    'api_endpoint' => 'https://rehabianex-api.onrender.com/api/ai/supervisor-chat',
                    'purpose' => 'Orientacion de apoyo para seguimiento, sin diagnostico clinico.',
                    'clinical_diagnosis_allowed' => false,
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            'ai_chat_messages' => [
                'ai_message_001' => [
                    'message_id' => 'ai_message_001',
                    'session_id' => 'ai_session_001',
                    'sender_type' => 'supervisor',
                    'content' => 'La paciente reporto ansiedad 8/10 y craving 7/10. Que seguimiento de apoyo sugieres?',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                'ai_message_002' => [
                    'message_id' => 'ai_message_002',
                    'session_id' => 'ai_session_001',
                    'sender_type' => 'ai',
                    'content' => 'Puedo sugerir apoyo no diagnostico: validar emociones, revisar detonantes, confirmar red de apoyo y acordar una accion segura para las proximas horas. Ante riesgo inmediato, contactar servicios de emergencia o profesionales responsables.',
                    'safety_notice' => 'No es diagnostico clinico ni reemplaza atencion profesional.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
        ];
    }

    private function iso(string $dateTime): string
    {
        return CarbonImmutable::parse($dateTime, 'America/Mexico_City')->toIso8601String();
    }
}
