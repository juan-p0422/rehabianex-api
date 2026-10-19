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
                'responsible_status' => 'pending_confirmation',
                'contact_status' => 'pending_confirmation',
                'sensitive_data_consent_separate' => true,
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

            $this->firebase->db()
                ->collection($collection)
                ->document($uid)
                ->set(['privacy_notice_read' => $confirmation], ['merge' => true]);

            return response()->json([
                'ok' => true,
                'message' => 'Lectura del aviso confirmada.',
                'confirmation' => [
                    'privacy_notice_version' => $this->version(),
                    'confirmed_at' => $confirmedAt,
                    'status' => 'confirmed',
                ],
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
        return (string) config('legal.privacy_notice_version', '2026-10-18');
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
