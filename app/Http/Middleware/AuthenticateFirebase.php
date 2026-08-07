<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use App\Services\FirebaseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateFirebase
{
    public function __construct(private FirebaseService $firebase) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return ApiErrorResponse::make(
                'Autenticacion requerida. Envia Authorization: Bearer {id_token}.',
                401
            );
        }

        try {
            $verifiedToken = $this->firebase->auth()->verifyIdToken($token, false, 60);
            $uid = $verifiedToken->claims()->get('sub');
            $user = $this->firebase->auth()->getUser($uid);
            $role = $user->customClaims['role'] ?? null;

            $authenticatedAt = $verifiedToken->claims()->get('auth_time');
            $authenticatedAtTimestamp = $authenticatedAt instanceof \DateTimeInterface
                ? $authenticatedAt->getTimestamp()
                : (int) $authenticatedAt;
            $validAfterTimestamp = $user->tokensValidAfterTime?->getTimestamp();

            if ($validAfterTimestamp !== null && $authenticatedAtTimestamp < $validAfterTimestamp) {
                return ApiErrorResponse::make(
                    'La sesion fue revocada. Inicia sesion nuevamente.',
                    401
                );
            }

            $tokenHash = hash('sha256', $token);
            $revokedToken = $this->firebase->db()
                ->collection('revoked_tokens')
                ->document($tokenHash)
                ->snapshot();

            if ($revokedToken->exists()) {
                return ApiErrorResponse::make(
                    'La sesion fue revocada. Inicia sesion nuevamente.',
                    401
                );
            }

            if ($user->disabled) {
                return ApiErrorResponse::make('La cuenta esta deshabilitada.', 401);
            }

            $profileCollections = config('firestore.profile_collections', []);

            if (! isset($profileCollections[$role])) {
                return ApiErrorResponse::make('La cuenta no tiene un rol valido.', 403);
            }

            $collection = $profileCollections[$role];
            $profile = $this->firebase->db()
                ->collection($collection)
                ->document($uid)
                ->snapshot();

            if (! $profile->exists()) {
                return ApiErrorResponse::make('La cuenta no tiene un perfil activo.', 403);
            }

            $request->attributes->set('firebase_uid', $uid);
            $request->attributes->set('firebase_role', $role);
            $request->attributes->set('firebase_user', $user);
            $request->attributes->set('firebase_profile', $profile->data());
            $request->attributes->set('firebase_token_hash', $tokenHash);
            $request->attributes->set('firebase_token_expires_at', $verifiedToken->claims()->get('exp'));

            return $next($request);
        } catch (Throwable $e) {
            Log::warning('Firebase ID token rejected.', [
                'exception' => get_class($e),
            ]);

            return ApiErrorResponse::make('Token invalido, expirado o revocado.', 401);
        }
    }
}
