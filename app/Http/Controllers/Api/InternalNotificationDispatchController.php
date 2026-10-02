<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DueNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalNotificationDispatchController extends Controller
{
    public function __invoke(
        Request $request,
        DueNotificationDispatcher $dueNotifications,
    ): JsonResponse {
        if (trim($request->getContent()) !== '') {
            return response()->json([
                'ok' => false,
                'message' => 'Este endpoint no acepta payload.',
            ], 422);
        }

        $processed = $dueNotifications->dispatch();

        return response()->json([
            'ok' => true,
            'processed' => [
                'agenda' => $processed['agenda'],
                'check_in' => $processed['check_in'],
                'total' => $processed['agenda'] + $processed['check_in'],
            ],
        ]);
    }
}
