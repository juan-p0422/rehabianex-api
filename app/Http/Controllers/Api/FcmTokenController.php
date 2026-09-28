<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserFcmToken;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class FcmTokenController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'min:50', 'max:4096'],
            'platform' => ['required', 'in:android'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'max:20'],
            'topic' => ['prohibited'],
            'user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'is_admin' => ['prohibited'],
            'is_active' => ['prohibited'],
            'payload' => ['prohibited'],
            'title' => ['prohibited'],
            'body' => ['prohibited'],
        ]);

        $token = UserFcmToken::firstOrNew(['fcm_token' => $data['fcm_token']]);
        $token->user_id = $this->authenticatedUid($request);
        $token->fill(Arr::except($data, ['fcm_token']));
        $token->fcm_token = $data['fcm_token'];
        $token->is_active = true;
        $token->last_seen_at = now();
        $token->revoked_at = null;
        $token->save();

        return response()->json([
            'ok' => true,
            'message' => 'Token FCM registrado correctamente.',
            'data' => $token->fresh(),
        ]);
    }

    public function revoke(Request $request)
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'min:50', 'max:4096'],
            'topic' => ['prohibited'],
            'user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'is_admin' => ['prohibited'],
            'payload' => ['prohibited'],
        ]);

        UserFcmToken::query()
            ->where('user_id', $this->authenticatedUid($request))
            ->where('fcm_token', $data['fcm_token'])
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->noContent();
    }

    public function test(Request $request, FcmService $fcm)
    {
        if ($request->all() !== []) {
            abort(422, 'El endpoint de prueba no acepta payload.');
        }

        if (! config('fcm.enabled')) {
            abort(422, 'FCM está deshabilitado en la configuración actual.');
        }

        if (! config('fcm.dry_run', true) && ! app()->environment(['local', 'staging'])) {
            abort(403, 'El envío real de prueba solo está permitido en local o staging.');
        }

        $result = $fcm->sendSafeTestToUser($this->authenticatedUid($request));

        if ($result['status'] === 'no_active_tokens') {
            abort(404, 'No existe un token FCM activo para la cuenta autenticada.');
        }

        $sent = $result['sent'] > 0;
        $message = match (true) {
            $sent && config('fcm.dry_run', true) => 'Mensaje FCM validado en modo dry-run.',
            $sent => 'Mensaje de prueba enviado a los dispositivos del usuario autenticado.',
            $result['invalid'] > 0 => 'No se envió: los tokens inválidos fueron desactivados.',
            default => 'No se pudo completar el envío de prueba.',
        };

        return response()->json([
            'ok' => $result['failed'] === 0,
            'sent' => $sent,
            'message' => $message,
            'mode' => config('fcm.dry_run', true) ? 'validation_only' : 'real_delivery',
            'processed_tokens' => $result['sent'] + $result['invalid'] + $result['failed'],
            'successful_tokens' => $result['sent'],
            'invalid_tokens' => $result['invalid'],
            'failed_tokens' => $result['failed'],
            'notification_id' => $result['notification_id'],
        ]);
    }

    private function authenticatedUid(Request $request): string
    {
        $uid = trim((string) $request->attributes->get('firebase_uid'));

        if ($uid === '') {
            abort(401, 'Autenticación Firebase requerida.');
        }

        return $uid;
    }
}
