<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiErrorResponse;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    private $db;

    public function __construct(
        FirebaseService $firebase,
        private FirestoreAccessService $access
    ) {
        $this->db = $firebase->db();
    }

    public function supervisedPatients(Request $request, string $supervisorUid)
    {
        if ($this->access->role($request) !== 'supervisor'
            || $this->access->uid($request) !== $supervisorUid) {
            abort(403, 'Solo puedes consultar los pacientes de tu propia sesion.');
        }

        $this->access->assertAuthorizedSupervisor($supervisorUid);

        $documents = $this->db
            ->collection('patients')
            ->where('wants_supervision', '=', true)
            ->where('supervisor_uid', '=', $supervisorUid)
            ->documents();

        $patients = [];

        foreach ($documents as $document) {
            if (! $document->exists()) {
                continue;
            }

            $patient = $document->data();
            $patientUid = (string) ($patient['uid'] ?? $document->id());

            if ($this->access->supervisorCanAccessPatient($supervisorUid, $patientUid)) {
                $patients[] = $this->access->sanitizeForSupervisor(
                    'patients',
                    $patient,
                    $supervisorUid
                );
            }
        }

        return response()->json([
            'ok' => true,
            'supervisor_uid' => $supervisorUid,
            'count' => count($patients),
            'patients' => $patients,
            'data' => $patients,
        ]);
    }

    public function notes(Request $request, string $patientUid)
    {
        $patient = $this->db->collection('patients')->document($patientUid)->snapshot();

        if (! $patient->exists()) {
            abort(404, 'Paciente no encontrado.');
        }

        $this->access->assertCanRead('patients', $patient->data(), $request);

        if ($this->access->role($request) === 'supervisor') {
            $this->access->assertSupervisorPatientAccess(
                $this->access->uid($request),
                $patientUid,
                ['patient_notes']
            );
        }

        $documents = $this->db
            ->collection('patient_notes')
            ->where('patient_uid', '=', $patientUid)
            ->documents();

        $notes = [];

        foreach ($documents as $document) {
            if ($document->exists()) {
                $note = $document->data();

                if (! empty($note['deleted_at'])) {
                    continue;
                }

                if ($this->access->role($request) === 'supervisor') {
                    $note = $this->access->sanitizeForSupervisor(
                        'patient-notes',
                        $note,
                        $this->access->uid($request)
                    );
                }

                $notes[] = $note;
            }
        }

        usort($notes, static fn (array $a, array $b) => strcmp(
            (string) ($b['created_at'] ?? ''),
            (string) ($a['created_at'] ?? '')
        ));

        return response()->json($this->notesResponse($patientUid, $notes));
    }

    public function storeNote(Request $request, string $patientUid)
    {
        if ($this->access->role($request) !== 'patient'
            || $this->access->uid($request) !== $patientUid) {
            abort(403, 'Solo puedes crear notas para tu propia cuenta.');
        }

        $payload = $request->all();
        $headerMutationId = trim((string) $request->header('Idempotency-Key', ''));
        $bodyMutationId = trim((string) ($payload['client_mutation_id'] ?? ''));

        if ($headerMutationId !== '' && $bodyMutationId !== ''
            && ! hash_equals($bodyMutationId, $headerMutationId)) {
            return ApiErrorResponse::make(
                'Los datos enviados no son validos.',
                422,
                ['client_mutation_id' => [
                    'client_mutation_id debe coincidir con el header Idempotency-Key.',
                ]]
            );
        }

        if ($bodyMutationId === '' && $headerMutationId !== '') {
            $payload['client_mutation_id'] = $headerMutationId;
        }

        $validator = Validator::make($payload, [
            'client_mutation_id' => [
                'nullable',
                'string',
                'min:8',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'mood' => ['required', 'string', 'max:80'],
            'mood_score' => ['required', 'integer', 'between:0,10'],
            'anxiety_level' => ['required', 'integer', 'between:0,10'],
            'craving_level' => ['required', 'integer', 'between:0,10'],
            'energy_level' => ['nullable', 'integer', 'between:0,10'],
            'sleep_quality' => ['nullable', 'integer', 'between:0,10'],
            'had_relapse' => ['required', 'boolean'],
            'triggers' => ['nullable', 'array', 'max:20'],
            'coping_actions' => ['nullable', 'array', 'max:20'],
            'note_text' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return ApiErrorResponse::make(
                'Los datos enviados no son validos.',
                422,
                $validator->errors()->toArray()
            );
        }

        $data = $validator->validated();
        $clientMutationId = isset($data['client_mutation_id'])
            ? trim((string) $data['client_mutation_id'])
            : null;
        $now = Carbon::now()->toIso8601String();
        $noteId = $clientMutationId !== null
            ? $this->idempotentNoteId($patientUid, $clientMutationId)
            : 'note_'.Str::uuid()->toString();
        $document = $this->db->collection('patient_notes')->document($noteId);

        if ($clientMutationId !== null) {
            $existing = $document->snapshot();

            if ($existing->exists()) {
                $existingNote = $existing->data();

                if (($existingNote['patient_uid'] ?? null) !== $patientUid
                    || ($existingNote['client_mutation_id'] ?? null) !== $clientMutationId) {
                    abort(409, 'La clave de idempotencia entra en conflicto con una nota existente.');
                }

                return response()->json($this->noteCreationResponse(
                    $existingNote,
                    true
                ));
            }
        }

        $riskScore = $this->calculateRiskScore(
            (int) $data['anxiety_level'],
            (int) $data['craving_level'],
            (int) $data['mood_score'],
            (bool) $data['had_relapse']
        );

        $note = [
            'note_id' => $noteId,
            'patient_uid' => $patientUid,
            'mood' => $data['mood'],
            'mood_score' => (int) $data['mood_score'],
            'anxiety_level' => (int) $data['anxiety_level'],
            'craving_level' => (int) $data['craving_level'],
            'energy_level' => (int) ($data['energy_level'] ?? 5),
            'sleep_quality' => (int) ($data['sleep_quality'] ?? 5),
            'had_relapse' => (bool) $data['had_relapse'],
            'triggers' => $data['triggers'] ?? [],
            'coping_actions' => $data['coping_actions'] ?? [],
            'note_text' => $data['note_text'] ?? '',
            'ai_risk_score' => $riskScore,
            'ai_risk_level' => $this->calculateRiskLevel($riskScore),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($clientMutationId !== null) {
            $note['client_mutation_id'] = $clientMutationId;
        }

        $document->set($note);

        return response()->json($this->noteCreationResponse($note, false), 201);
    }

    private function idempotentNoteId(string $patientUid, string $clientMutationId): string
    {
        return 'note_mut_'.hash('sha256', $patientUid."\0".$clientMutationId);
    }

    private function noteCreationResponse(array $note, bool $replayed): array
    {
        return [
            'ok' => true,
            'message' => $replayed
                ? 'Nota recuperada de una operación ya procesada.'
                : 'Nota creada correctamente.',
            'idempotent_replay' => $replayed,
            'note' => $note,
            'data' => $note,
        ];
    }

    private function calculateRiskScore(int $anxiety, int $craving, int $moodScore, bool $hadRelapse): int
    {
        return min(
            ($anxiety * 4) + ($craving * 5) + ((10 - $moodScore) * 3) + ($hadRelapse ? 30 : 0),
            100
        );
    }

    private function calculateRiskLevel(int $riskScore): string
    {
        return match (true) {
            $riskScore >= 85 => 'critical',
            $riskScore >= 65 => 'high',
            $riskScore >= 40 => 'medium',
            default => 'low',
        };
    }

    private function notesResponse(string $patientUid, array $notes): array
    {
        return [
            'ok' => true,
            'patient_uid' => $patientUid,
            'count' => count($notes),
            'notes' => $notes,
            'data' => $notes,
        ];
    }
}
