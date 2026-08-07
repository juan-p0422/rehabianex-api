<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('firebase_role') !== 'admin') {
            return ApiErrorResponse::make(
                'Acceso restringido a administradores.',
                403
            );
        }

        $profile = $request->attributes->get('firebase_profile');
        $status = is_array($profile) ? ($profile['status'] ?? null) : null;

        if ($status !== 'active') {
            return ApiErrorResponse::make(
                'La cuenta administradora no está activa.',
                403
            );
        }

        return $next($request);
    }
}
