<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FcmService;
use App\Support\FcmDemoCatalog;
use App\Support\FcmNotificationTypes;
use App\Support\FcmSafeTexts;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FcmDemoController extends Controller
{
    public function __invoke(Request $request, FcmService $fcm)
    {
        if (array_diff(array_keys($request->all()), ['type']) !== []) {
            abort(422, 'La demostración solo acepta el campo type.');
        }

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', FcmNotificationTypes::ALL)],
        ]);

        if (! config('fcm.enabled')) {
            abort(422, 'FCM está deshabilitado en la configuración actual.');
        }

        if (! config('fcm.demo.enabled')) {
            abort(403, 'La demostración FCM está deshabilitada.');
        }

        if (! config('fcm.dry_run', true)
            && app()->environment('production')
            && ! config('fcm.production_send_enabled')) {
            abort(403, 'El envío FCM real en producción no está habilitado explícitamente.');
        }

        $uid = trim((string) $request->attributes->get('firebase_uid'));
        $role = trim((string) $request->attributes->get('firebase_role'));

        if ($uid === '') {
            abort(401, 'Autenticación Firebase requerida.');
        }

        if (! FcmDemoCatalog::isAllowedForRole($data['type'], $role)) {
            abort(403, 'El tipo solicitado no corresponde al rol autenticado.');
        }

        $entityId = 'demo_'.Str::uuid()->toString();
        $notificationId = 'demo_'.Str::uuid()->toString();
        $route = FcmDemoCatalog::routeFor($data['type'], $entityId);
        $createdAt = now()->toIso8601String();

        $result = $fcm->sendToUser(
            $uid,
            $data['type'],
            $route,
            $entityId,
            $notificationId,
            $createdAt,
            'normal',
        );

        if ($result['status'] === 'no_active_tokens') {
            abort(404, 'No existe un token FCM activo para la cuenta autenticada.');
        }

        return response()->json([
            'ok' => $result['failed'] === 0,
            'sent' => $result['sent'] > 0,
            'type' => $data['type'],
            'notification_id' => $notificationId,
            'mode' => config('fcm.dry_run', true) ? 'validation_only' : 'real_delivery',
            'message' => config('fcm.dry_run', true)
                ? 'Demostración FCM validada en modo dry-run.'
                : 'Demostración FCM enviada al usuario autenticado.',
            'notification' => [
                'type' => $data['type'],
                'title' => FcmSafeTexts::TITLE,
                'body' => FcmSafeTexts::bodyFor($data['type']),
                'route' => $route,
                'entity_id' => $entityId,
                'notification_id' => $notificationId,
                'created_at' => $createdAt,
                'priority' => 'normal',
            ],
            'delivery' => [
                'processed_tokens' => $result['sent'] + $result['invalid'] + $result['failed'],
                'successful_tokens' => $result['sent'],
                'invalid_tokens' => $result['invalid'],
                'failed_tokens' => $result['failed'],
            ],
        ]);
    }
}
