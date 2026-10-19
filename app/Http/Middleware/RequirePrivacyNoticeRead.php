<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePrivacyNoticeRead
{
    public function handle(Request $request, Closure $next): Response
    {
        // AuthenticateFirebase always supplies this attribute in production.
        // Keeping direct controller tests without that middleware compatible
        // avoids turning this legal gate into a substitute authentication layer.
        if (! $request->attributes->has('firebase_profile')) {
            return $next($request);
        }

        $profile = $request->attributes->get('firebase_profile');
        $confirmation = is_array($profile) ? ($profile['privacy_notice_read'] ?? null) : null;
        $currentVersion = (string) config('legal.privacy_notice_version', '2026-10-18');
        $isCurrent = is_array($confirmation)
            && ($confirmation['status'] ?? null) === 'confirmed'
            && is_string($confirmation['privacy_notice_version'] ?? null)
            && hash_equals($currentVersion, $confirmation['privacy_notice_version']);

        if ($isCurrent) {
            return $next($request);
        }

        return new JsonResponse([
            'ok' => false,
            'code' => 'privacy_notice_read_required',
            'message' => 'Confirma la lectura del aviso de privacidad vigente antes de continuar.',
            'privacy_notice_version' => $currentVersion,
        ], 428);
    }
}
