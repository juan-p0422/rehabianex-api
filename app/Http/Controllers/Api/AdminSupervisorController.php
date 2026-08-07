<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminSupervisorController extends Controller
{
    private const LIST_FIELDS = [
        'uid',
        'full_name',
        'email',
        'phone',
        'supervisor_type',
        'supervisor_code',
        'authorized',
        'verified',
        'status',
        'created_at',
    ];

    private const DETAIL_FIELDS = [
        'uid',
        'full_name',
        'email',
        'phone',
        'supervisor_type',
        'supervisor_code',
        'authorized',
        'verified',
        'status',
        'created_at',
        'updated_at',
        'authorized_at',
        'authorized_by',
        'authorization_notes',
        'rejection_reason',
        'suspension_reason',
        'suspended_at',
        'suspended_by',
        'reactivated_at',
        'reactivated_by',
    ];

    private $db;

    public function __construct(FirebaseService $firebase)
    {
        $this->db = $firebase->db();
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'status' => ['nullable', Rule::in([
                'pending_review',
                'active',
                'rejected',
                'suspended',
                'disabled',
            ])],
            'authorized' => ['nullable', 'boolean'],
            'verified' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $filters = $validator->validated();
        $limit = (int) ($filters['limit'] ?? 50);
        $data = [];

        foreach ($this->db->collection('supervisors')->documents() as $snapshot) {
            if (! $snapshot->exists()) {
                continue;
            }

            $supervisor = $snapshot->data();
            $supervisor['uid'] = $supervisor['uid'] ?? $snapshot->id();
            $supervisor = $this->normalizeSupervisor($supervisor);

            if (! $this->matchesFilters($supervisor, $filters)) {
                continue;
            }

            $data[] = $this->only($supervisor, self::LIST_FIELDS);
        }

        usort(
            $data,
            fn (array $left, array $right): int => strcmp(
                (string) ($right['created_at'] ?? ''),
                (string) ($left['created_at'] ?? '')
            )
        );
        $data = array_slice($data, 0, $limit);

        return response()->json([
            'ok' => true,
            'count' => count($data),
            'data' => $data,
        ]);
    }

    public function show(string $uid)
    {
        $supervisor = $this->findSupervisor($uid);

        return response()->json([
            'ok' => true,
            'data' => $this->only(
                $this->normalizeSupervisor($supervisor),
                self::DETAIL_FIELDS
            ),
        ]);
    }

    public function authorizeSupervisor(Request $request, string $uid)
    {
        $validator = Validator::make($request->all(), [
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $supervisor = $this->findSupervisor($uid);
        $this->assertStatus($supervisor, ['pending', 'pending_review', 'rejected']);
        $now = Carbon::now()->toIso8601String();
        $changes = [
            'authorized' => true,
            'verified' => true,
            'status' => 'active',
            'authorized_at' => $now,
            'authorized_by' => $this->adminUid($request),
            'supervisor_code' => ($supervisor['supervisor_code'] ?? null)
                ?: 'RA-'.strtoupper(substr(sha1($uid), 0, 8)),
            'rejection_reason' => null,
            'updated_at' => $now,
        ];

        if ($request->filled('notes')) {
            $changes['authorization_notes'] = $validator->validated()['notes'];
        }

        return $this->saveTransition($uid, $supervisor, $changes, 'Supervisor autorizado correctamente.');
    }

    public function reject(Request $request, string $uid)
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $supervisor = $this->findSupervisor($uid);
        $this->assertStatus($supervisor, ['pending', 'pending_review']);
        $now = Carbon::now()->toIso8601String();

        return $this->saveTransition($uid, $supervisor, [
            'authorized' => false,
            'verified' => false,
            'status' => 'rejected',
            'rejection_reason' => $validator->validated()['reason'],
            'rejected_at' => $now,
            'rejected_by' => $this->adminUid($request),
            'updated_at' => $now,
        ], 'Supervisor rechazado correctamente.');
    }

    public function suspend(Request $request, string $uid)
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $supervisor = $this->findSupervisor($uid);
        $this->assertStatus($supervisor, ['active']);
        $now = Carbon::now()->toIso8601String();

        return $this->saveTransition($uid, $supervisor, [
            'authorized' => false,
            'status' => 'suspended',
            'suspension_reason' => $validator->validated()['reason'],
            'suspended_at' => $now,
            'suspended_by' => $this->adminUid($request),
            'updated_at' => $now,
        ], 'Supervisor suspendido correctamente.');
    }

    public function reactivate(Request $request, string $uid)
    {
        $supervisor = $this->findSupervisor($uid);
        $this->assertStatus($supervisor, ['suspended']);
        $now = Carbon::now()->toIso8601String();

        return $this->saveTransition($uid, $supervisor, [
            'authorized' => true,
            'verified' => true,
            'status' => 'active',
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
            'reactivated_at' => $now,
            'reactivated_by' => $this->adminUid($request),
            'updated_at' => $now,
        ], 'Supervisor reactivado correctamente.');
    }

    private function findSupervisor(string $uid): array
    {
        $snapshot = $this->db->collection('supervisors')->document($uid)->snapshot();

        if (! $snapshot->exists()) {
            abort(404, 'Supervisor no encontrado.');
        }

        return array_replace($snapshot->data(), ['uid' => $uid]);
    }

    private function saveTransition(
        string $uid,
        array $supervisor,
        array $changes,
        string $message
    ) {
        $updated = array_replace($supervisor, $changes, ['uid' => $uid]);
        $this->db->collection('supervisors')->document($uid)->set($updated);

        return response()->json([
            'ok' => true,
            'message' => $message,
            'data' => $this->only(
                $this->normalizeSupervisor($updated),
                self::DETAIL_FIELDS
            ),
        ]);
    }

    private function assertStatus(array $supervisor, array $allowed): void
    {
        $status = (string) ($supervisor['status'] ?? 'pending');

        if (! in_array($status, $allowed, true)) {
            abort(409, 'El estado actual del supervisor no permite esta operación.');
        }
    }

    private function adminUid(Request $request): string
    {
        return (string) $request->attributes->get('firebase_uid');
    }

    private function normalizeSupervisor(array $supervisor): array
    {
        $supervisor['status'] = ($supervisor['status'] ?? 'pending') === 'pending'
            ? 'pending_review'
            : $supervisor['status'];
        $supervisor['authorized'] = (bool) ($supervisor['authorized'] ?? false);
        $supervisor['verified'] = (bool) ($supervisor['verified'] ?? false);

        return $supervisor;
    }

    private function matchesFilters(array $supervisor, array $filters): bool
    {
        if (isset($filters['status']) && $supervisor['status'] !== $filters['status']) {
            return false;
        }

        foreach (['authorized', 'verified'] as $field) {
            if (array_key_exists($field, $filters)
                && $supervisor[$field] !== filter_var($filters[$field], FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
        }

        return true;
    }

    private function only(array $supervisor, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $result[$field] = $supervisor[$field] ?? null;
        }

        return $result;
    }

    private function validationError(array $errors)
    {
        return ApiErrorResponse::make(
            'Los datos enviados no son válidos.',
            422,
            $errors
        );
    }
}
