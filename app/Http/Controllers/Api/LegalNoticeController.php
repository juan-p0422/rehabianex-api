<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class LegalNoticeController extends Controller
{
    public function __construct(private FirebaseService $firebase) {}

    public function show(): JsonResponse
    {
        $path = (string) config('legal.privacy_notice_file');
        $content = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($content) || trim($content) === '') {
            return response()->json([
                'ok' => false,
                'message' => 'El aviso de privacidad no está disponible temporalmente.',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'notice' => [
                'title' => 'Aviso de Privacidad de RehabiAnex',
                'version' => $this->version(),
                'published_on' => (string) config('legal.privacy_notice_published_on'),
                'content' => trim($content),
                'responsible_status' => 'identified',
                'contact_status' => 'phone_and_email_confirmed',
                'permanent_deletion_contact_status' => 'email_confirmed',
                'pending_legal_fields' => [],
                'closed_beta_minimum_age' => (int) config('legal.closed_beta_minimum_age', 18),
                'closed_beta_age_policy_status' => (string) config(
                    'legal.closed_beta_age_policy_status',
                    'confirmed_adults_only'
                ),
                'sensitive_data_consent_separate' => true,
                'sensitive_data_consent_version' => (string) config('legal.sensitive_data_consent_version'),
            ],
        ]);
    }

    public function confirmRead(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $validator = Validator::make($data, [
            'version' => ['required', 'string', Rule::in([$this->version()])],
            'confirmed_read' => ['required', 'accepted'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => 'La confirmación no corresponde al aviso vigente.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        try {
            $uid = trim((string) $request->attributes->get('firebase_uid'));
            $role = trim((string) $request->attributes->get('firebase_role'));
            $collection = $this->profileCollection($role);
            if ($uid === '' || $collection === null) {
                return response()->json(['ok' => false, 'message' => 'Sesión sin perfil válido.'], 403);
            }

            $confirmedAt = Carbon::now()->toIso8601String();
            $confirmation = [
                'uid' => $uid,
                'privacy_notice_version' => $this->version(),
                'confirmed_at' => $confirmedAt,
                'source' => 'android',
                'status' => 'confirmed',
            ];

            $profile = (array) $request->attributes->get('firebase_profile', []);
            $previous = $profile['privacy_notice_read'] ?? null;
            $updates = ['privacy_notice_read' => $confirmation];
            $previousPreserved = is_array($previous)
                && ($previous['status'] ?? null) === 'confirmed'
                && is_string($previous['privacy_notice_version'] ?? null)
                && $previous['privacy_notice_version'] !== $this->version();

            if ($previousPreserved) {
                $updates['privacy_notice_read_previous'] = $previous;
            }

            $this->firebase->db()
                ->collection($collection)
                ->document($uid)
                ->set($updates, ['merge' => true]);

            return response()->json([
                'ok' => true,
                'message' => 'Lectura del aviso confirmada.',
                'confirmation' => [
                    'privacy_notice_version' => $this->version(),
                    'confirmed_at' => $confirmedAt,
                    'status' => 'confirmed',
                ],
                'previous_confirmation_preserved' => $previousPreserved,
            ]);
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'No fue posible guardar la confirmación. Intenta nuevamente.',
            ], 503);
        }
    }

    private function version(): string
    {
        return (string) config('legal.privacy_notice_version', '2026-11-03');
    }

    private function profileCollection(string $role): ?string
    {
        return match ($role) {
            'patient' => 'patients',
            'supervisor' => 'supervisors',
            'admin' => 'admins',
            default => null,
        };
    }
}
