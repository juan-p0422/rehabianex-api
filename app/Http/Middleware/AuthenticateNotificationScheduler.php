<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNotificationScheduler
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('fcm.scheduler.secret', '');

        if (strlen($secret) < 32) {
            return $this->error('Scheduler no configurado.', 503);
        }

        $timestamp = (string) $request->header('X-Rehabianex-Scheduler-Timestamp', '');
        $signature = strtolower((string) $request->header('X-Rehabianex-Scheduler-Signature', ''));
        $maxClockSkew = max(30, (int) config('fcm.scheduler.max_clock_skew_seconds', 300));

        if (preg_match('/^\d{10}$/', $timestamp) !== 1
            || abs(now()->timestamp - (int) $timestamp) > $maxClockSkew
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return $this->error('Solicitud no autorizada.', 401);
        }

        $canonical = implode("\n", [
            $timestamp,
            strtoupper($request->method()),
            $request->getPathInfo(),
        ]);
        $expected = hash_hmac('sha256', $canonical, $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->error('Solicitud no autorizada.', 401);
        }

        return $next($request);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
        ], $status);
    }
}
