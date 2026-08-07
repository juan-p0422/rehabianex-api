<?php

namespace App\Http\Responses;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrorResponse
{
    public static function make(
        string $message,
        int $status,
        array $errors = [],
        array $headers = []
    ): JsonResponse {
        return response()->json([
            'ok' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status, $headers);
    }

    public static function fromException(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return self::make(
                'Los datos enviados no son validos.',
                422,
                $exception->errors()
            );
        }

        if ($exception instanceof AuthenticationException) {
            return self::make('Autenticacion requerida.', 401);
        }

        if ($exception instanceof AuthorizationException) {
            return self::make(
                $exception->getMessage() ?: 'No tienes permiso para realizar esta accion.',
                403
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $message = trim($exception->getMessage());

            if ($status >= 500 || in_array($status, [400, 429], true)) {
                $message = self::defaultMessage($status);
            }

            if ($status === 404
                && str_starts_with(strtolower($message), 'the route ')) {
                $message = self::defaultMessage(404);
            }

            return self::make(
                $message !== '' ? $message : self::defaultMessage($status),
                $status,
                [],
                $exception->getHeaders()
            );
        }

        return self::make(self::defaultMessage(500), 500);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'La solicitud no es valida.',
            401 => 'Autenticacion requerida.',
            403 => 'No tienes permiso para realizar esta accion.',
            404 => 'El recurso solicitado no fue encontrado.',
            409 => 'La operacion entra en conflicto con el estado actual.',
            422 => 'Los datos enviados no son validos.',
            429 => 'Demasiadas solicitudes. Intenta nuevamente mas tarde.',
            502 => 'Un proveedor externo no pudo procesar la solicitud.',
            default => 'Ocurrio un error interno. Intenta nuevamente mas tarde.',
        };
    }
}
